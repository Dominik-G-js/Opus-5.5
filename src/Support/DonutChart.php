<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Prstencový graf podílů (SVG, bez inline stylů). Barva segmentu je CSS třída stroke-KEY,
 * mezi segmenty je 2px mezera, každý segment nese <title> s názvem, částkou a podílem.
 */
final class DonutChart
{
    private const SIZE = 120;
    private const RADIUS = 48;
    private const GAP = 1.6;

    /**
     * @param list<array{key: string, value: int, title: string}> $segments hodnoty v haléřích
     */
    public static function render(array $segments, string $ariaLabel): string
    {
        $e = ChartScale::e(...);
        $center = self::SIZE / 2;
        $circumference = 2 * M_PI * self::RADIUS;
        $segments = array_values(array_filter($segments, static fn (array $s): bool => $s['value'] > 0));
        $total = array_sum(array_column($segments, 'value'));

        $svg = sprintf(
            '<svg class="donut" viewBox="0 0 %1$d %1$d" role="img" aria-label="%2$s">'
            . '<circle class="donut-track" cx="%3$.1f" cy="%3$.1f" r="%4$d" fill="none"/>',
            self::SIZE,
            $e($ariaLabel),
            $center,
            self::RADIUS
        );
        if ($total <= 0) {
            return $svg . '</svg>';
        }

        $gap = count($segments) > 1 ? self::GAP : 0;
        $offset = 0.0;
        $svg .= sprintf('<g transform="rotate(-90 %1$.1f %1$.1f)">', $center);
        foreach ($segments as $segment) {
            $length = $segment['value'] / $total * $circumference;
            $visible = max(0.6, $length - $gap);
            $svg .= sprintf(
                '<circle class="donut-seg stroke-%s" cx="%.1f" cy="%.1f" r="%d" fill="none" stroke-dasharray="%.2f %.2f" stroke-dashoffset="%.2f" tabindex="0"><title>%s</title></circle>',
                (string) preg_replace('/[^a-z0-9-]/', '', strtolower($segment['key'])),
                $center,
                $center,
                self::RADIUS,
                $visible,
                $circumference - $visible,
                -$offset,
                $e($segment['title'])
            );
            $offset += $length;
        }

        return $svg . '</g></svg>';
    }
}
