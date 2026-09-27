<?php

declare(strict_types=1);

namespace App\Support;

final class Str
{
    /** Latinka s diakritikou → ASCII (fallback bez rozšíření intl; výsledek iconv závisí na systému a locale). */
    private const LATIN_GROUPS = [
        'a' => 'áäàâãåāăą', 'c' => 'çćĉċč', 'd' => 'ďđð', 'e' => 'éèêëēĕėęě', 'g' => 'ĝğġģ', 'h' => 'ĥħ',
        'i' => 'íìîïĩīĭįı', 'j' => 'ĵ', 'k' => 'ķ', 'l' => 'ĺļľŀł', 'n' => 'ñńņňŉ', 'o' => 'óòôöõøōŏő',
        'r' => 'ŕŗř', 's' => 'śŝşš', 't' => 'ţťŧ', 'u' => 'úùûüũūŭůűų', 'w' => 'ŵ', 'y' => 'ýÿŷ', 'z' => 'źżž',
        'ss' => 'ß', 'ae' => 'æ', 'oe' => 'œ', 'th' => 'þ',
    ];

    /** @var array<string, string>|null */
    private static ?array $latinMap = null;

    public static function slug(string $text): string
    {
        $ascii = function_exists('transliterator_transliterate')
            ? (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text)
            : self::transliterateLatin($text);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');

        return substr($slug, 0, 80) ?: 'model';
    }

    public static function transliterateLatin(string $text): string
    {
        if (self::$latinMap === null) {
            self::$latinMap = [];
            foreach (self::LATIN_GROUPS as $ascii => $chars) {
                foreach (mb_str_split($chars) as $char) {
                    self::$latinMap[$char] = $ascii;
                }
            }
        }

        return strtr(mb_strtolower($text), self::$latinMap);
    }

    public static function randomCode(int $length = 8): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    public static function limit(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)) . '…' : $text;
    }

    public static function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
