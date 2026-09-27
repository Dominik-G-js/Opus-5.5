<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Malý dekorativní průběh hodnoty (SVG, aria-hidden — číslo je vždy vypsané vedle).
 * Roztahuje se na šířku kontejneru, čára drží 2 px díky vector-effect.
 */
final class Sparkline
{
    private const WIDTH = 100;
    private const HEIGHT = 32;

    /** @param list<int> $values */
    public static function render(array $values, string $key): string
    {
        $key = (string) preg_replace('/[^a-z0-9-]/', '', strtolower($key));
        $values = array_values($values);
        if ($values === []) {
            $values = [0];
        }
        if (count($values) === 1) {
            $values[] = $values[0];
        }
        $min = min(0, ...$values);
        $max = max(...$values);
        $range = $max - $min;
        $pad = 2;
        $count = count($values);
        $points = [];
        foreach ($values as $i => $value) {
            $px = $i / ($count - 1) * self::WIDTH;
            $py = $range > 0
                ? $pad + (1 - ($value - $min) / $range) * (self::HEIGHT - 2 * $pad)
                : self::HEIGHT - $pad;
            $points[] = sprintf('%.2f %.2f', $px, $py);
        }
        $line = 'M' . implode(' L', $points);

        return sprintf(
            '<svg class="sparkline" viewBox="0 0 %1$d %2$d" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
            . '<path class="spark-fill fill-%3$s" d="%4$s L%1$d %2$d L0 %2$d Z"/>'
            . '<path class="spark-line stroke-%3$s" fill="none" vector-effect="non-scaling-stroke" d="%4$s"/></svg>',
            self::WIDTH,
            self::HEIGHT,
            $key,
            $line
        );
    }
}
