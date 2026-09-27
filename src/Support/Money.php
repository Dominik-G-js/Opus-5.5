<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Peníze jako celé číslo v nejmenších jednotkách (centy/haléře). Žádné floaty při parsování.
 */
final class Money
{
    public const CURRENCIES = ['CZK', 'USD', 'EUR', 'GBP'];

    /**
     * Přijme „12,50“, „12.50“, „1 234,56“, „$1,234.56“, „-5“ a vrátí 1250, 1250, 123456, 123456, -500.
     */
    public static function parseToMinor(string $input): int
    {
        $value = str_replace(["\u{00A0}", "\u{202F}", ' ', '$', '€', '£', 'Kč', 'CZK', 'USD', 'EUR', 'GBP'], '', trim($input));
        if ($value === '') {
            throw new InvalidArgumentException('Částka je prázdná.');
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        $decimalPos = null;
        if ($lastComma !== false && $lastDot !== false) {
            $decimalPos = max($lastComma, $lastDot);
        } elseif ($lastComma !== false || $lastDot !== false) {
            $pos = $lastComma !== false ? $lastComma : $lastDot;
            $separator = $value[$pos];
            $digitsAfter = strlen($value) - $pos - 1;
            // Oddělovač následovaný přesně 3 číslicemi (a jediný svého druhu) je oddělovač tisíců.
            $isThousands = $digitsAfter === 3
                && preg_match('/^[1-9]\d{0,2}([' . preg_quote($separator, '/') . ']\d{3})+$/', $value) === 1;
            $decimalPos = $isThousands ? null : $pos;
        }

        if ($decimalPos === null) {
            $integer = (string) preg_replace('/[.,]/', '', $value);
            $fraction = '';
        } else {
            $integer = (string) preg_replace('/[.,]/', '', substr($value, 0, $decimalPos));
            $fraction = substr($value, $decimalPos + 1);
        }
        if ($integer === '') {
            $integer = '0';
        }
        if (preg_match('/^\d+$/', $integer) !== 1 || ($fraction !== '' && preg_match('/^\d{1,2}$/', $fraction) !== 1)) {
            throw new InvalidArgumentException("Neplatná částka „{$input}“.");
        }
        if (strlen($integer) > 12) {
            throw new InvalidArgumentException('Částka je příliš velká.');
        }
        $minor = (int) $integer * 100 + (int) str_pad($fraction, 2, '0');

        return $negative ? -$minor : $minor;
    }

    public static function format(int $minor, string $currency = 'CZK', bool $withCents = true): string
    {
        $decimals = $withCents ? 2 : 0;
        $amount = $minor / 100;
        if (!$withCents) {
            $amount = round($amount);
        }
        $formatted = number_format($amount, $decimals, ',', "\u{00A0}");

        return match ($currency) {
            'CZK' => $formatted . "\u{00A0}Kč",
            'USD' => '$' . number_format($amount, $decimals, '.', ','),
            'EUR' => $formatted . "\u{00A0}€",
            'GBP' => '£' . number_format($amount, $decimals, '.', ','),
            default => $formatted . "\u{00A0}" . $currency,
        };
    }

    /** Hodnota pro předvyplnění formuláře („12,50“). */
    public static function toInput(?int $minor): string
    {
        if ($minor === null) {
            return '';
        }

        return number_format($minor / 100, 2, ',', '');
    }

    /**
     * Doplní chybějící hrubou nebo čistou částku podle poplatku platformy (v %).
     * Při poplatku 100 % nelze hrubou částku z čisté spočítat — pak se bere rovna čisté.
     *
     * @return array{0: int, 1: int} [hrubá, čistá]
     */
    public static function completeGrossNet(?int $gross, ?int $net, float $feePercent): array
    {
        if ($gross === null && $net === null) {
            throw new InvalidArgumentException('Zadej hrubou nebo čistou částku.');
        }
        if ($feePercent < 0 || $feePercent > 100) {
            throw new InvalidArgumentException('Poplatek musí být 0–100 %.');
        }
        $keep = 1 - $feePercent / 100;
        $net ??= (int) round((int) $gross * $keep);
        $gross ??= $keep > 0 ? (int) round($net / $keep) : $net;

        return [$gross, $net];
    }

    public static function convert(int $minor, float $rate): int
    {
        return (int) round($minor * $rate);
    }

    public static function assertCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (!in_array($currency, self::CURRENCIES, true)) {
            throw new InvalidArgumentException("Nepodporovaná měna {$currency}.");
        }

        return $currency;
    }
}
