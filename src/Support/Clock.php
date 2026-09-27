<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Jednotné zacházení s časem: DB drží UTC ('Y-m-d H:i:s'), UI a měsíční přehledy lokální zónu.
 */
final class Clock
{
    public const DB_FORMAT = 'Y-m-d H:i:s';

    private static string $localZone = 'Europe/Prague';

    public static function setLocalZone(string $zone): void
    {
        new DateTimeZone($zone); // vyhodí výjimku při neplatné zóně
        self::$localZone = $zone;
    }

    public static function localZone(): DateTimeZone
    {
        return new DateTimeZone(self::$localZone);
    }

    public static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    public static function nowUtc(): string
    {
        return (new DateTimeImmutable('now', self::utc()))->format(self::DB_FORMAT);
    }

    public static function todayLocal(): string
    {
        return (new DateTimeImmutable('now', self::localZone()))->format('Y-m-d');
    }

    public static function toUtcString(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(self::utc())->format(self::DB_FORMAT);
    }

    public static function localDateOf(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(self::localZone())->format('Y-m-d');
    }

    /** Převod UTC hodnoty z DB na lokální zobrazení. */
    public static function formatLocal(?string $utc, string $format = 'j. n. Y H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }
        $moment = DateTimeImmutable::createFromFormat(self::DB_FORMAT, $utc, self::utc());
        if ($moment === false) {
            return $utc;
        }

        return $moment->setTimezone(self::localZone())->format($format);
    }

    public static function isValidDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
