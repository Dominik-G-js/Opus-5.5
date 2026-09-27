<?php

// Zkopíruj jako config/config.php (nebo spusť `php bin/console install`, který ho vytvoří sám).
// Tento soubor NIKDY necommituj — obsahuje tajné klíče.

return [
    'app_name' => 'AI Model Studio',

    // Hlavní adresa aplikace (administrace + landing pages na /m/{slug}). Bez lomítka na konci.
    'base_url' => 'https://studio.example.com',

    // Cesta k administraci. Méně obvyklá cesta (např. /studio-k7x2) sníží automatizované útoky.
    'admin_path' => '/admin',

    // 32bajtový klíč pro šifrování tokenů a 2FA v databázi. Vygeneruje `php bin/console install`.
    // Při změně/ztrátě klíče nepůjdou dešifrovat uložené tokeny (stačí znovu připojit Fanvue a zapnout 2FA).
    'app_key' => 'base64:CHANGE_ME',

    'timezone' => 'Europe/Prague',

    // Absolutní cesta k SQLite databázi (mimo veřejnou složku).
    'db_path' => __DIR__ . '/../storage/database.sqlite',

    // Na produkci vždy true: administrace pouze přes HTTPS.
    'force_https' => true,
    'hsts' => true,

    // Zobrazovat detail chyb (jen pro lokální vývoj!).
    'debug' => false,

    // Pokud běží za reverzní proxy / Cloudflare, uveď jejich IP rozsahy (CIDR),
    // jinak nebude fungovat omezení pokusů o přihlášení podle IP. Např. ['173.245.48.0/20', ...].
    'trusted_proxies' => [],

    'session' => [
        'name' => 'ams_sid',
        'idle_timeout' => 7200,      // odhlášení po 2 h nečinnosti
        'absolute_timeout' => 43200, // max. 12 h od přihlášení
    ],

    // Fanvue API: aplikaci založíš ve Fanvue developer portálu (viz README → Fanvue API).
    // Redirect URI: {base_url}{admin_path}/integrations/fanvue/callback
    'fanvue' => [
        'client_id' => '',
        'client_secret' => '',
        'api_version' => '2025-06-26',
    ],
];
