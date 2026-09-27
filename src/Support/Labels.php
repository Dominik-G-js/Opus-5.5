<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Jediný zdroj povolených hodnot výčtů a jejich českých popisků (validace i UI).
 */
final class Labels
{
    private const GROUPS = [
        'model_status' => [
            'concept' => 'Koncept',
            'building' => 'Ve výrobě',
            'active' => 'Aktivní',
            'paused' => 'Pozastavená',
            'retired' => 'Ukončená',
        ],
        'prompt_kind' => [
            'character_base' => 'Základ postavy (master)',
            'image' => 'Fotka / scéna',
            'video' => 'Video',
            'negative' => 'Negativní prompt',
            'caption' => 'Popisek příspěvku',
            'chat_persona' => 'Chat persona (systémový prompt)',
            'dataset' => 'Dataset pro LoRA',
            'other' => 'Jiné',
        ],
        'tool_category' => [
            'image' => 'Obrázky',
            'video' => 'Video',
            'lora_training' => 'Trénink LoRA',
            'upscale' => 'Upscale / retuš',
            'voice' => 'Hlas',
            'chat' => 'Chat / texty',
            'other' => 'Jiné',
        ],
        'pricing_model' => [
            'subscription' => 'Předplatné',
            'per_use' => 'Platba za použití',
            'credits' => 'Kredity',
            'free' => 'Zdarma',
            'local' => 'Lokálně (vlastní HW)',
        ],
        'cost_category' => [
            'generation' => 'Generování obrázků',
            'training' => 'Trénink LoRA',
            'video' => 'Generování videa',
            'voice' => 'Hlas',
            'subscription' => 'Předplatné nástroje',
            'ads' => 'Reklama / promo',
            'chatting' => 'Chatování / asistent',
            'hosting' => 'Hosting / doména',
            'other' => 'Ostatní',
        ],
        'platform_role' => [
            'monetization' => 'Výdělek (předplatné)',
            'traffic' => 'Zdroj návštěvnosti',
            'both' => 'Obojí',
        ],
        'ai_policy' => [
            'allowed' => 'AI povoleno',
            'restricted' => 'S omezeními',
            'banned' => 'AI persona zakázána',
            'unknown' => 'Neověřeno',
        ],
        'account_status' => [
            'planned' => 'Plánovaný',
            'warming' => 'Zahřívání',
            'active' => 'Aktivní',
            'paused' => 'Pozastavený',
            'banned' => 'Zabanovaný',
        ],
        'tx_type' => [
            'subscription' => 'Předplatné',
            'renewal' => 'Obnovení předplatného',
            'tip' => 'Spropitné',
            'message' => 'Placená zpráva (PPV)',
            'post' => 'Placený příspěvek',
            'media_link' => 'Placený odkaz',
            'referral' => 'Referral',
            'affiliate' => 'Affiliate',
            'giveaway' => 'Giveaway',
            'other' => 'Ostatní',
        ],
        'tx_group' => [
            'subscription' => 'Předplatné',
            'tip' => 'Spropitné',
            'ppv' => 'Placený obsah (PPV)',
            'other' => 'Ostatní',
        ],
        'tx_source' => [
            'manual' => 'Ručně',
            'csv' => 'CSV import',
            'fanvue' => 'Fanvue API',
        ],
        'link_source' => [
            'tiktok' => 'TikTok',
            'x' => 'X (Twitter)',
            'instagram' => 'Instagram',
            'threads' => 'Threads',
            'reddit' => 'Reddit',
            'telegram' => 'Telegram',
            'youtube' => 'YouTube',
            'landing' => 'Landing page',
            'other' => 'Jiné',
        ],
        'integration' => [
            'none' => 'Ručně / CSV',
            'fanvue' => 'Fanvue API',
        ],
        'page_lang' => [
            'en' => 'Angličtina',
            'cs' => 'Čeština',
        ],
        'currency' => [
            'USD' => 'USD',
            'EUR' => 'EUR',
            'GBP' => 'GBP',
            'CZK' => 'CZK',
        ],
    ];

    /** @return array<string, string> */
    public static function group(string $group): array
    {
        return self::GROUPS[$group] ?? [];
    }

    /** @return list<string> */
    public static function keys(string $group): array
    {
        return array_keys(self::group($group));
    }

    public static function get(string $group, ?string $key): string
    {
        if ($key === null || $key === '') {
            return '—';
        }

        return self::GROUPS[$group][$key] ?? $key;
    }
}
