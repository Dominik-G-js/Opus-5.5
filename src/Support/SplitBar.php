<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Vodorovný pruh rozdělený na podíly (např. tržby modelky podle platforem). SVG bez inline stylů,
 * barva dílu je CSS třída fill-KEY, mezi díly je mezera, každý díl nese <title>.
 */
final class SplitBar
{
    private const WIDTH = 100;
    private const HEIGHT = 8;
    private const GAP = 0.8;

    /** @param list<array{key: string, value: int, title: string}> $parts */
    public static function render(array $parts, string $ariaLabel): string
    {
        $e = ChartScale::e(...);
        $parts = array_values(array_filter($parts, static fn (array $p): bool => $p['value'] > 0));
        $total = array_sum(array_column($parts, 'value'));
        $svg = sprintf(
            '<svg class="splitbar" viewBox="0 0 %d %d" preserveAspectRatio="none" role="img" aria-label="%s">',
            self::WIDTH,
            self::HEIGHT,
            $e($ariaLabel)
        );
        if ($total <= 0) {
            return $svg . sprintf('<rect class="splitbar-track" x="0" y="0" width="%d" height="%d"/></svg>', self::WIDTH, self::HEIGHT);
        }
        $gap = count($parts) > 1 ? self::GAP : 0;
        $available = self::WIDTH - $gap * (count($parts) - 1);
        $x = 0.0;
        foreach ($parts as $part) {
            $width = max(0.4, $part['value'] / $total * $available);
            $svg .= sprintf(
                '<rect class="fill-%s" x="%.2f" y="0" width="%.2f" height="%d"><title>%s</title></rect>',
                (string) preg_replace('/[^a-z0-9-]/', '', strtolower($part['key'])),
                $x,
                $width,
                self::HEIGHT,
                $e($part['title'])
            );
            $x += $width + $gap;
        }

        return $svg . '</svg>';
    }
}
