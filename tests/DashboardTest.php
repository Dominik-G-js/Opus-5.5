<?php

declare(strict_types=1);

use App\Controller\Admin\DashboardController;
use App\Service\Period;
use App\Service\Stats;
use App\Support\AreaChart;
use App\Support\ChartScale;
use App\Support\Clock;
use App\Support\DonutChart;
use App\Support\Icon;
use App\Support\ProgressRing;
use App\Support\Sparkline;
use App\Support\SplitBar;

/** Platba přímo do DB (CZK, bez přepočtu), vrátí ID. */
function insertPayment(App\Database\Database $db, int $accountId, string $day, string $type, int $gross, int $net, ?int $fanId = null): int
{
    static $counter = 0;
    $counter++;

    return $db->insert('transactions', [
        'account_id' => $accountId, 'fan_id' => $fanId, 'occurred_at' => $day . ' 10:00:00', 'occurred_on' => $day, 'type' => $type,
        'gross_minor' => $gross, 'net_minor' => $net, 'currency' => 'CZK', 'fx_rate' => 1, 'gross_czk_minor' => $gross, 'net_czk_minor' => $net,
        'source' => 'manual', 'dedupe_key' => 'test-' . $counter, 'created_at' => Clock::nowUtc(),
    ]);
}

function insertCost(App\Database\Database $db, ?int $modelId, string $day, int $amount): void
{
    $db->insert('costs', ['model_id' => $modelId, 'category' => 'generation', 'incurred_on' => $day, 'amount_minor' => $amount,
        'currency' => 'CZK', 'fx_rate' => 1, 'amount_czk_minor' => $amount, 'created_at' => Clock::nowUtc()]);
}

test('Period: z URL — měsíc, neplatný měsíc, 7 dní má přednost', function (): void {
    $month = Period::fromQuery('', '', '2026-09-27');
    assertSame(['month', '2026-09-01', '2026-10-01', '2026-09'], [$month->kind, $month->from, $month->to, $month->month]);
    assertSame('září 2026', $month->label());
    assertSame(['month' => '2026-09'], $month->query());
    assertSame('2026-09', Period::fromQuery('', '2026-13', '2026-09-27')->month, 'neplatný měsíc → běžící');
    assertSame('2026-09', Period::fromQuery('', "2025-01' OR 1=1", '2026-09-27')->month);

    $week = Period::fromQuery('7d', '2020-01', '2026-09-27');
    assertSame(['7d', '2026-09-21', '2026-09-28', 7], [$week->kind, $week->from, $week->to, $week->days()]);
    assertSame(['2026-09-14', '2026-09-21'], [$week->previous()->from, $week->previous()->to]);
    assertSame(['range' => '7d'], $week->query());
    assertSame('posledních 30 dní', Period::fromQuery('30d', '', '2026-09-27')->label());
    assertThrows(InvalidArgumentException::class, fn () => Period::lastDays(0, '2026-09-27'));
    assertThrows(InvalidArgumentException::class, fn () => Period::month('2026-9', '2026-09-27'));
});

test('Period: běžící měsíc se srovnává se stejnými dny minulého měsíce', function (): void {
    $current = Period::month('2026-09', '2026-09-27');
    assertTrue($current->isCurrentMonth());
    assertSame(['2026-08-01', '2026-08-28'], [$current->previous()->from, $current->previous()->to]);
    assertSame('vs. stejné dny minulého měsíce', $current->compareLabel());
    // 31. 3. → únor má jen 28 dní, srovnává se celý únor.
    $march = Period::month('2026-03', '2026-03-31')->previous();
    assertSame(['2026-02-01', '2026-03-01'], [$march->from, $march->to]);

    $past = Period::month('2026-07', '2026-09-27');
    assertTrue(!$past->isCurrentMonth());
    assertSame(['2026-06-01', '2026-07-01'], [$past->previous()->from, $past->previous()->to]);
    assertSame('vs. předchozí měsíc', $past->compareLabel());
});

test('Period: YTD po měsících, přestupný rok, konec dat nejvýš dnešek', function (): void {
    $ytd = Period::yearToDate('2028-02-29');
    assertSame(['2028-01-01', '2028-03-01', 'month'], [$ytd->from, $ytd->to, $ytd->bucketUnit()]);
    assertSame(['2027-01-01', '2027-03-01'], [$ytd->previous()->from, $ytd->previous()->to], '29. 2. → 28. 2. loni');
    assertSame('od začátku roku 2028', $ytd->label());

    assertSame('2026-09-28', Period::month('2026-09', '2026-09-27')->dataEnd(), 'běžící měsíc končí dneškem');
    assertSame('2026-08-01', Period::month('2026-07', '2026-09-27')->dataEnd(), 'minulý měsíc celý');
    assertSame('2026-12-01', Period::month('2026-12', '2026-09-27')->dataEnd(), 'budoucí měsíc je prázdný');
    assertSame('day', Period::lastDays(30, '2026-09-27')->bucketUnit());
});

test('Stats: souhrn, rozpad modelek a platforem, nejvyšší platby za období', function (): void {
    $db = testDatabase();
    $fanvue = seedAccount($db, 'CZK');
    $luna = (int) $db->scalar('SELECT model_id FROM accounts WHERE id = :id', ['id' => $fanvue]);
    $patreon = $db->insert('accounts', ['model_id' => $luna, 'platform_id' => 4, 'handle' => 'luna', 'currency' => 'CZK', 'fee_percent' => 10, 'created_at' => Clock::nowUtc()]);
    $mia = seedAccount($db, 'CZK');
    $miaModel = (int) $db->scalar('SELECT model_id FROM accounts WHERE id = :id', ['id' => $mia]);
    $fan = $db->insert('fans', ['account_id' => $fanvue, 'external_id' => 'f1', 'handle' => 'whale', 'first_seen_at' => Clock::nowUtc()]);

    insertPayment($db, $fanvue, '2026-09-08', 'subscription', 10000, 8000, $fan);
    insertPayment($db, $fanvue, '2026-09-09', 'renewal', 5000, 4000);
    insertPayment($db, $fanvue, '2026-09-10', 'message', 30000, 24000, $fan);
    insertPayment($db, $patreon, '2026-09-10', 'tip', 20000, 18000);
    insertPayment($db, $mia, '2026-09-04', 'affiliate', 1000, 800);
    insertPayment($db, $fanvue, '2026-09-01', 'tip', 99900, 79920); // mimo 7 dní
    insertCost($db, $luna, '2026-09-10', 3000);
    insertCost($db, null, '2026-09-09', 2000);

    $stats = new Stats($db);
    $week = Period::lastDays(7, '2026-09-10');
    $summary = $stats->summary($week);
    assertSame([66000, 54800, 11200, 5, 5000, 2000, 49800], [
        $summary['gross'], $summary['net'], $summary['fees'], $summary['count'], $summary['costs'], $summary['shared_costs'], $summary['profit'],
    ]);

    $models = array_column($stats->modelsIn($week), null, 'id');
    assertSame(54000, $models[$luna]['net']);
    assertSame(65000, $models[$luna]['gross']);
    assertSame(3000, $models[$luna]['costs']);
    assertSame(4, $models[$luna]['payments']);
    assertSame(800, $models[$miaModel]['net']);

    $mix = $stats->modelMix($week);
    assertSame([1 => 45000, 4 => 20000], $mix[$luna]['platforms'] + [], 'hrubě podle platforem');
    assertSame(12000, $mix[$luna]['groups']['subscription'], 'předplatné + obnovení');
    assertSame(24000, $mix[$luna]['groups']['ppv']);
    assertSame(18000, $mix[$luna]['groups']['tip']);
    assertSame(800, $mix[$miaModel]['groups']['other']);

    $platforms = $stats->platformsIn($week);
    assertSame(['Fanvue', 'Patreon'], array_column($platforms, 'name'));
    assertSame('allowed', $platforms[0]['ai_policy']);
    assertSame([1 => 1, 2 => 2, 3 => 3, 4 => 4], $stats->platformSlots(), 'výdělečné platformy podle ID');

    $payments = $stats->largestPayments($week, 3);
    assertSame([30000, 20000, 10000], array_map('intval', array_column($payments, 'gross')));
    assertSame('whale', $payments[0]['fan_handle']);
    assertSame(null, $payments[1]['fan_id'], 'platba bez fanouška zůstane');
    assertSame('whale', $stats->topFansIn($week)[0]['handle']);
});

test('Stats: časová řada po dnech (do dneška) a u YTD po měsících', function (): void {
    $db = testDatabase();
    $account = seedAccount($db, 'CZK');
    $model = (int) $db->scalar('SELECT model_id FROM accounts WHERE id = :id', ['id' => $account]);
    insertPayment($db, $account, '2026-09-08', 'subscription', 10000, 8000);
    insertPayment($db, $account, '2026-09-08', 'post', 5000, 4000);
    insertPayment($db, $account, '2026-09-10', 'tip', 2000, 1600);
    insertPayment($db, $account, '2026-01-15', 'tip', 1000, 800);
    insertPayment($db, $account, '2026-09-11', 'tip', 7000, 5600); // zítřek se nekreslí
    insertCost($db, $model, '2026-09-08', 500);

    $stats = new Stats($db);
    $series = $stats->series(Period::month('2026-09', '2026-09-10'));
    assertSame(10, count($series), '1.–10. září');
    $eighth = $series[7];
    assertSame(['2026-09-08', 15000, 12000, 3000, 500], [$eighth['start'], $eighth['gross'], $eighth['net'], $eighth['fees'], $eighth['costs']]);
    assertSame(8000, $eighth['groups']['subscription']);
    assertSame(4000, $eighth['groups']['ppv'], 'placený příspěvek patří do PPV');
    assertSame([1 => 12000], $eighth['platforms']);
    assertSame([$model => 12000], $eighth['models']);
    assertSame(0, $series[0]['net']);

    $ytd = $stats->series(Period::yearToDate('2026-09-10'));
    assertSame(9, count($ytd), 'leden–září');
    assertSame('2026-01-01', $ytd[0]['start']);
    assertSame(800, $ytd[0]['net']);
    assertSame(13600, $ytd[8]['net'], 'září bez zítřejší platby');
    assertSame([], $stats->series(Period::month('2026-12', '2026-09-10')), 'budoucí měsíc');
    assertSame('other', Stats::typeGroup('neznámý'));
});

test('AreaChart: skládané plochy, tooltip ID, escapované popisky, bez inline stylů', function (): void {
    $svg = AreaChart::render(['1. 9.', '<b>'], [
        ['key' => 'g-tip', 'name' => 'Spropitné', 'values' => [10000, 250000]],
        ['key' => 'g-ppv', 'name' => 'PPV', 'values' => [5000, 0]],
    ], ['den "1"', 'den 2'], 'Graf <x>', 'rev');
    assertTrue(str_starts_with($svg, '<svg') && str_ends_with($svg, '</svg>'));
    assertTrue(str_contains($svg, 'data-tip="rev-tip-1"'));
    assertTrue(str_contains($svg, 'url(#rev-g-tip)') && str_contains($svg, 'class="stop-g-ppv"'));
    assertTrue(str_contains($svg, '&lt;b&gt;') && !str_contains($svg, '<b>'), 'popisky escapované');
    assertTrue(str_contains($svg, 'den &quot;1&quot;'));
    assertTrue(str_contains($svg, 'aria-label="Graf &lt;x&gt;"'));
    assertTrue(!str_contains($svg, 'style='), 'CSP: žádné inline styly');
    assertTrue(str_contains($svg, '3 000') || str_contains($svg, "3\u{00A0}000"), 'kulatá osa');
    assertTrue(str_contains(AreaChart::render(['1. 9.'], [['key' => 'a', 'name' => 'a', 'values' => [100]]], [], 'x', 'one'), 'one-tip-0'), 'jeden bod');
    assertSame('', AreaChart::render([], [], [], 'x', 'x'));
});

test('Donut, SplitBar, Sparkline, ProgressRing: prázdná data, escapování, bez inline stylů', function (): void {
    $donut = DonutChart::render([
        ['key' => 'p1', 'value' => 7500, 'title' => 'Fanvue <75 %>'],
        ['key' => 'p2', 'value' => 2500, 'title' => 'Patreon'],
        ['key' => 'p3', 'value' => 0, 'title' => 'nula'],
    ], 'Podíl');
    assertSame(2, substr_count($donut, 'class="donut-seg'));
    assertTrue(str_contains($donut, 'Fanvue &lt;75 %&gt;') && !str_contains($donut, 'nula'));
    assertSame(0, substr_count(DonutChart::render([], 'x'), 'donut-seg'));

    $split = SplitBar::render([['key' => 'p1', 'value' => 3, 'title' => 'a'], ['key' => 'p2', 'value' => 1, 'title' => 'b']], 'Podíl');
    assertSame(2, substr_count($split, '<rect class="fill-p'));
    assertTrue(str_contains(SplitBar::render([], 'x'), 'splitbar-track'));

    $spark = Sparkline::render([5, 5, 5], 'kpi-gross');
    assertTrue(str_contains($spark, 'aria-hidden="true"') && !str_contains($spark, 'NAN') && !str_contains($spark, 'INF'));
    assertTrue(str_contains(Sparkline::render([], 'x'), '<path'), 'bez dat plochá čára');
    assertTrue(str_contains(Sparkline::render([-300, 200], 'kpi-profit'), 'stroke-kpi-profit'));

    assertTrue(str_contains(ProgressRing::render(2.5, 'Cíl', 'goal'), 'ring-value'), 'přes 100 % se ořízne');
    assertTrue(!str_contains(ProgressRing::render(0, 'Cíl', 'goal'), 'ring-value'));
    foreach ([$donut, $split, $spark, ProgressRing::render(.5, 'Cíl', 'goal')] as $svg) {
        assertTrue(!str_contains($svg, 'style='), 'CSP: žádné inline styly');
    }
    assertSame('150 tis.', ChartScale::compact(15000000));
    assertSame([400000, 100000], ChartScale::nice(350000));
});

test('Icon a přehled: neznámá ikona, změna oproti minulému období, barva platformy', function (): void {
    assertTrue(str_contains(Icon::svg('wallet'), 'aria-hidden="true"'));
    assertThrows(InvalidArgumentException::class, fn () => Icon::svg('neexistuje'));
    assertSame(0.1, DashboardController::growth(110, 100));
    assertSame(null, DashboardController::growth(50, 0), 'bez srovnání');
    assertSame(null, DashboardController::growth(50, -100), 'záporný zisk nelze srovnat');
    assertSame('p2', DashboardController::platformKey(4, [1 => 1, 4 => 2]));
    assertSame('p0', DashboardController::platformKey(9, [9 => 6]), 'šestá a další platforma sdílí neutrální barvu');
    assertSame('p0', DashboardController::platformKey(5, [1 => 1]), 'platforma pro návštěvnost');
});
