<?php

declare(strict_types=1);

use App\Controller\Admin\DashboardController;
use App\Service\Period;
use App\Service\Stats;
use App\Support\ChartScale;
use App\Support\Clock;
use App\Support\DonutChart;
use App\Support\Icon;
use App\Support\ProgressRing;
use App\Support\Sparkline;
use App\Support\SplitBar;
use App\Support\StackedColumnChart;

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

test('Period: YTD po týdnech, přestupný rok, konec dat nejvýš dnešek', function (): void {
    $ytd = Period::yearToDate('2028-02-29');
    assertSame(['2028-01-01', '2028-03-01', 'week'], [$ytd->from, $ytd->to, $ytd->bucketUnit()]);
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

test('Stats: časová řada po dnech (do dneška) a u YTD po týdnech', function (): void {
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

    assertSame(['2026-09-08', '2026-09-08'], [$eighth['start'], $eighth['end']], 'den: začátek = konec');

    $ytd = $stats->series(Period::yearToDate('2026-09-10'));
    assertSame(37, count($ytd), 'týdny od čt 1. 1. do čt 10. 9.');
    assertSame(['2026-01-01', '2026-01-04'], [$ytd[0]['start'], $ytd[0]['end']], 'první týden jen čt–ne');
    assertSame(['2026-01-05', '2026-01-11'], [$ytd[1]['start'], $ytd[1]['end']], 'celý týden po–ne');
    assertSame(['2026-01-12', 800], [$ytd[2]['start'], $ytd[2]['net']], 'čt 15. 1. patří do týdne od po 12. 1.');
    assertSame(['2026-09-07', '2026-09-10', 13600, 500], [$ytd[36]['start'], $ytd[36]['end'], $ytd[36]['net'], $ytd[36]['costs']], 'poslední týden do dneška, bez zítřejší platby');
    assertSame(14400, array_sum(array_column($ytd, 'net')), 'součet týdnů = součet plateb do dneška');
    assertSame([], $stats->series(Period::month('2026-12', '2026-09-10')), 'budoucí měsíc');
    assertSame('other', Stats::typeGroup('neznámý'));
});

/** Fanoušek na účtu, vrátí ID. */
function insertFan(App\Database\Database $db, int $accountId, string $name, ?string $displayName = null): int
{
    return $db->insert('fans', ['account_id' => $accountId, 'external_id' => 'manual:' . $name, 'handle' => $name, 'display_name' => $displayName, 'first_seen_at' => Clock::nowUtc()]);
}

test('Stats: Koho oslovit — horních 20 % podle útraty, 30+ dní bez platby', function (): void {
    $db = testDatabase();
    $account = seedAccount($db, 'CZK');
    $fans = [];
    // [jméno, čistě v haléřích, poslední platba]
    foreach ([['whale', 1_000_000, '2026-08-29'], ['active', 900_000, '2026-08-30'], ['sleeper', 800_000, '2026-05-01'],
              ['recent', 700_000, '2026-09-27'], ['gone', 600_000, '2026-06-01']] as [$name, $net, $last]) {
        $fans[$name] = insertFan($db, $account, $name, $name === 'whale' ? 'Velryba' : null);
        insertPayment($db, $account, '2026-01-10', 'tip', $net, $net - 1000, $fans[$name]);
        insertPayment($db, $account, $last, 'message', 1250, 1000, $fans[$name]);
    }
    for ($i = 1; $i <= 5; $i++) { // malí fanoušci, dávno bez platby — mimo horních 20 %
        insertPayment($db, $account, '2026-02-01', 'tip', 1000, 800, insertFan($db, $account, 'small' . $i));
    }
    $refund = insertFan($db, $account, 'refund');
    insertPayment($db, $account, '2026-03-01', 'tip', 5_000_000, 5_000_000, $refund);
    insertPayment($db, $account, '2026-03-02', 'other', -5_000_000, -5_000_000, $refund); // vrácené — čistě 0
    insertPayment($db, $account, '2026-10-05', 'tip', 9_000_000, 9_000_000, $fans['sleeper']); // budoucí datum se nepočítá
    insertPayment($db, $account, '2026-01-10', 'tip', 99_000_000, 99_000_000); // bez fanouška

    $stats = new Stats($db);
    $default = $stats->lapsedTopFans('2026-09-28');
    assertSame(['Velryba'], array_column($default, 'name'), 'z 10 platících jsou horních 20 % dva; aktivní vypadne');
    assertSame([30, '2026-08-29', 1_000_000, 2], [$default[0]['days'], $default[0]['last_payment'], $default[0]['net'], $default[0]['payments']], 'přesně 30 dní se počítá');

    $wider = $stats->lapsedTopFans('2026-09-28', 30, 0.5);
    assertSame(['Velryba', 'sleeper', 'gone'], array_column($wider, 'name'), 'podle útraty, jméno z handle');
    assertSame([150, 800_000], [$wider[1]['days'], $wider[1]['net']], 'budoucí platba nezvyšuje útratu ani neruší odmlčení');
    assertSame(119, $wider[2]['days']);
    assertSame(['Velryba', 'sleeper'], array_column($stats->lapsedTopFans('2026-09-28', 30, 0.5, 2), 'name'), 'limit');
    assertSame(['Velryba', 'active', 'sleeper', 'gone'], array_column($stats->lapsedTopFans('2026-09-28', 29, 0.5), 'name'), '29 dní už stačí při kratší hranici');
    assertSame([], (new Stats(testDatabase()))->lapsedTopFans('2026-09-28'), 'bez fanoušků');
    assertThrows(InvalidArgumentException::class, fn () => $stats->lapsedTopFans('2026-09-28', 0));
    assertThrows(InvalidArgumentException::class, fn () => $stats->lapsedTopFans('2026-09-28', 30, 1.5));
});

test('Stats: závislost na největších fanoušcích, platící fanoušci a průměr za období', function (): void {
    $db = testDatabase();
    $account = seedAccount($db, 'CZK');
    foreach ([['a', 600_000], ['b', 300_000], ['c', 100_000], ['d', 50_000]] as [$name, $net]) {
        insertPayment($db, $account, '2026-09-10', 'tip', $net, $net, insertFan($db, $account, $name));
    }
    $refund = insertFan($db, $account, 'e');
    insertPayment($db, $account, '2026-09-11', 'tip', 10_000, 10_000, $refund);
    insertPayment($db, $account, '2026-09-12', 'other', -30_000, -30_000, $refund); // čistě záporně — neplatící
    insertPayment($db, $account, '2026-09-13', 'subscription', 200_000, 200_000); // bez fanouška
    insertPayment($db, $account, '2026-08-31', 'tip', 999_000, 999_000, insertFan($db, $account, 'f')); // mimo období

    $stats = new Stats($db);
    $september = Period::month('2026-09', '2026-09-28');
    $concentration = $stats->fanConcentration($september);
    assertSame(['paying' => 4, 'fan_net' => 1_050_000, 'average' => 262_500, 'top_count' => 3, 'top_net' => 1_000_000], $concentration);
    assertSame(600_000, $stats->fanConcentration($september, 1)['top_net']);
    assertSame(['paying' => 0, 'fan_net' => 0, 'average' => null, 'top_count' => 0, 'top_net' => 0], $stats->fanConcentration(Period::month('2026-07', '2026-09-28')));

    $net = $stats->summary($september)['net'];
    assertSame(1_230_000, $net, 'čisté příjmy období včetně plateb bez fanouška a refundace');
    assertSame(round(1_000_000 / 1_230_000, 6), round((float) DashboardController::topShare(1_000_000, $net), 6));
    assertSame(null, DashboardController::topShare(0, 500), 'bez fanoušků nelze');
    assertSame(null, DashboardController::topShare(500, 0), 'bez příjmů nelze');
    assertSame(1.0, DashboardController::topShare(900, 600), 'víc než 100 % (refundace mimo fanoušky) se ořízne');
});

test('StackedColumnChart: skládané díly s mezerou, zaoblený vrchol, tooltip a klávesnice, bez inline stylů', function (): void {
    $svg = StackedColumnChart::render(['1. 9.', '<b>', '3. 9.'], [
        ['key' => 'g-subscription', 'name' => 'Předplatné', 'values' => [100000, 250000, 0]],
        ['key' => 'g-tip', 'name' => 'Spropitné', 'values' => [50000, 0, 0]],
        ['key' => 'g-ppv', 'name' => 'PPV', 'values' => [20000, 0, -300]],
    ], ['den "1"', 'den 2', 'den 3'], 'Graf <x>', 'rev');
    assertTrue(str_starts_with($svg, '<svg') && str_ends_with($svg, '</svg>'));
    assertSame(3, substr_count($svg, 'class="chart-group stack-col" tabindex="0"'), 'každý sloupec je cíl pro klávesnici');
    assertTrue(str_contains($svg, 'data-tip="rev-tip-0"') && str_contains($svg, 'data-tip="rev-tip-2"'));
    assertTrue(str_contains($svg, 'aria-label="den &quot;1&quot;"') && str_contains($svg, '<title>den &quot;1&quot;</title>'));
    assertTrue(str_contains($svg, '&lt;b&gt;') && !str_contains($svg, '<b>'), 'popisky escapované');
    assertTrue(str_contains($svg, 'aria-label="Graf &lt;x&gt;"'));
    assertTrue(!str_contains($svg, 'style='), 'CSP: žádné inline styly');
    assertTrue(str_contains($svg, '3 000') || str_contains($svg, "3\u{00A0}000"), 'kulatá osa');

    // 1. sloupec: předplatné (spodní díl) je obdélník, PPV nahoře má zaoblený vrchol (path), prázdný 3. sloupec nemá díly.
    preg_match_all('/<g class="chart-group stack-col".*?<\/g>/s', $svg, $columns);
    assertTrue(str_contains($columns[0][0], '<rect class="fill-g-subscription"') && str_contains($columns[0][0], '<path class="fill-g-ppv"'));
    assertTrue(str_contains($columns[0][1], '<path class="fill-g-subscription"'), 'jediný díl má zaoblený vrchol');
    assertTrue(!preg_match('/class="fill-/', $columns[0][2]), 'záporné ani nulové hodnoty se nekreslí');
    preg_match('/<rect class="fill-g-subscription" x="[\d.]+" y="([\d.]+)" width="([\d.]+)" height="([\d.]+)"/', $columns[0][0], $bottom);
    preg_match('/<rect class="fill-g-tip" x="[\d.]+" y="[\d.]+" width="[\d.]+" height="[\d.]+"/', $columns[0][0], $middle);
    assertTrue($bottom !== [] && $middle !== [], 'prostřední díl je obdélník');
    assertTrue((float) $bottom[2] <= 24.0, 'sloupec max. 24 px');
    preg_match('/fill-g-tip" x="[\d.]+" y="([\d.]+)" width="[\d.]+" height="([\d.]+)"/', $columns[0][0], $tipBox);
    assertTrue(abs(((float) $tipBox[1] + (float) $tipBox[2]) + 2 - (float) $bottom[1]) < 0.15, 'mezi díly 2px mezera');

    // Nepatrný horní díl (pod 1 px) se nekreslí a zaoblený vrchol dostane díl pod ním.
    $tiny = StackedColumnChart::render(['a', 'b'], [
        ['key' => 'g-tip', 'name' => 'T', 'values' => [100000, 250000]],
        ['key' => 'g-ppv', 'name' => 'P', 'values' => [300, 0]],
    ], [], 'x', 'tiny');
    preg_match('/<g class="chart-group stack-col".*?<\/g>/s', $tiny, $first);
    assertTrue(str_contains($first[0], '<path class="fill-g-tip"') && !str_contains($first[0], 'fill-g-ppv'), $first[0]);

    assertTrue(str_contains(StackedColumnChart::render(['1. 9.'], [['key' => 'a', 'name' => 'a', 'values' => [100]]], [], 'x', 'one'), 'one-tip-0'), 'jeden sloupec');
    assertSame('', StackedColumnChart::render([], [], [], 'x', 'x'));
    assertSame(31, substr_count(StackedColumnChart::render(array_fill(0, 31, 'd'), [['key' => 'a', 'name' => 'a', 'values' => array_fill(0, 31, 0)]], [], 'x', 'z'), 'stack-col'), 'bez plateb jen osy a cíle');
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

function viewHelpers(): App\Kernel\ViewHelpers
{
    $session = new App\Kernel\Session('test', 3600, 7200, '/');

    return new App\Kernel\ViewHelpers(new App\Kernel\UrlGenerator('https://studio.example.com', '/admin'), new App\Security\Csrf($session), $session, 'Test');
}

test('ViewHelpers::delta: nulová změna je neutrální, jinak barva podle směru a typu', function (): void {
    $v = viewHelpers();
    foreach ([[0.0, false], [0.0, true], [0.0004, true], [-0.0004, false], [-0.0004, true]] as [$change, $invert]) {
        $html = $v->delta($change, $invert);
        assertTrue(str_contains($html, 'delta-neutral'), "{$change} → neutrální: {$html}");
        assertTrue(str_contains($html, "0,0\u{00A0}%") && !str_contains($html, '<svg') && !str_contains($html, '−') && !str_contains($html, '+'), "bez šipky a znaménka: {$html}");
    }
    $up = $v->delta(0.123);
    assertTrue(str_contains($up, 'delta-good') && str_contains($up, "+12,3\u{00A0}%") && str_contains($up, '<svg'), $up);
    assertTrue(str_contains($v->delta(0.0005), 'delta-good') && str_contains($v->delta(0.0005), '+0,1'), 'po zaokrouhlení 0,1 % už je změna');
    assertTrue(str_contains($v->delta(-0.05), 'delta-bad') && str_contains($v->delta(-0.05), "−5,0\u{00A0}%"));
    assertTrue(str_contains($v->delta(0.05, true), 'delta-bad'), 'růst nákladů je špatně');
    assertTrue(str_contains($v->delta(-0.05, true), 'delta-good'), 'pokles nákladů je dobře');
    assertTrue(str_contains($v->delta(0.1, false, true), 'delta-neutral') && str_contains($v->delta(0.1, false, true), '<svg'), 'poplatky: bez hodnocení, se šipkou');
    assertTrue(str_contains($v->delta(null), 'delta-none'));
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
    $d = static fn (string $v): DateTimeImmutable => new DateTimeImmutable($v);
    assertSame("5.\u{2013}11. 1. 2026", DashboardController::dayRange($d('2026-01-05'), $d('2026-01-11')));
    assertSame("26. 1. \u{2013} 1. 2. 2026", DashboardController::dayRange($d('2026-01-26'), $d('2026-02-01')));
    assertSame('4. 1. 2026', DashboardController::dayRange($d('2026-01-04'), $d('2026-01-04')));
});
