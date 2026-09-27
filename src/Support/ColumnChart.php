<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Serverově vykreslený seskupený sloupcový graf (SVG, bez JS).
 * Specifikace: sloupce max. 24 px, zaoblený jen datový konec (4 px), 2px mezera mezi sousedními sloupci,
 * jedna osa Y s „kulatými“ hodnotami, vlasové mřížky. Hover/focus: celé pásmo měsíce nese <title>.
 */
final class ColumnChart
{
    private const WIDTH = 480;
    private const HEIGHT = 220;
    private const PAD_LEFT = 48;
    private const PAD_RIGHT = 8;
    private const PAD_TOP = 12;
    private const PAD_BOTTOM = 26;
    private const BAR_MAX = 24;
    private const GAP = 2;
    private const RADIUS = 4;

    /**
     * @param list<string> $categories popisky osy X
     * @param list<array{name: string, class: string, values: list<int>}> $series hodnoty v haléřích
     * @param list<string> $tooltips jeden text na kategorii
     */
    public static function render(array $categories, array $series, array $tooltips, string $ariaLabel): string
    {
        $count = count($categories);
        if ($count === 0 || $series === []) {
            return '';
        }
        $max = 0;
        foreach ($series as $s) {
            $max = max($max, ...$s['values']);
        }
        [$axisMax, $step] = ChartScale::nice($max);

        $plotW = self::WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $plotH = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;
        $baseline = self::PAD_TOP + $plotH;
        $band = $plotW / $count;
        $seriesCount = count($series);
        $barW = min(self::BAR_MAX, ($band * 0.7 - self::GAP * ($seriesCount - 1)) / $seriesCount);
        $groupW = $barW * $seriesCount + self::GAP * ($seriesCount - 1);

        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $svg = sprintf(
            '<svg class="chart" viewBox="0 0 %d %d" role="img" aria-label="%s" preserveAspectRatio="xMidYMid meet">',
            self::WIDTH,
            self::HEIGHT,
            $e($ariaLabel)
        );

        for ($tick = 0; $tick <= $axisMax; $tick += $step) {
            $y = $baseline - ($axisMax > 0 ? $tick / $axisMax * $plotH : 0);
            $class = $tick === 0 ? 'chart-baseline' : 'chart-grid';
            $svg .= sprintf('<line class="%s" x1="%d" x2="%d" y1="%.1f" y2="%.1f"/>', $class, self::PAD_LEFT, self::WIDTH - self::PAD_RIGHT, $y, $y);
            $svg .= sprintf(
                '<text class="chart-tick" x="%d" y="%.1f" text-anchor="end" dominant-baseline="middle">%s</text>',
                self::PAD_LEFT - 6,
                $y,
                $e(ChartScale::compact($tick))
            );
        }

        foreach ($categories as $i => $label) {
            $bandX = self::PAD_LEFT + $i * $band;
            $groupX = $bandX + ($band - $groupW) / 2;
            $svg .= '<g class="chart-group" tabindex="0">';
            $svg .= sprintf(
                '<rect class="chart-hit" x="%.1f" y="%d" width="%.1f" height="%d"><title>%s</title></rect>',
                $bandX,
                self::PAD_TOP,
                $band,
                $plotH,
                $e($tooltips[$i] ?? $label)
            );
            foreach ($series as $j => $s) {
                $value = max(0, $s['values'][$i] ?? 0);
                $h = $axisMax > 0 ? $value / $axisMax * $plotH : 0;
                if ($h < 0.5) {
                    continue;
                }
                $x = $groupX + $j * ($barW + self::GAP);
                $svg .= sprintf('<path class="%s" d="%s"/>', $e($s['class']), self::roundedTopBar($x, $baseline, $barW, $h));
            }
            $svg .= '</g>';
            $svg .= sprintf(
                '<text class="chart-tick" x="%.1f" y="%d" text-anchor="middle">%s</text>',
                $bandX + $band / 2,
                self::HEIGHT - 7,
                $e($label)
            );
        }

        return $svg . '</svg>';
    }

    /** Sloupec se zaobleným horním koncem a rovnou základnou. */
    private static function roundedTopBar(float $x, float $baseline, float $w, float $h): string
    {
        $r = min(self::RADIUS, $h, $w / 2);
        $top = $baseline - $h;

        return sprintf(
            'M%.1f %.1f V%.1f Q%.1f %.1f %.1f %.1f H%.1f Q%.1f %.1f %.1f %.1f V%.1f Z',
            $x, $baseline,
            $top + $r,
            $x, $top, $x + $r, $top,
            $x + $w - $r,
            $x + $w, $top, $x + $w, $top + $r,
            $baseline
        );
    }
}
