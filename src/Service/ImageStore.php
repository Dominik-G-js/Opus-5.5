<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\Database;
use App\Support\Clock;
use GdImage;
use RuntimeException;

/**
 * Úložiště obrázků mimo webroot. Originály jsou soukromé (obsahují i metadata s promptem, např. z ComfyUI).
 * Pro veřejnou landing page se vytváří zmenšená JPEG kopie přes GD — tím se odstraní všechna metadata
 * (prompty, LoRA, seed), aby je nikdo nemohl zkopírovat.
 */
final class ImageStore
{
    public const MAX_BYTES = 15 * 1024 * 1024;
    private const MAX_PIXELS_STORE = 60_000_000;
    private const MAX_PIXELS_PUBLIC = 25_000_000;
    private const PUBLIC_MAX_SIDE = 1600;
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly string $privateDir,
        private readonly string $publicDir,
    ) {
    }

    /**
     * @param array<string, mixed> $file položka z $_FILES
     * @param array{prompt_id?: int|null, tool_id?: int|null, alt_text?: string|null, seed?: string|null, notes?: string|null, is_reference?: bool} $meta
     */
    public function storeUpload(int $modelId, array $file, array $meta = []): int
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Soubor je příliš velký (limit serveru).',
                UPLOAD_ERR_NO_FILE => 'Nebyl vybrán žádný soubor.',
                UPLOAD_ERR_PARTIAL => 'Soubor se nahrál jen částečně, zkus to znovu.',
                default => 'Nahrání selhalo (kód ' . $error . ').',
            });
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Neplatný nahraný soubor.');
        }

        return $this->storeFile($modelId, $tmp, (string) ($file['name'] ?? 'image'), $meta, true);
    }

    /**
     * Uložení souboru z disku (využívají testy a importy). $move=true přesune nahraný soubor.
     *
     * @param array{prompt_id?: int|null, tool_id?: int|null, alt_text?: string|null, seed?: string|null, notes?: string|null, is_reference?: bool} $meta
     */
    public function storeFile(int $modelId, string $path, string $originalName, array $meta = [], bool $move = false): int
    {
        $size = (int) filesize($path);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('Obrázek musí mít 1 B – ' . (self::MAX_BYTES / 1024 / 1024) . ' MB.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!isset(self::ALLOWED[$mime])) {
            throw new RuntimeException('Povolené formáty jsou JPG, PNG a WebP.');
        }
        $info = @getimagesize($path);
        if ($info === false || $info[0] < 16 || $info[1] < 16 || $info[0] * $info[1] > self::MAX_PIXELS_STORE) {
            throw new RuntimeException('Soubor není platný obrázek nebo má nepřiměřené rozměry.');
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . self::ALLOWED[$mime];
        $this->ensureDir($this->privateDir);
        $target = $this->privateDir . '/' . $storedName;
        $ok = $move ? move_uploaded_file($path, $target) : copy($path, $target);
        if (!$ok) {
            throw new RuntimeException('Obrázek se nepodařilo uložit na disk (práva k storage/?).');
        }
        @chmod($target, 0640);

        return $this->db->insert('images', [
            'model_id' => $modelId,
            'prompt_id' => $meta['prompt_id'] ?? null,
            'tool_id' => $meta['tool_id'] ?? null,
            'stored_name' => $storedName,
            'original_name' => mb_substr(basename($originalName), 0, 200),
            'mime' => $mime,
            'width' => $info[0],
            'height' => $info[1],
            'size_bytes' => $size,
            'sha256' => hash_file('sha256', $target),
            'is_reference' => !empty($meta['is_reference']) ? 1 : 0,
            'alt_text' => $meta['alt_text'] ?? null,
            'seed' => $meta['seed'] ?? null,
            'notes' => $meta['notes'] ?? null,
            'created_at' => Clock::nowUtc(),
        ]);
    }

    public function privatePath(array $image): string
    {
        $name = (string) $image['stored_name'];
        if (preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $name) !== 1) {
            throw new RuntimeException('Neplatný název souboru obrázku.');
        }

        return $this->privateDir . '/' . $name;
    }

    public function publicPath(string $publicId): string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $publicId) !== 1) {
            throw new RuntimeException('Neplatné veřejné ID.');
        }

        return $this->publicDir . '/' . $publicId . '.jpg';
    }

    /** Zveřejní obrázek: vytvoří očištěnou kopii bez metadat. */
    public function makePublic(array $image): string
    {
        if ((int) $image['width'] * (int) $image['height'] > self::MAX_PIXELS_PUBLIC) {
            throw new RuntimeException('Obrázek je pro zveřejnění příliš velký (max. 25 Mpx). Nahraj menší verzi.');
        }
        $publicId = is_string($image['public_id'] ?? null) && $image['public_id'] !== ''
            ? (string) $image['public_id']
            : bin2hex(random_bytes(16));

        $width = (int) $image['width'];
        $height = (int) $image['height'];
        $scale = min(1.0, self::PUBLIC_MAX_SIDE / max($width, $height));
        $targetW = max(1, (int) round($width * $scale));
        $targetH = max(1, (int) round($height * $scale));
        self::ensureMemoryForDecoding($width * $height + $targetW * $targetH);

        $source = $this->load($this->privatePath($image), (string) $image['mime']);
        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($targetW, $targetH);
        // Průhlednost (PNG/WebP) → bílé pozadí, JPEG průhlednost neumí.
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
        imageinterlace($canvas, true);

        $this->ensureDir($this->publicDir);
        $target = $this->publicPath($publicId);
        if (!imagejpeg($canvas, $target, 85)) {
            throw new RuntimeException('Veřejnou kopii se nepodařilo uložit.');
        }
        unset($canvas, $source);

        $this->db->update('images', ['is_public' => 1, 'public_id' => $publicId], ['id' => $image['id']]);

        return $publicId;
    }

    public function makePrivate(array $image): void
    {
        if (is_string($image['public_id'] ?? null) && $image['public_id'] !== '') {
            @unlink($this->publicPath((string) $image['public_id']));
        }
        $this->db->update('images', ['is_public' => 0, 'public_id' => null], ['id' => $image['id']]);
    }

    public function delete(array $image): void
    {
        $this->makePrivate($image);
        $path = $this->privatePath($image);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Soubor obrázku nelze smazat.');
        }
        $this->db->delete('images', ['id' => $image['id']]);
        $this->db->run('UPDATE models SET avatar_image_id = NULL WHERE avatar_image_id = :id', ['id' => $image['id']]);
    }

    /**
     * GD drží obrázek nekomprimovaně (~5 B/px s režií). Nedostatek paměti je fatální chyba, kterou nejde zachytit,
     * proto se paměť ověří předem a případně se zkusí navýšit memory_limit.
     */
    public static function ensureMemoryForDecoding(int $pixels): void
    {
        $needed = memory_get_usage() + $pixels * 5 + 16 * 1024 * 1024;
        $limit = self::bytesFromIni((string) ini_get('memory_limit'));
        if ($limit < 0 || $needed <= $limit) {
            return;
        }
        if (@ini_set('memory_limit', (string) $needed) === false || self::bytesFromIni((string) ini_get('memory_limit')) < $needed) {
            throw new RuntimeException(sprintf(
                'Na zpracování obrázku je potřeba cca %d MB paměti, server povoluje %d MB. Nahraj menší verzi (např. max. 2048 px).',
                (int) ceil($needed / 1048576),
                (int) floor($limit / 1048576)
            ));
        }
    }

    /** Převod hodnoty jako „128M“ nebo „1G“ na bajty; -1 = bez omezení. */
    public static function bytesFromIni(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function load(string $path, string $mime): GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };
        if (!$image instanceof GdImage) {
            throw new RuntimeException('Obrázek nelze načíst (poškozený soubor nebo chybí podpora v GD).');
        }

        return $image;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Nelze vytvořit adresář {$dir}.");
        }
    }
}
