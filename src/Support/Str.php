<?php

declare(strict_types=1);

namespace App\Support;

final class Str
{
    public static function slug(string $text): string
    {
        $ascii = function_exists('transliterator_transliterate')
            ? (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text)
            : strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');

        return substr($slug, 0, 80) ?: 'model';
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
