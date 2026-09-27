<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Serverově vykreslený skládaný plošný graf (SVG, bez inline stylů). Barvy nesou CSS třídy
 * podle klíče řady (fill-KEY, stroke-KEY, stop-KEY). Každý bod má sloupec pro hover/focus s <title>
 * a data-tip="ID" — app.js podle něj zobrazí bohatý tooltip, bez JS funguje nativní <title>.
 */
final class AreaChart
{
    private const WIDTH = 640;
    private const HEIGHT = 240;
    private const PAD_LEFT = 56;
    private const PAD_RIGHT = 14;
    private const PAD_TOP = 14;
    private const PAD_BOTTOM = 28;
    private const MAX_LABELS = 8;

    /**
     * @param list<string> $labels popisky osy X, jeden na bod
     * @param list<array{key: string, name: string, values: list<int>}> $series řady odspodu nahoru, hodnoty v haléřích
     * @param list<string> $titles textový popis bodu (tooltip bez JS)
     * @param string $id jedinečný prefix pro id gradientů a tooltipů
     */
    public static function render(array $labels, array $series, array $titles, string $ariaLabel, string $id): string
    {
        $count = count($labels);
        if ($count === 0 || $series === []) {
            return '';
        }
        $id = self::slug($id);
        $e = ChartScale::e(...);

        // Kumulované hodnoty pro skládání.
        $stack = [];
        $running = array_fill(0, $count, 0);
        foreach ($series as $s) {
            $bottom = $running;
            foreach ($running as $i => $value) {
                $running[$i] = $value + max(0, (int) ($s['values'][$i] ?? 0));
            }
            $stack[] = ['series' => $s, 'bottom' => $bottom, 'top' => $running];
        }
        [$axisMax, $step] = ChartScale::nice(max(0, ...$running));

        $plotW = self::WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $plotH = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;
        $baseline = self::PAD_TOP + $plotH;
        $x = static fn (int $i): float => $count === 1
            ? self::PAD_LEFT + $plotW / 2
            : self::PAD_LEFT + $i * $plotW / ($count - 1);
        $y = static fn (int $value): float => $baseline - ($axisMax > 0 ? $value / $axisMax * $plotH : 0);

        $svg = sprintf(
            '<svg class="chart chart-area" viewBox="0 0 %d %d" role="img" aria-label="%s" preserveAspectRatio="xMidYMid meet">',
            self::WIDTH,
            self::HEIGHT,
            $e($ariaLabel)
        );

        $svg .= '<defs>';
        foreach ($series as $s) {
            $key = self::slug($s['key']);
            $svg .= sprintf(
                '<linearGradient id="%s-%s" x1="0" y1="0" x2="0" y2="1"><stop offset="0" class="stop-%s" stop-opacity="0.55"/><stop offset="1" class="stop-%s" stop-opacity="0.06"/></linearGradient>',
                $id,
                $key,
                $key,
                $key
            );
        }
        $svg .= '</defs>';

        for ($tick = 0; $tick <= $axisMax; $tick += $step) {
            $ty = $y($tick);
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

        // Jediný bod se kreslí jako vodorovná plocha přes celou šířku.
        $xs = $count === 1 ? [self::PAD_LEFT, self::WIDTH - self::PAD_RIGHT] : array_map($x, range(0, $count - 1));
        $pick = static fn (array $values): array => $count === 1 ? [$values[0], $values[0]] : $values;
        foreach ($stack as $layer) {
            if (max($layer['series']['values'] === [] ? [0] : $layer['series']['values']) <= 0) {
                continue;
            }
            $key = self::slug($layer['series']['key']);
            $top = $pick($layer['top']);
            $bottom = $pick($layer['bottom']);
            $line = '';
            foreach ($xs as $i => $px) {
                $line .= sprintf('%s%.1f %.1f ', $i === 0 ? 'M' : 'L', $px, $y($top[$i]));
            }
            $area = $line;
            for ($i = count($xs) - 1; $i >= 0; $i--) {
                $area .= sprintf('L%.1f %.1f ', $xs[$i], $y($bottom[$i]));
            }
            $svg .= sprintf('<path class="area-fill" fill="url(#%s-%s)" d="%sZ"/>', $id, $key, $area);
            $svg .= sprintf('<path class="area-line stroke-%s" fill="none" d="%s"/>', $key, trim($line));
        }

        // Sloupce pro hover a klávesnici.
        $band = $count === 1 ? $plotW : $plotW / ($count - 1);
        foreach ($labels as $i => $label) {
            $px = $x($i);
            $left = max(self::PAD_LEFT, $px - $band / 2);
            $right = min(self::WIDTH - self::PAD_RIGHT, $px + $band / 2);
            $title = $titles[$i] ?? $label;
            $svg .= sprintf('<g class="area-col" tabindex="0" data-tip="%s-tip-%d" aria-label="%s">', $id, $i, $e($title));
            $svg .= sprintf(
                '<rect class="chart-hit" x="%.1f" y="%d" width="%.1f" height="%d"><title>%s</title></rect>',
                $left,
                self::PAD_TOP,
                max(1, $right - $left),
                $plotH,
                $e($title)
            );
            $svg .= sprintf('<line class="area-guide" x1="%.1f" x2="%.1f" y1="%d" y2="%.1f"/>', $px, $px, self::PAD_TOP, $baseline);
            foreach ($stack as $layer) {
                if (($layer['series']['values'][$i] ?? 0) > 0) {
                    $svg .= sprintf(
                        '<circle class="area-dot stroke-%s" cx="%.1f" cy="%.1f" r="4"/>',
                        self::slug($layer['series']['key']),
                        $px,
                        $y($layer['top'][$i])
                    );
                }
            }
            $svg .= '</g>';
        }

        $every = (int) max(1, ceil($count / self::MAX_LABELS));
        foreach ($labels as $i => $label) {
            if ($i % $every !== 0) {
                continue;
            }
            $anchor = $count === 1 ? 'middle' : ($i === 0 ? 'start' : 'middle');
            $svg .= sprintf(
                '<text class="chart-tick" x="%.1f" y="%d" text-anchor="%s">%s</text>',
                $x($i),
                self::HEIGHT - 8,
                $anchor,
                $e($label)
            );
        }

        return $svg . '</svg>';
    }

    private static function slug(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9-]/', '', strtolower($value));
    }
}
