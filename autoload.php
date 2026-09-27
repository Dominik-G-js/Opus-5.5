<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80200) {
    error_log('AI Model Studio vyžaduje PHP 8.2 nebo novější.');
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    echo "AI Model Studio vyžaduje PHP 8.2 nebo novější.\n";
    exit(1);
}

foreach (['pdo_sqlite', 'sodium', 'curl', 'mbstring', 'gd', 'fileinfo'] as $extension) {
    if (!extension_loaded($extension)) {
        throw new RuntimeException("Chybí PHP rozšíření {$extension}.");
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Varování a notice se nepolykají: převádíme je na výjimky (kromě výrazů potlačených pomocí @).
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
