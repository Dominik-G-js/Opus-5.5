<?php

declare(strict_types=1);

use App\Kernel\App;
use App\Kernel\Config;
use App\Kernel\Request;

$root = dirname(__DIR__);
require $root . '/autoload.php';

try {
    $app = new App(Config::load($root . '/config/config.php'), $root);
} catch (Throwable $e) {
    error_log('[ai-model-studio] Start selhal: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Aplikace není správně nakonfigurovaná. Podrobnosti jsou v error logu serveru.\n";
    exit;
}

ini_set('display_errors', $app->config->bool('debug') ? '1' : '0');

$request = Request::fromGlobals($app->urls->basePath(), $app->config->stringList('trusted_proxies'));
$app->handle($request)->send();
