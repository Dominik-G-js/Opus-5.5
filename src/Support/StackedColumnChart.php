<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Serverově vykreslený skládaný sloupcový graf (SVG, bez inline stylů). Barvu dílu nese CSS třída
 * fill-KEY podle klíče řady. Sloupec max. 24 px, zaoblený jen datový konec (4 px), mezi díly 2px mezera
 * v barvě podkladu. Každý sloupec je cíl pro hover i klávesnici (tabindex) s <title> a data-tip="ID-tip-N"
 * — app.js podle něj zobrazí bohatý tooltip, bez JS funguje nativní <title>.
 */
final class StackedColumnChart
{
    private const WIDTH = 640;
    private const HEIGHT = 240;
    private const PAD_LEFT = 56;
    private const PAD_RIGHT = 14;
    private const PAD_TOP = 14;
    private const PAD_BOTTOM = 28;
    private const BAR_MAX = 24;
    private const BAR_FILL = 0.72;
    private const GAP = 2;
    private const RADIUS = 4;
    private const MAX_LABELS = 8;

    /**
     * @param list<string> $labels popisky osy X, jeden na sloupec
     * @param list<array{key: string, name: string, values: list<int>}> $series řady odspodu nahoru, hodnoty v haléřích
     * @param list<string> $titles textový popis sloupce (tooltip bez JS a přístupný název)
     * @param string $id jedinečný prefix pro id tooltipů
     */
    public static function render(array $labels, array $series, array $titles, string $ariaLabel, string $id): string
    {
        $count = count($labels);
        if ($count === 0 || $series === []) {
            return '';
        }
        $id = self::slug($id);
        $e = ChartScale::e(...);

        $totals = array_fill(0, $count, 0);
        foreach ($series as $s) {
            foreach ($totals as $i => $total) {
                $totals[$i] = $total + max(0, (int) ($s['values'][$i] ?? 0));
            }
        }
        [$axisMax, $step] = ChartScale::nice(max(0, ...$totals));

        $plotW = self::WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $plotH = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;
        $baseline = self::PAD_TOP + $plotH;
        $band = $plotW / $count;
        $barW = max(2.0, min(self::BAR_MAX, $band * self::BAR_FILL));
        $px = static fn (int $value): float => $axisMax > 0 ? $value / $axisMax * $plotH : 0.0;

        $svg = sprintf(
            '<svg class="chart chart-stack" viewBox="0 0 %d %d" role="group" aria-label="%s" preserveAspectRatio="xMidYMid meet">',
            self::WIDTH,
            self::HEIGHT,
            $e($ariaLabel)
        );

        for ($tick = 0; $tick <= $axisMax; $tick += $step) {
            $ty = $baseline - $px($tick);
            $svg .= sprintf(
                '<line class="%s" x1="%d" x2="%d" y1="%.1f" y2="%.1f"/>',
                $tick === 0 ? 'chart-baseline' : 'chart-grid',
                self::PAD_LEFT,
                self::WIDTH - self::PAD_RIGHT,
                $ty,
                $ty
            );
            $svg .= sprintf(
                '<text class="chart-tick" x="%d" y="%.1f" text-anchor="end" dominant-baseline="middle">%s</text>',
                self::PAD_LEFT - 8,
                $ty,
                $e(ChartScale::compact($tick))
            );
        }

        foreach ($labels as $i => $label) {
            $bandX = self::PAD_LEFT + $i * $band;
            $barX = $bandX + ($band - $barW) / 2;
            $title = $titles[$i] ?? $label;
            $svg .= sprintf('<g class="chart-group stack-col" tabindex="0" role="img" aria-label="%s" data-tip="%s-tip-%d">', $e($title), $id, $i);
            $svg .= sprintf(
                '<rect class="chart-hit" x="%.1f" y="%d" width="%.1f" height="%d"><title>%s</title></rect>',
                $bandX,
                self::PAD_TOP,
                $band,
                $plotH,
                $e($title)
            );
            $svg .= self::column($series, $i, $barX, $barW, $baseline, $px);
            $svg .= '</g>';
        }

        $every = (int) max(1, ceil($count / self::MAX_LABELS));
        foreach ($labels as $i => $label) {
            if ($i % $every !== 0) {
                continue;
            }
            $svg .= sprintf(
                '<text class="chart-tick" x="%.1f" y="%d" text-anchor="middle">%s</text>',
                self::PAD_LEFT + ($i + 0.5) * $band,
                self::HEIGHT - 8,
                $e($label)
            );
        }

        return $svg . '</svg>';
    }

    /**
     * Díly jednoho sloupce odspodu nahoru. Mezi díly zůstane mezera GAP (po 1 px z každého),
     * díl, který by po odečtení mezer nebyl vidět, se nekreslí (hodnota zůstává v tooltipu a tabulce);
     * zaoblený vrchol pak dostane nejvyšší vykreslený díl.
     *
     * @param list<array{key: string, name: string, values: list<int>}> $series
     * @param callable(int): float $px
     */
    private static function column(array $series, int $i, float $x, float $w, float $baseline, callable $px): string
    {
        $parts = [];
        $running = 0;
        foreach ($series as $s) {
            $value = max(0, (int) ($s['values'][$i] ?? 0));
            if ($value === 0) {
                continue;
            }
            $parts[] = ['key' => self::slug($s['key']), 'bottom' => $baseline - $px($running), 'top' => $baseline - $px($running + $value)];
            $running += $value;
        }

        // Vyřazení dílu změní sousedy (mezery i vrchol), proto opakovat, dokud se nic nezmění.
        do {
            $geometry = self::withGaps($parts);
            $visible = array_values(array_filter($geometry, static fn (array $p): bool => $p['bottom'] - $p['top'] >= 0.5));
            $changed = count($visible) !== count($parts);
            $parts = array_map(static fn (array $p): array => $p['raw'], $visible);
        } while ($changed);

        $out = '';
        $last = count($geometry) - 1;
        foreach ($geometry as $j => $part) {
            $h = $part['bottom'] - $part['top'];
            $out .= $j === $last
                ? sprintf('<path class="fill-%s" d="%s"/>', $part['raw']['key'], self::roundedTop($x, $part['bottom'], $w, $h))
                : sprintf('<rect class="fill-%s" x="%.1f" y="%.1f" width="%.1f" height="%.1f"/>', $part['raw']['key'], $x, $part['top'], $w, $h);
        }

        return $out;
    }

    /**
     * @param list<array{key: string, bottom: float, top: float}> $parts
     * @return list<array{raw: array{key: string, bottom: float, top: float}, bottom: float, top: float}>
     */
    private static function withGaps(array $parts): array
    {
        $last = count($parts) - 1;

        return array_map(static fn (array $part, int $j): array => [
            'raw' => $part,
            'bottom' => $part['bottom'] - ($j > 0 ? self::GAP / 2 : 0),
            'top' => $part['top'] + ($j < $last ? self::GAP / 2 : 0),
        ], $parts, array_keys($parts));
    }

    /** Horní díl se zaobleným datovým koncem a rovnou spodní hranou. */
    private static function roundedTop(float $x, float $bottom, float $w, float $h): string
    {
        $r = min(self::RADIUS, $h, $w / 2);
        $top = $bottom - $h;

        return sprintf(
            'M%.1f %.1f V%.1f Q%.1f %.1f %.1f %.1f H%.1f Q%.1f %.1f %.1f %.1f V%.1f Z',
            $x, $bottom,
            $top + $r,
            $x, $top, $x + $r, $top,
            $x + $w - $r,
            $x + $w, $top, $x + $w, $top + $r,
            $bottom
        );
    }

    private static function slug(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9-]/', '', strtolower($value));
    }
}
