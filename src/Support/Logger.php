<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Jednoduchý logger do měsíčních souborů (JSON lines) mimo webroot.
 */
final class Logger
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @param array<string, mixed> $context */
    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $event, array $context = []): void
    {
        $this->write('warning', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $event, array $context = []): void
    {
        $this->write('error', $event, $context);
    }

    public function exception(Throwable $e, string $event = 'exception'): void
    {
        $this->error($event, [
            'type' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $event, array $context): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            error_log("[ai-model-studio] Nelze vytvořit adresář logů {$this->directory}");

            return;
        }
        $line = json_encode([
            'time' => gmdate('c'),
            'level' => $level,
            'event' => $event,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $file = $this->directory . '/app-' . gmdate('Y-m') . '.log';
        if (@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log("[ai-model-studio] Nelze zapsat do logu {$file}: {$line}");
        }
    }
}
