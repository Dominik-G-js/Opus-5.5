<?php

// Router pro vestavěný PHP server (jen lokální vývoj):
// php -S localhost:8000 -t public bin/dev-router.php
declare(strict_types=1);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = realpath(__DIR__ . '/../public' . (is_string($path) ? $path : '/'));
$public = realpath(__DIR__ . '/../public');
if ($file !== false && $public !== false && str_starts_with($file, $public . DIRECTORY_SEPARATOR) && is_file($file)) {
    // Skryté soubory (.htaccess…) se nevydávají — stejně jako na Apache/nginx. PHP soubory řeší index.php.
    if (str_starts_with(basename($file), '.')) {
        http_response_code(404);

        return true;
    }
    if (!str_ends_with($file, '.php')) {
        return false;
    }
}
require __DIR__ . '/../public/index.php';
