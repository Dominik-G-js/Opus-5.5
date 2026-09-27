<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Společné měřítko grafů: „kulatá“ osa Y a zkrácené popisky částek (hodnoty v haléřích).
 */
final class ChartScale
{
    /** @return array{0: int, 1: int} [maximum osy, krok] v haléřích, kulaté hodnoty v Kč. */
    public static function nice(int $maxMinor, int $ticks = 4): array
    {
        $max = max(1, $maxMinor / 100);
        $rawStep = $max / max(1, $ticks);
        $magnitude = 10 ** floor(log10($rawStep));
        $step = $magnitude;
        foreach ([1, 2, 2.5, 5, 10] as $multiplier) {
            if ($multiplier * $magnitude >= $rawStep) {
                $step = $multiplier * $magnitude;
                break;
            }
        }
        $step = max(1, (int) round($step));

        return [(int) (ceil($max / $step) * $step * 100), $step * 100];
    }

    /** 1 500 / 250 tis. / 1,5 mil. — částka v Kč bez měny. */
    public static function compact(int $minor): string
    {
        $value = $minor / 100;
        if ($value >= 1_000_000) {
            return rtrim(rtrim(number_format($value / 1_000_000, 1, ',', ''), '0'), ',') . ' mil.';
        }
        if ($value >= 100_000) {
            return rtrim(rtrim(number_format($value / 1000, 1, ',', ''), '0'), ',') . ' tis.';
        }

        return number_format($value, 0, ',', "\u{00A0}");
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
