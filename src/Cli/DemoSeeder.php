<?php

declare(strict_types=1);

namespace App\Cli;

use App\Database\Database;
use App\Http\HttpClient;
use App\Http\HttpClientException;
use App\Http\HttpResponse;
use App\Service\ExchangeRates;
use App\Service\ImageStore;
use App\Service\Ledger;
use App\Support\Clock;
use DateTimeImmutable;
use RuntimeException;

/**
 * Ukázková data pro vyzkoušení aplikace: jedna aktivní modelka s 5 měsíci provozu, fanoušci, náklady,
 * odkazy a prokliky. Běží offline (pevný kurz USD), obrázky jsou barevné zástupné plochy.
 * Vše je označené, takže `demo:clear` smaže přesně jen ukázková data.
 */
final class DemoSeeder
{
    public const NOTE = 'Ukázková data';
    private const SLUGS = ['nessa-wren', 'mila-vesna'];
    private const USD_RATE = 21.1;

    private Ledger $ledger;

    public function __construct(private readonly Database $db, private readonly ImageStore $images)
    {
        $offline = new class implements HttpClient {
            public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
            {
                throw new HttpClientException('Ukázková data nepoužívají síť.');
            }
        };
        $this->ledger = new Ledger($db, new ExchangeRates($db, $offline));
    }

    /** @return array{model_id: int, transactions: int} */
    public function seed(): array
    {
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM models') > 0) {
            throw new RuntimeException('V databázi už jsou modelky. Ukázková data se vkládají jen do prázdné instalace.');
        }
        mt_srand(7);
        $now = Clock::nowUtc();

        return $this->db->transaction(function () use ($now): array {
            $modelId = $this->createModels($now);
            $masterPrompt = $this->createPrompts($modelId, $now);
            $this->createImages($modelId, $masterPrompt);
            $fanvue = $this->createAccounts($modelId, $now);
            $transactions = $this->createTransactionsAndCosts($modelId, $fanvue, $now);
            $this->createLinks($modelId, $now);
            $this->createSubscriberStats($fanvue);

            return ['model_id' => $modelId, 'transactions' => $transactions];
        });
    }

    /** Smaže jen ukázková data (modelky se slugem z ukázky a náklady s poznámkou „Ukázková data“). */
    public function clear(): int
    {
        $placeholders = implode(', ', array_map(static fn (int $i): string => ':s' . $i, array_keys(self::SLUGS)));
        $params = [];
        foreach (self::SLUGS as $i => $slug) {
            $params['s' . $i] = $slug;
        }
        $models = $this->db->all("SELECT id FROM models WHERE slug IN ({$placeholders})", $params);
        foreach ($models as $model) {
            foreach ($this->db->all('SELECT * FROM images WHERE model_id = :m', ['m' => $model['id']]) as $image) {
                $this->images->delete($image);
            }
            $this->db->delete('models', ['id' => $model['id']]);
        }
        $this->db->run('DELETE FROM costs WHERE note = :n', ['n' => self::NOTE]);

        return count($models);
    }

    private function createModels(string $now): int
    {
        $modelId = $this->db->insert('models', [
            'name' => 'Nessa Wren', 'slug' => 'nessa-wren', 'status' => 'active', 'persona_age' => 26,
            'niche' => 'Cozy gamerka „girl next door“', 'tagline' => 'Cozy gamer, coffee addict, night owl.',
            'public_bio' => "Virtual AI creator — gaming nights, cozy outfits and late-night chats.\nEverything here is AI-generated.",
            'backstory' => 'Z přímořského městečka, studovala grafiku, po nocích streamuje indie hry a má kocoura Pixela.',
            'personality' => 'Vtipná, trochu stydlivá, sarkastická u her. Píše malými písmeny, emoji 🎮☕.',
            'look_face' => 'soft oval face, light freckles across nose and cheeks, small mole above left lip',
            'look_hair' => 'long wavy copper-auburn hair with curtain bangs',
            'look_eyes' => 'bright green eyes',
            'look_skin' => 'fair skin with natural texture',
            'look_body' => 'slim athletic build, 168 cm',
            'look_marks' => 'small mole above left lip, thin silver ring on right index finger',
            'look_style' => 'oversized hoodies, cat-ear headset, knee socks, pastel RGB setup',
            'base_model' => 'FLUX.1 dev + LoRA', 'lora_name' => 'nessa_v3.safetensors', 'lora_trigger' => 'ohwx_nessa',
            'lora_weight' => '0.8', 'lora_location' => 'Google Drive /AI/LoRA + externí disk', 'default_seed' => '424242',
            'default_negative' => 'plastic skin, airbrushed, waxy, doll-like, cgi, extra fingers, child, teen, young-looking',
            'page_published' => 1, 'page_lang' => 'en',
            'seo_title' => 'Nessa Wren — AI creator | Fanvue, X & TikTok',
            'seo_description' => 'Nessa Wren is an AI-generated virtual creator: cozy gaming, outfits and exclusive content.',
            'notes' => self::NOTE, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->insert('models', [
            'name' => 'Mila Vesna', 'slug' => 'mila-vesna', 'status' => 'building', 'persona_age' => 27,
            'niche' => 'Česká fitness a hory', 'tagline' => 'Mountains, gym and sauna.',
            'notes' => self::NOTE, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach (['fal.ai – Flux LoRA trénink' => 'trénink LoRA', 'fal.ai – Flux LoRA generování' => 'generování fotek', 'Kling 3.0' => 'videa na TikTok', 'ElevenLabs' => 'hlasové zprávy'] as $tool => $purpose) {
            $toolId = $this->toolId($tool);
            if ($toolId !== null) {
                $this->db->insert('model_tools', ['model_id' => $modelId, 'tool_id' => $toolId, 'purpose' => $purpose]);
            }
        }

        return $modelId;
    }

    private function createPrompts(int $modelId, string $now): int
    {
        $imageTool = $this->toolId('fal.ai – Flux LoRA generování');
        $master = $this->db->insert('prompts', [
            'model_id' => $modelId, 'tool_id' => $imageTool, 'kind' => 'character_base', 'title' => 'Master — Nessa',
            'prompt' => 'ohwx_nessa, photo of a 26-year-old woman, soft oval face, light freckles across nose and cheeks, bright green eyes, '
                . 'long wavy copper-auburn hair with curtain bangs, fair skin, slim athletic build, small mole above left lip, '
                . 'shot on iPhone 15 Pro, candid, natural skin texture, visible pores',
            'negative_prompt' => 'plastic skin, airbrushed, waxy, doll-like, cgi, extra fingers, child, teen, young-looking',
            'seed' => '424242', 'settings' => 'steps 28, cfg 3.5, euler, 832×1216, LoRA 0.8', 'is_master' => 1, 'rating' => 5,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $scenes = [
            ['Gaming setup — večer', 'image', 5, 'sitting in a gaming chair in a cozy bedroom with RGB lights, wearing an oversized lavender hoodie and a cat-ear headset, soft monitor glow'],
            ['Kavárna — ráno', 'image', 4, 'at a café table by the window with a laptop and croissant, wearing a cream knit sweater, overcast daylight'],
            ['Otočení hlavy (TikTok)', 'video', 4, 'she slowly turns her head toward the camera and smiles, hair moves slightly, handheld phone camera, 5 seconds'],
        ];
        foreach ($scenes as [$title, $kind, $rating, $scene]) {
            $this->db->insert('prompts', [
                'model_id' => $modelId, 'tool_id' => $kind === 'video' ? $this->toolId('Kling 3.0') : $imageTool,
                'kind' => $kind, 'title' => $title, 'prompt' => 'ohwx_nessa, photo of a 26-year-old woman, … (master popis) …, ' . $scene,
                'rating' => $rating, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $master;
    }

    private function createImages(int $modelId, int $masterPrompt): void
    {
        $palette = [[196, 120, 92], [120, 98, 168], [70, 130, 150], [214, 160, 110], [150, 90, 120], [90, 120, 90]];
        $avatar = null;
        foreach ($palette as $i => [$r, $g, $b]) {
            $canvas = imagecreatetruecolor(800, 1000);
            for ($y = 0; $y < 1000; $y++) {
                $k = $y / 1000;
                $color = (int) imagecolorallocate($canvas, (int) ($r * (1 - $k * .5)), (int) ($g * (1 - $k * .4)), (int) min(255, $b + 40 * $k));
                imageline($canvas, 0, $y, 800, $y, $color);
            }
            $file = tempnam(sys_get_temp_dir(), 'ams-demo');
            if ($file === false || !imagejpeg($canvas, $file, 90)) {
                throw new RuntimeException('Nelze vytvořit ukázkový obrázek.');
            }
            try {
                $id = $this->images->storeFile($modelId, $file, "nessa_demo_{$i}.jpg", [
                    'prompt_id' => $masterPrompt, 'seed' => (string) (424242 + $i), 'is_reference' => $i < 2,
                    'alt_text' => 'Nessa Wren — AI-generated photo', 'notes' => self::NOTE,
                ]);
            } finally {
                @unlink($file);
            }
            $this->images->makePublic((array) $this->db->one('SELECT * FROM images WHERE id = :id', ['id' => $id]));
            $avatar ??= $id;
        }
        $this->db->update('models', ['avatar_image_id' => $avatar], ['id' => $modelId]);
    }

    private function createAccounts(int $modelId, string $now): int
    {
        $fanvue = $this->db->insert('accounts', [
            'model_id' => $modelId, 'platform_id' => $this->platformId('Fanvue'), 'handle' => 'nessawren',
            'profile_url' => 'https://www.fanvue.com/nessawren', 'status' => 'active', 'currency' => 'USD', 'fee_percent' => 15,
            'show_on_page' => 1, 'notes' => self::NOTE, 'created_at' => $now,
        ]);
        foreach ([['X (Twitter)', 'nessawren', 'https://x.com/nessawren'], ['TikTok', 'nessa.wren', 'https://www.tiktok.com/@nessa.wren'], ['Reddit', 'NessaWren', 'https://www.reddit.com/user/NessaWren']] as [$platform, $handle, $url]) {
            $this->db->insert('accounts', [
                'model_id' => $modelId, 'platform_id' => $this->platformId($platform), 'handle' => $handle, 'profile_url' => $url,
                'status' => 'active', 'currency' => 'USD', 'fee_percent' => 0, 'show_on_page' => 1, 'notes' => self::NOTE, 'created_at' => $now,
            ]);
        }

        return $fanvue;
    }

    private function createTransactionsAndCosts(int $modelId, int $fanvue, string $now): int
    {
        $fanIds = [];
        foreach (['NightOwl_88', 'pixelknight', 'coffee_dad', 'Marek.K', 'gamer_joe', 'silentfox', 'Tom_R', 'lucky7', 'cozyfan', 'redpanda'] as $i => $fan) {
            $fanIds[] = $this->ledger->upsertFan($fanvue, 'demo-' . $i, $fan, $fan, $i < 2);
        }
        $types = [['subscription', 999], ['renewal', 999], ['message', 800], ['message', 1500], ['tip', 500], ['post', 1200], ['tip', 2000]];
        $today = new DateTimeImmutable(Clock::todayLocal(), Clock::localZone());
        $count = 0;
        foreach ([4 => 4, 3 => 12, 2 => 26, 1 => 42, 0 => 38] as $monthsAgo => $payments) {
            $monthStart = $today->modify('first day of this month')->modify("-{$monthsAgo} months");
            $days = $monthsAgo === 0 ? (int) $today->format('j') : (int) $monthStart->format('t');
            for ($i = 0; $i < $payments; $i++) {
                [$type, $gross] = $types[mt_rand(0, count($types) - 1)];
                $fanIndex = min(count($fanIds) - 1, (int) floor((mt_rand(0, 100) / 100) ** 2 * count($fanIds)));
                $moment = $monthStart->modify('+' . mt_rand(0, $days - 1) . ' days')->setTime(mt_rand(8, 23), mt_rand(0, 59));
                $this->ledger->insertTransaction($this->ledger->prepareTransaction($fanvue, [
                    'occurred_at' => $moment, 'type' => $type, 'gross_minor' => $gross, 'net_minor' => (int) round($gross * 0.85),
                    'currency' => 'USD', 'source' => 'manual', 'fan_id' => $fanIds[$fanIndex], 'fx_rate' => self::USD_RATE, 'note' => self::NOTE,
                ]));
                $count++;
            }
            $costDate = $monthStart->modify('+3 days')->format('Y-m-d');
            $costs = [['generation', 'fal.ai – Flux LoRA generování', 1200, 300], ['video', 'Kling 3.0', 1600, 40], ['voice', 'ElevenLabs', 600, null]];
            if ($monthsAgo === 4) {
                $costs[] = ['training', 'fal.ai – Flux LoRA trénink', 480, null];
            }
            foreach ($costs as [$category, $tool, $amount, $quantity]) {
                $this->insertCost($modelId, $this->toolId($tool), $category, $costDate, $amount, 'USD', $quantity);
            }
            $this->insertCost(null, null, 'hosting', $costDate, 9900, 'CZK', null);
        }

        return $count;
    }

    private function insertCost(?int $modelId, ?int $toolId, string $category, string $date, int $amount, string $currency, ?int $quantity): void
    {
        $rate = $currency === 'CZK' ? 1.0 : self::USD_RATE;
        $this->db->insert('costs', [
            'model_id' => $modelId, 'tool_id' => $toolId, 'category' => $category, 'incurred_on' => $date,
            'amount_minor' => $amount, 'currency' => $currency, 'fx_rate' => $rate, 'amount_czk_minor' => (int) round($amount * $rate),
            'quantity' => $quantity, 'note' => self::NOTE, 'created_at' => Clock::nowUtc(),
        ]);
    }

    private function createLinks(int $modelId, string $now): void
    {
        $today = new DateTimeImmutable(Clock::todayLocal());
        $links = [['TikTok bio', 'tiktok', 'tt', 0, 1, 18], ['X bio', 'x', 'x', 0, 1, 11], ['Reddit profil', 'reddit', 'rd', 0, 0, 6], ['Fanvue', 'landing', 'fv', 1, 1, 9]];
        foreach ($links as $i => [$label, $source, $code, $premium, $onPage, $daily]) {
            $linkId = $this->db->insert('links', [
                'model_id' => $modelId, 'code' => 'nessa-' . $code, 'label' => $label, 'source' => $source,
                'target_url' => 'https://www.fanvue.com/nessawren', 'is_active' => 1, 'is_premium' => $premium,
                'show_on_page' => $onPage, 'sort_order' => $i, 'created_at' => $now,
            ]);
            for ($d = 0; $d < 30; $d++) {
                $this->db->insert('link_clicks_daily', [
                    'link_id' => $linkId, 'day' => $today->modify("-{$d} days")->format('Y-m-d'), 'clicks' => max(0, $daily + mt_rand(-4, 6)),
                ]);
            }
        }
    }

    private function createSubscriberStats(int $accountId): void
    {
        $today = new DateTimeImmutable(Clock::todayLocal());
        for ($d = 0; $d < 30; $d++) {
            $this->db->insert('subscriber_stats_daily', [
                'account_id' => $accountId, 'day' => $today->modify("-{$d} days")->format('Y-m-d'),
                'new_subscribers' => mt_rand(0, 3), 'cancelled' => mt_rand(0, 1),
            ]);
        }
    }

    private function toolId(string $name): ?int
    {
        $id = $this->db->scalar('SELECT id FROM ai_tools WHERE name = :n', ['n' => $name]);

        return $id === null ? null : (int) $id;
    }

    private function platformId(string $name): int
    {
        $id = $this->db->scalar('SELECT id FROM platforms WHERE name = :n', ['n' => $name]);
        if ($id === null) {
            throw new RuntimeException("Chybí platforma {$name} (spusť migrate).");
        }

        return (int) $id;
    }
}
