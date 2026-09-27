<?php

declare(strict_types=1);

namespace App\Security;

final class Passwords
{
    public const MIN_LENGTH = 12;

    public static function algorithm(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /** Platný hash náhodného hesla stejného algoritmu — pro vyrovnání času odezvy u neexistujícího účtu. */
    public static function dummyHash(): string
    {
        return defined('PASSWORD_ARGON2ID')
            ? '$argon2id$v=19$m=65536,t=4,p=1$Z0M0TGcyazM1Y1RNTHVCVQ$H9/q7lVuj3s+gppFlEoPBgXUFvJzNA1JFvJpAitkMHQ'
            : '$2y$12$PBgF5hbj72YrdzPeri70iufpVQWfzUUMa45SrPec7AT0qsSeN/v9S';
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    /** Vrací chybovou hlášku, nebo null pokud je heslo přijatelné. */
    public static function validate(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'Heslo musí mít alespoň ' . self::MIN_LENGTH . ' znaků (ideálně delší frázi).';
        }
        if (mb_strlen($password) > 256) {
            return 'Heslo je příliš dlouhé.';
        }
        if (count(array_unique(mb_str_split($password))) < 6) {
            return 'Heslo je příliš jednoduché (málo různých znaků).';
        }

        return null;
    }
}
