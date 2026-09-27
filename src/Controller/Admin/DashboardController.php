<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Service\Period;
use App\Service\Stats;
use App\Support\AreaChart;
use App\Support\Clock;
use App\Support\ColumnChart;
use App\Support\DonutChart;
use App\Support\Labels;
use App\Support\Money;
use App\Support\ProgressRing;
use App\Support\Sparkline;
use App\Support\SplitBar;
use DateTimeImmutable;

final class DashboardController extends Controller
{
    private const MONTH_NAMES = ['led', 'úno', 'bře', 'dub', 'kvě', 'čvn', 'čvc', 'srp', 'zář', 'říj', 'lis', 'pro'];
    private const WEEKDAYS = ['ne', 'po', 'út', 'st', 'čt', 'pá', 'so'];
    /** Kolik výdělečných platforem má vlastní barvu; další sdílí neutrální „ostatní“. */
    private const PLATFORM_COLORS = 5;
    private const POLICY_TONE = ['allowed' => 'good', 'restricted' => 'warn', 'banned' => 'bad', 'unknown' => 'neutral'];

    public function index(Request $request): Response
    {
        $today = Clock::todayLocal();
        $currentMonth = substr($today, 0, 7);
        $period = Period::fromQuery($request->query('range'), $request->query('month'), $today);
        $previous = $period->previous();
        $stats = new Stats($this->app->db);
        $slots = $stats->platformSlots();
        $platformInfo = array_column($this->app->db->all('SELECT id, name, ai_policy FROM platforms'), null, 'id');

        $summary = $stats->summary($period);
        $prevSummary = $stats->summary($previous);
        $series = $stats->series($period);

        $month = $period->month;
        $prevMonth = $month !== null ? (new DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m') : null;
        $nextMonth = $month !== null && $month < $currentMonth
            ? (new DateTimeImmutable($month . '-01'))->modify('+1 month')->format('Y-m')
            : null;

        return $this->render('dashboard', [
            'title' => 'Přehled · ' . ($month ?? $period->label()),
            'period' => $period,
            'currentMonth' => $currentMonth,
            'prevMonth' => $prevMonth,
            'nextMonth' => $nextMonth,
            'summary' => $summary,
            'deltas' => [
                'gross' => self::growth($summary['gross'], $prevSummary['gross']),
                'net' => self::growth($summary['net'], $prevSummary['net']),
                'fees' => self::growth($summary['fees'], $prevSummary['fees']),
                'costs' => self::growth($summary['costs'], $prevSummary['costs']),
                'profit' => self::growth($summary['profit'], $prevSummary['profit']),
            ],
            'sparklines' => [
                'gross' => Sparkline::render(array_column($series, 'gross'), 'kpi-gross'),
                'fees' => Sparkline::render(array_column($series, 'fees'), 'kpi-fees'),
                'costs' => Sparkline::render(array_column($series, 'costs'), 'kpi-costs'),
                'profit' => Sparkline::render(array_map(static fn (array $b): int => $b['net'] - $b['costs'], $series), 'kpi-profit'),
            ],
            'revenue' => $this->revenueChart($period, $series, $slots, $platformInfo),
            'groups' => $this->groupTotals($stats->byTypeIn($period)),
            'platforms' => $this->platforms($stats->platformsIn($period), $slots),
            'modelCards' => $this->modelCards($stats, $period, $previous, $series, $slots, $platformInfo),
            'payments' => array_map(
                static fn (array $p): array => $p + ['key' => self::platformKey((int) $p['platform_id'], $slots)],
                $stats->largestPayments($period, 8)
            ),
            'goal' => $this->goal($stats, $month ?? $currentMonth, $currentMonth, $today),
            'accounts' => array_values(array_filter($stats->byAccountIn($period), static fn (array $a): bool => (int) $a['count'] > 0)),
            'topFans' => $stats->topFansIn($period, 8),
            'clicks' => $stats->clicksBySourceIn($period),
            'monthly' => $this->monthlyChart($stats->monthlySeries(12)),
            'syncErrors' => $this->app->db->all(
                "SELECT a.id, a.handle, a.last_sync_error, p.name AS platform FROM accounts a
                 JOIN platforms p ON p.id = a.platform_id WHERE a.last_sync_error IS NOT NULL"
            ),
            'modelCount' => (int) $this->app->db->scalar('SELECT COUNT(*) FROM models'),
        ]);
    }

    /** Relativní změna, null když srovnávací období nemá kladnou hodnotu (nelze spočítat). */
    public static function growth(int $current, int $previous): ?float
    {
        return $previous > 0 ? ($current - $previous) / $previous : null;
    }

    /** @param array<int, int> $slots */
    public static function platformKey(int $platformId, array $slots): string
    {
        $slot = $slots[$platformId] ?? 0;

        return 'p' . ($slot >= 1 && $slot <= self::PLATFORM_COLORS ? $slot : 0);
    }

    /**
     * Plošný graf čistých příjmů podle skupin typů plateb + data pro bohatý tooltip.
     *
     * @param list<array<string, mixed>> $series
     * @param array<int, int> $slots
     * @param array<int, array<string, mixed>> $platformInfo
     * @return array<string, mixed>
     */
    private function revenueChart(Period $period, array $series, array $slots, array $platformInfo): array
    {
        $labels = [];
        $titles = [];
        $points = [];
        foreach ($series as $bucket) {
            $date = new DateTimeImmutable($bucket['start']);
            $monthIndex = (int) $date->format('n') - 1;
            if ($period->bucketUnit() === 'month') {
                $labels[] = self::MONTH_NAMES[$monthIndex];
                $heading = Period::monthName($date->format('Y-m'));
            } else {
                $labels[] = $date->format('j. n.');
                $heading = self::WEEKDAYS[(int) $date->format('w')] . ' ' . $date->format('j. n. Y');
            }
            $groups = [];
            foreach ($bucket['groups'] as $group => $net) {
                $groups[] = ['key' => 'g-' . $group, 'label' => Labels::get('tx_group', $group), 'net' => $net];
            }
            arsort($bucket['platforms']);
            $platforms = [];
            foreach ($bucket['platforms'] as $platformId => $net) {
                $key = self::platformKey($platformId, $slots);
                $name = (string) ($platformInfo[$platformId]['name'] ?? '—');
                $platforms[] = [
                    'key' => $key,
                    'name' => $name,
                    'net' => $net,
                    'bar' => SplitBar::render([
                        ['key' => $key, 'value' => max(0, $net), 'title' => $name],
                        ['key' => 'rest', 'value' => max(0, $bucket['net'] - $net), 'title' => 'ostatní platformy'],
                    ], $name . ': podíl na čistých příjmech'),
                ];
            }
            $parts = array_map(
                static fn (array $g): string => $g['label'] . ' ' . Money::format($g['net'], 'CZK', false),
                array_filter($groups, static fn (array $g): bool => $g['net'] !== 0)
            );
            $titles[] = $heading . ' — čistě ' . Money::format($bucket['net'], 'CZK', false) . ($parts === [] ? '' : ' (' . implode(', ', $parts) . ')');
            $points[] = ['heading' => $heading, 'net' => $bucket['net'], 'gross' => $bucket['gross'], 'groups' => $groups, 'platforms' => $platforms];
        }

        $chartSeries = [];
        foreach (array_keys(Stats::TYPE_GROUPS) as $group) {
            $chartSeries[] = [
                'key' => 'g-' . $group,
                'name' => Labels::get('tx_group', $group),
                'values' => array_map(static fn (array $b): int => max(0, $b['groups'][$group]), $series),
            ];
        }

        return [
            'svg' => AreaChart::render($labels, $chartSeries, $titles, 'Čisté příjmy podle typu plateb, ' . $period->label(), 'rev'),
            'points' => $points,
        ];
    }

    /**
     * Součty podle skupin typů plateb (legenda grafu), všechny skupiny i s nulou.
     *
     * @param list<array<string, mixed>> $types
     * @return list<array{key: string, group: string, label: string, net: int, count: int}>
     */
    private function groupTotals(array $types): array
    {
        $totals = [];
        foreach (array_keys(Stats::TYPE_GROUPS) as $group) {
            $totals[$group] = ['key' => 'g-' . $group, 'group' => $group, 'label' => Labels::get('tx_group', $group), 'net' => 0, 'count' => 0];
        }
        foreach ($types as $type) {
            $group = Stats::typeGroup((string) $type['type']);
            $totals[$group]['net'] += (int) $type['net'];
            $totals[$group]['count'] += (int) $type['count'];
        }

        return array_values($totals);
    }

    /**
     * @param list<array{id: int, name: string, ai_policy: string, gross: int, net: int, count: int}> $rows
     * @param array<int, int> $slots
     * @return array<string, mixed>
     */
    private function platforms(array $rows, array $slots): array
    {
        $total = array_sum(array_column($rows, 'gross'));
        $items = [];
        $segments = [];
        foreach ($rows as $row) {
            $key = self::platformKey($row['id'], $slots);
            $share = $total > 0 ? $row['gross'] / $total : 0.0;
            $items[] = $row + [
                'key' => $key,
                'share' => $share,
                'fee_percent' => $row['gross'] > 0 ? ($row['gross'] - $row['net']) / $row['gross'] * 100 : 0.0,
                'policy_label' => Labels::get('ai_policy', $row['ai_policy']),
                'policy_tone' => self::POLICY_TONE[$row['ai_policy']] ?? 'neutral',
            ];
            $segments[] = [
                'key' => $key,
                'value' => $row['gross'],
                'title' => sprintf('%s: %s hrubě (%s %%)', $row['name'], Money::format($row['gross'], 'CZK', false), number_format($share * 100, 0, ',', ' ')),
            ];
        }

        return [
            'total' => $total,
            'items' => $items,
            'svg' => DonutChart::render($segments, 'Podíl platforem na hrubých tržbách'),
        ];
    }

    /**
     * Karty modelek: tržby, změna, průběh, rozpad podle platforem, nejziskovější typ plateb, zisk a ROI.
     *
     * @param list<array<string, mixed>> $series
     * @param array<int, int> $slots
     * @param array<int, array<string, mixed>> $platformInfo
     * @return list<array<string, mixed>>
     */
    private function modelCards(Stats $stats, Period $period, Period $previous, array $series, array $slots, array $platformInfo): array
    {
        $previousNet = array_column($stats->modelsIn($previous), 'net', 'id');
        $mix = $stats->modelMix($period);

        $cards = [];
        foreach ($stats->modelsIn($period) as $model) {
            $id = (int) $model['id'];
            $modelMix = $mix[$id] ?? ['platforms' => [], 'groups' => []];
            arsort($modelMix['platforms']);
            $platformGross = array_sum($modelMix['platforms']);
            $parts = [];
            $legend = [];
            $risky = false;
            foreach ($modelMix['platforms'] as $platformId => $gross) {
                $platform = $platformInfo[$platformId] ?? ['name' => '—', 'ai_policy' => 'unknown'];
                $key = self::platformKey($platformId, $slots);
                $share = $platformGross > 0 ? $gross / $platformGross : 0.0;
                $parts[] = ['key' => $key, 'value' => $gross, 'title' => sprintf('%s %s %%', $platform['name'], number_format($share * 100, 0, ',', ' '))];
                $legend[] = ['key' => $key, 'name' => $platform['name'], 'share' => $share];
                $risky = $risky || $platform['ai_policy'] === 'banned';
            }
            $topGroup = null;
            $groups = array_filter($modelMix['groups'], static fn (int $net): bool => $net > 0);
            if ($groups !== []) {
                arsort($groups);
                $group = (string) array_key_first($groups);
                $topGroup = [
                    'key' => 'g-' . $group,
                    'group' => $group,
                    'label' => Labels::get('tx_group', $group),
                    'share' => $model['net'] > 0 ? $groups[$group] / $model['net'] : null,
                ];
            }
            $cards[] = $model + [
                'delta' => self::growth($model['net'], (int) ($previousNet[$id] ?? 0)),
                'sparkline' => Sparkline::render(array_map(static fn (array $b): int => $b['models'][$id] ?? 0, $series), 'model'),
                'split' => $parts === [] ? '' : SplitBar::render($parts, 'Hrubé tržby modelky podle platforem'),
                'legend' => $legend,
                'risky' => $risky,
                'top_group' => $topGroup,
            ];
        }

        return $cards;
    }

    /** @return array<string, mixed> */
    private function goal(Stats $stats, string $month, string $currentMonth, string $today): array
    {
        $settings = $this->settings();
        $summary = $stats->monthSummary($month);
        $goal = $settings->monthlyGoalMinor();
        $basis = $settings->goalBasis();
        $isCurrent = $month === $currentMonth;
        $value = $basis === 'net' ? $summary['net'] : $summary['profit'];
        $projected = $basis === 'net' ? $summary['projected_net'] : $summary['projected_profit'];
        $fraction = $goal > 0 ? max(0, min(1, $value / $goal)) : 0.0;
        $day = (int) substr($today, 8, 2);
        $daysInMonth = (int) (new DateTimeImmutable($month . '-01'))->format('t');
        $remaining = $isCurrent ? $daysInMonth - $day + 1 : 0;

        return [
            'month' => $month,
            'monthName' => Period::monthName($month),
            'goal' => $goal,
            'basis' => $basis,
            'value' => $value,
            'projected' => $projected,
            'isCurrent' => $isCurrent,
            'percent' => $fraction * 100,
            'pace' => $isCurrent ? (int) round($value / max(1, $day)) : null,
            'needed' => $isCurrent && $remaining > 0 ? (int) ceil(max(0, $goal - $value) / $remaining) : null,
            'remainingDays' => $remaining,
            'onTrack' => $projected >= $goal,
            'ring' => ProgressRing::render($fraction, 'Splnění cíle', 'goal-ring'),
        ];
    }

    /**
     * Sloupcový graf posledních 12 měsíců (příjmy po poplatcích vs. náklady).
     *
     * @param list<array{month: string, net: int, costs: int}> $series
     * @return array{svg: string, series: list<array{month: string, net: int, costs: int}>}
     */
    private function monthlyChart(array $series): array
    {
        $categories = [];
        $tooltips = [];
        foreach ($series as $point) {
            $monthIndex = (int) substr($point['month'], 5, 2) - 1;
            $label = self::MONTH_NAMES[$monthIndex] . ' ' . substr($point['month'], 0, 4);
            // Osa X: krátký název měsíce, rok jen u ledna (plné datum je v tooltipu a tabulce).
            $categories[] = $monthIndex === 0 ? substr($point['month'], 0, 4) : self::MONTH_NAMES[$monthIndex];
            $tooltips[] = sprintf(
                '%s — příjmy %s, náklady %s, zisk %s',
                $label,
                Money::format($point['net'], 'CZK', false),
                Money::format($point['costs'], 'CZK', false),
                Money::format($point['net'] - $point['costs'], 'CZK', false)
            );
        }

        return [
            'svg' => ColumnChart::render($categories, [
                ['name' => 'Příjmy po poplatcích', 'class' => 'bar-series-1', 'values' => array_column($series, 'net')],
                ['name' => 'Náklady', 'class' => 'bar-series-2', 'values' => array_column($series, 'costs')],
            ], $tooltips, 'Příjmy a náklady za posledních 12 měsíců'),
            'series' => $series,
        ];
    }
}
