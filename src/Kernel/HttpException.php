<?php

declare(strict_types=1);

namespace App\Kernel;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    public static function notFound(): self
    {
        return new self(404);
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Neplatný požadavek.',
            403 => 'Přístup odepřen.',
            404 => 'Stránka nenalezena.',
            405 => 'Metoda není povolena.',
            413 => 'Soubor je příliš velký.',
            419 => 'Platnost formuláře vypršela. Obnov stránku a zkus to znovu.',
            429 => 'Příliš mnoho pokusů. Zkus to později.',
            default => 'Chyba serveru.',
        };
    }
}
