<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Kruhový ukazatel splnění (např. měsíční cíl). SVG bez inline stylů, oblouk s gradientem z CSS tříd.
 */
final class ProgressRing
{
    private const SIZE = 120;
    private const RADIUS = 50;

    public static function render(float $fraction, string $ariaLabel, string $id): string
    {
        $id = (string) preg_replace('/[^a-z0-9-]/', '', strtolower($id));
        $fraction = max(0.0, min(1.0, $fraction));
        $center = self::SIZE / 2;
        $circumference = 2 * M_PI * self::RADIUS;
        $svg = sprintf(
            '<svg class="ring" viewBox="0 0 %1$d %1$d" role="img" aria-label="%2$s">'
            . '<defs><linearGradient id="%3$s" x1="0" y1="0" x2="1" y2="1"><stop offset="0" class="stop-ring-a"/><stop offset="1" class="stop-ring-b"/></linearGradient></defs>'
            . '<circle class="ring-track" cx="%4$.1f" cy="%4$.1f" r="%5$d" fill="none"/>',
            self::SIZE,
            ChartScale::e($ariaLabel),
            $id,
            $center,
            self::RADIUS
        );
        if ($fraction > 0) {
            $svg .= sprintf(
                '<circle class="ring-value" cx="%1$.1f" cy="%1$.1f" r="%2$d" fill="none" stroke="url(#%3$s)" stroke-linecap="round"'
                . ' stroke-dasharray="%4$.2f %5$.2f" transform="rotate(-90 %1$.1f %1$.1f)"/>',
                $center,
                self::RADIUS,
                $id,
                $fraction * $circumference,
                $circumference
            );
        }

        return $svg . '</svg>';
    }
}
