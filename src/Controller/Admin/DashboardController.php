<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Service\Stats;
use App\Support\Clock;
use App\Support\ColumnChart;
use App\Support\Money;
use DateTimeImmutable;

final class DashboardController extends Controller
{
    private const MONTH_NAMES = ['led', 'úno', 'bře', 'dub', 'kvě', 'čvn', 'čvc', 'srp', 'zář', 'říj', 'lis', 'pro'];

    public function index(Request $request): Response
    {
        $month = $request->query('month');
        if (!Stats::isValidMonth($month)) {
            $month = substr(Clock::todayLocal(), 0, 7);
        }
        $stats = new Stats($this->app->db);
        $settings = $this->settings();
        $summary = $stats->monthSummary($month);
        $goal = $settings->monthlyGoalMinor();
        $basis = $settings->goalBasis();
        $isCurrentMonth = $month === substr(Clock::todayLocal(), 0, 7);
        $goalValue = $basis === 'net' ? $summary['net'] : $summary['profit'];
        $projected = $basis === 'net' ? $summary['projected_net'] : $summary['projected_profit'];

        $series = $stats->monthlySeries(12);
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
        $chart = ColumnChart::render($categories, [
            ['name' => 'Příjmy po poplatcích', 'class' => 'bar-series-1', 'values' => array_column($series, 'net')],
            ['name' => 'Náklady', 'class' => 'bar-series-2', 'values' => array_column($series, 'costs')],
        ], $tooltips, 'Příjmy a náklady za posledních 12 měsíců');

        $prev = (new DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m');
        $next = (new DateTimeImmutable($month . '-01'))->modify('+1 month')->format('Y-m');

        return $this->render('dashboard', [
            'title' => 'Přehled',
            'month' => $month,
            'prevMonth' => $prev,
            'nextMonth' => $isCurrentMonth ? null : $next,
            'isCurrentMonth' => $isCurrentMonth,
            'summary' => $summary,
            'goal' => $goal,
            'goalBasis' => $basis,
            'goalValue' => $goalValue,
            'projected' => $projected,
            'goalPercent' => $goal > 0 ? max(0, min(100, $goalValue / $goal * 100)) : 0,
            'models' => $stats->byModel($month),
            'sharedCosts' => $stats->sharedCosts($month),
            'accounts' => $stats->byAccount($month),
            'types' => $stats->byType($month),
            'topFans' => $stats->topFans($month, 10),
            'clicks' => $stats->clicksBySource($month),
            'series' => $series,
            'chart' => $chart,
            'syncErrors' => $this->app->db->all(
                "SELECT a.id, a.handle, a.last_sync_error, p.name AS platform FROM accounts a
                 JOIN platforms p ON p.id = a.platform_id WHERE a.last_sync_error IS NOT NULL"
            ),
            'modelCount' => (int) $this->app->db->scalar('SELECT COUNT(*) FROM models'),
        ]);
    }
}
