<?php

declare(strict_types=1);

namespace App\Support;

use App\Kernel\Response;

/**
 * CSV export pro Excel (středník, UTF-8 s BOM). Textové buňky začínající =, +, -, @, tabulátorem
 * nebo CR se prefixují apostrofem — ochrana proti CSV/formula injection (OWASP).
 */
final class CsvExport
{
    /**
     * @param list<string> $header
     * @param list<list<string|int|float|null>> $rows
     */
    public static function response(string $filename, array $header, array $rows): Response
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Nelze vytvořit dočasný soubor pro export.');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $header, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'cell'], $row), ';', '"', '');
        }
        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return (new Response($content))
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^a-z0-9._-]/i', '_', $filename) . '"');
    }

    public static function cell(string|int|float|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return str_replace('.', ',', (string) $value);
        }

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    /** Částka z haléřů/centů jako číslo (float, aby záporné částky nebyly brány jako text). */
    public static function amount(int $minor): float
    {
        return $minor / 100;
    }
}
