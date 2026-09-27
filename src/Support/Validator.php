<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Sběr validačních chyb pro formuláře. Každá metoda vrací normalizovanou hodnotu.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    public function required(string $field, string $value, string $label, int $maxLength = 255): string
    {
        if ($value === '') {
            $this->errors[$field] = "{$label}: povinné pole.";
        } elseif (mb_strlen($value) > $maxLength) {
            $this->errors[$field] = "{$label}: max. {$maxLength} znaků.";
        }

        return $value;
    }

    public function optional(string $field, string $value, string $label, int $maxLength = 255): ?string
    {
        if (mb_strlen($value) > $maxLength) {
            $this->errors[$field] = "{$label}: max. {$maxLength} znaků.";
        }

        return $value === '' ? null : $value;
    }

    /**
     * @template T of string
     * @param array<T, string>|list<T> $allowed
     * @return T|string
     */
    public function oneOf(string $field, string $value, array $allowed, string $label): string
    {
        $keys = array_is_list($allowed) ? $allowed : array_keys($allowed);
        if (!in_array($value, $keys, true)) {
            $this->errors[$field] = "{$label}: neplatná hodnota.";
        }

        return $value;
    }

    public function int(string $field, string $value, string $label, int $min, int $max, bool $required = true): ?int
    {
        if ($value === '' && !$required) {
            return null;
        }
        if (preg_match('/^-?\d+$/', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
            $this->errors[$field] = "{$label}: zadej celé číslo {$min}–{$max}.";

            return null;
        }

        return (int) $value;
    }

    public function decimal(string $field, string $value, string $label, float $min, float $max): float
    {
        $normalized = str_replace(',', '.', $value);
        if (!is_numeric($normalized) || (float) $normalized < $min || (float) $normalized > $max) {
            $this->errors[$field] = "{$label}: zadej číslo {$min}–{$max}.";

            return 0.0;
        }

        return (float) $normalized;
    }

    public function money(string $field, string $value, string $label, bool $allowNegative = false): int
    {
        try {
            $minor = Money::parseToMinor($value);
        } catch (InvalidArgumentException $e) {
            $this->errors[$field] = "{$label}: " . $e->getMessage();

            return 0;
        }
        if (!$allowNegative && $minor < 0) {
            $this->errors[$field] = "{$label}: nesmí být záporné.";
        }

        return $minor;
    }

    public function currency(string $field, string $value, string $label): string
    {
        $value = strtoupper($value);
        if (!in_array($value, Money::CURRENCIES, true)) {
            $this->errors[$field] = "{$label}: nepodporovaná měna.";
        }

        return $value;
    }

    public function date(string $field, string $value, string $label): string
    {
        if (!Clock::isValidDate($value)) {
            $this->errors[$field] = "{$label}: zadej datum ve formátu RRRR-MM-DD.";
        }

        return $value;
    }

    public function url(string $field, string $value, string $label, bool $required = false): ?string
    {
        if ($value === '') {
            if ($required) {
                $this->errors[$field] = "{$label}: povinné pole.";
            }

            return null;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if (filter_var($value, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true) || mb_strlen($value) > 2000) {
            $this->errors[$field] = "{$label}: zadej platnou adresu začínající https://.";
        }

        return $value;
    }

    public function domain(string $field, string $value, string $label): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value) !== 1) {
            $this->errors[$field] = "{$label}: zadej doménu bez https:// (např. jmeno-modelky.com).";
        }

        return $value;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
