<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\Database;
use App\Support\Clock;

/**
 * Sledovací odkazy /go/{kód}: počítají prokliky po dnech (bez ukládání IP ani cookies — GDPR friendly)
 * a přesměrují na cíl. Díky tomu je vidět, který zdroj (TikTok, X, Reddit…) přivádí lidi.
 */
final class LinkTracker
{
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|whatsapp|telegrambot|discordbot|curl|wget|python-requests|headless/i';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return string|null Cílová adresa, nebo null pokud odkaz neexistuje / je vypnutý.
     */
    public function resolveAndCount(string $code, string $userAgent, ?int $onlyModelId = null): ?string
    {
        $link = $this->db->one('SELECT id, model_id, target_url, is_active FROM links WHERE code = :c', ['c' => $code]);
        if ($link === null || (int) $link['is_active'] !== 1) {
            return null;
        }
        if ($onlyModelId !== null && (int) $link['model_id'] !== $onlyModelId) {
            return null;
        }
        if ($userAgent !== '' && preg_match(self::BOT_PATTERN, $userAgent) !== 1) {
            $this->db->run(
                'INSERT INTO link_clicks_daily (link_id, day, clicks) VALUES (:l, :d, 1)
                 ON CONFLICT (link_id, day) DO UPDATE SET clicks = clicks + 1',
                ['l' => $link['id'], 'd' => Clock::todayLocal()]
            );
        }

        return (string) $link['target_url'];
    }
}
