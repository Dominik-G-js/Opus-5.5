<?php

declare(strict_types=1);

use App\Http\HttpResponse;
use App\Service\CsvImporter;
use App\Service\ExchangeRates;
use App\Service\ExchangeRateUnavailable;
use App\Service\Ledger;
use App\Service\LinkTracker;
use App\Service\Stats;
use App\Support\Clock;

/** Vytvoří modelku a účet, vrátí ID účtu. */
function seedAccount(App\Database\Database $db, string $currency = 'USD', float $fee = 20): int
{
    $now = Clock::nowUtc();
    $modelId = $db->insert('models', ['name' => 'Test', 'slug' => 'test-' . bin2hex(random_bytes(3)), 'persona_age' => 24, 'created_at' => $now, 'updated_at' => $now]);

    return $db->insert('accounts', ['model_id' => $modelId, 'platform_id' => 1, 'handle' => 'test', 'currency' => $currency, 'fee_percent' => $fee, 'created_at' => $now]);
}

function cnbMonth(array $rates): HttpResponse
{
    return FakeHttpClient::json(['rates' => array_map(
        static fn (string $date, float $rate): array => ['validFor' => $date, 'order' => 1, 'amount' => 1, 'currencyCode' => 'USD', 'rate' => $rate],
        array_keys($rates),
        array_values($rates)
    )]);
}

test('ExchangeRates: měsíc z ČNB, víkend = poslední pracovní den, cache', function (): void {
    $db = testDatabase();
    $http = new FakeHttpClient([
        cnbMonth(['2025-03-03' => 23.0, '2025-03-06' => 23.5, '2025-03-07' => 23.4]),
        cnbMonth(['2025-02-27' => 24.0, '2025-02-28' => 24.1]),
    ]);
    $rates = new ExchangeRates($db, $http);
    assertSame(23.4, $rates->czkPerUnit('USD', '2025-03-09'), 'neděle → pátek');
    assertSame(24.1, $rates->czkPerUnit('USD', '2025-03-01'), '1. 3. je sobota → kurz z února');
    assertSame(23.0, $rates->czkPerUnit('USD', '2025-03-04'));
    assertSame(2, count($http->requests), 'uzavřené měsíce se stahují jen jednou');
    assertTrue(str_contains($http->requests[0]['url'], 'currency=USD&yearMonth=2025-03'));
    assertSame(1.0, $rates->czkPerUnit('CZK', '2025-03-04'));
});

test('ExchangeRates: nedostupné ČNB vyhodí srozumitelnou chybu', function (): void {
    $db = testDatabase();
    $rates = new ExchangeRates($db, new FakeHttpClient([new HttpResponse(503, [], 'down')]));
    assertThrows(ExchangeRateUnavailable::class, fn () => $rates->czkPerUnit('EUR', '2025-05-05'));
});

test('Ledger: přepočet do CZK, deduplikace a fanoušek', function (): void {
    $db = testDatabase();
    $accountId = seedAccount($db);
    $ledger = new Ledger($db, new ExchangeRates($db, new FakeHttpClient([])));
    $row = $ledger->prepareTransaction($accountId, [
        'occurred_at' => new DateTimeImmutable('2025-06-30 23:30:00', new DateTimeZone('UTC')),
        'type' => 'tip', 'gross_minor' => 1000, 'net_minor' => 800, 'currency' => 'USD',
        'source' => 'csv', 'dedupe_key' => 'k1', 'fx_rate' => 22.5,
    ]);
    assertSame('2025-07-01', $row['occurred_on'], 'lokální datum (Praha) pro měsíční přehledy');
    assertSame(18000, $row['net_czk_minor']);
    assertTrue($ledger->insertTransaction($row) !== null);
    assertSame(null, $ledger->insertTransaction($row), 'duplikát se nevloží');
    $fan = $ledger->upsertFan($accountId, 'uuid-1', 'fan1', null);
    assertSame($fan, $ledger->upsertFan($accountId, 'uuid-1', null, 'Fan One', true));
    $stored = $db->one('SELECT * FROM fans WHERE id = :id', ['id' => $fan]);
    assertSame('fan1', $stored['handle']);
    assertSame('Fan One', $stored['display_name']);
    assertSame(1, (int) $stored['is_top_spender']);
    assertThrows(InvalidArgumentException::class, fn () => $ledger->prepareTransaction($accountId, [
        'occurred_at' => new DateTimeImmutable(), 'type' => 'hack', 'gross_minor' => 1, 'net_minor' => 1,
        'currency' => 'USD', 'source' => 'manual', 'fx_rate' => 1.0,
    ]));
});

test('CsvImporter: mapování, dopočet čisté částky, chyby řádků a opakovaný import', function (): void {
    $db = testDatabase();
    $accountId = seedAccount($db, 'CZK', 20);
    $ledger = new Ledger($db, new ExchangeRates($db, new FakeHttpClient([])));
    $importer = new CsvImporter($ledger);
    $file = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($file, "\xEF\xBB\xBFDatum;Popis;Částka;Uživatel\n02.09.2026 10:00;Recurring subscription;100,00;joe\n02.09.2026 10:00;Recurring subscription;100,00;joe\n03.09.2026;Tip from fan;50;anna\nxx;Tip;1;z\n");
    $options = ['account_id' => $accountId, 'currency' => 'CZK', 'fee_percent' => 20.0, 'timezone' => 'Europe/Prague'];
    $mapping = ['date' => 0, 'type' => 1, 'gross' => 2, 'fan' => 3];
    $result = $importer->prepare($file, $mapping, $options);
    assertSame(3, count($result['rows']));
    assertSame(1, count($result['errors']));
    assertTrue(str_contains($result['errors'][0], 'Řádek 5'));
    assertSame('renewal', $result['rows'][0]['row']['type']);
    assertSame('tip', $result['rows'][2]['row']['type']);
    assertSame(8000, $result['rows'][0]['row']['net_minor'], 'čistá = hrubá − 20 %');
    assertTrue($result['rows'][0]['row']['dedupe_key'] !== $result['rows'][1]['row']['dedupe_key'], 'dvě stejné platby nejsou duplikát');
    foreach ($result['rows'] as $entry) {
        $ledger->insertTransaction($entry['row']);
    }
    $again = $importer->prepare($file, $mapping, $options);
    $inserted = 0;
    foreach ($again['rows'] as $entry) {
        $inserted += $ledger->insertTransaction($entry['row']) !== null ? 1 : 0;
    }
    assertSame(0, $inserted, 'opakovaný import nic nepřidá');
    assertThrows(InvalidArgumentException::class, fn () => $importer->prepare($file, ['type' => 1], $options));
    unlink($file);
});

test('LinkTracker: počítá lidi, ignoruje boty, kontroluje modelku', function (): void {
    $db = testDatabase();
    $accountId = seedAccount($db);
    $modelId = (int) $db->scalar('SELECT model_id FROM accounts WHERE id = :id', ['id' => $accountId]);
    $db->insert('links', ['model_id' => $modelId, 'code' => 'abc123', 'label' => 'TT', 'source' => 'tiktok', 'target_url' => 'https://example.com', 'created_at' => Clock::nowUtc()]);
    $tracker = new LinkTracker($db);
    assertSame('https://example.com', $tracker->resolveAndCount('abc123', 'Mozilla/5.0 (iPhone)'));
    assertSame('https://example.com', $tracker->resolveAndCount('abc123', 'Googlebot/2.1'));
    assertSame(null, $tracker->resolveAndCount('abc123', 'Mozilla', $modelId + 1), 'cizí doména nesmí přesměrovat odkaz jiné modelky');
    assertSame(null, $tracker->resolveAndCount('nope', 'Mozilla'));
    assertSame(1, (int) $db->scalar('SELECT SUM(clicks) FROM link_clicks_daily'));
});

test('Stats: měsíční souhrn, zisk a top fanoušci', function (): void {
    $db = testDatabase();
    $accountId = seedAccount($db, 'CZK');
    $ledger = new Ledger($db, new ExchangeRates($db, new FakeHttpClient([])));
    $fan = $ledger->upsertFan($accountId, 'f1', 'whale', null);
    foreach ([[50000, 40000, $fan], [10000, 8000, null]] as [$gross, $net, $fanId]) {
        $row = $ledger->prepareTransaction($accountId, [
            'occurred_at' => new DateTimeImmutable('2025-04-10 12:00', Clock::localZone()), 'type' => 'message',
            'gross_minor' => $gross, 'net_minor' => $net, 'currency' => 'CZK', 'source' => 'manual', 'fan_id' => $fanId,
        ]);
        $ledger->insertTransaction($row);
    }
    $db->insert('costs', ['model_id' => null, 'category' => 'hosting', 'incurred_on' => '2025-04-01', 'amount_minor' => 10000, 'currency' => 'CZK', 'fx_rate' => 1, 'amount_czk_minor' => 10000, 'created_at' => Clock::nowUtc()]);
    $summary = (new Stats($db))->monthSummary('2025-04');
    assertSame(60000, $summary['gross']);
    assertSame(48000, $summary['net']);
    assertSame(12000, $summary['fees']);
    assertSame(38000, $summary['profit']);
    $top = (new Stats($db))->topFans('2025-04');
    assertSame('whale', $top[0]['handle']);
    assertSame(40000, (int) $top[0]['net']);
    assertSame(12, count((new Stats($db))->monthlySeries(12)));
    assertTrue(Stats::isValidMonth('2025-04') && !Stats::isValidMonth('2025-13'));
});

test('ExchangeRates: měsíc stažený v průběhu se po jeho konci doplní', function (): void {
    $db = testDatabase();
    // Březen 2025 byl stažen 15. 3. (běžící měsíc) → obsahuje jen kurzy do 14. 3.
    $db->insert('exchange_rates', ['currency' => 'USD', 'rate_date' => '2025-03-14', 'czk_per_unit' => 23.0]);
    $db->insert('exchange_rate_months', ['currency' => 'USD', 'year_month' => '2025-03', 'fetched_at' => '2025-03-15 10:00:00']);
    $db->insert('exchange_rate_months', ['currency' => 'USD', 'year_month' => '2025-02', 'fetched_at' => '2025-03-15 10:00:00']);
    $http = new FakeHttpClient([cnbMonth(['2025-03-14' => 23.0, '2025-03-28' => 22.1])]);
    $rates = new ExchangeRates($db, $http);
    assertSame(22.1, $rates->czkPerUnit('USD', '2025-03-30'), 'po konci měsíce se stáhne celý');
    assertSame(1, count($http->requests));
    assertSame(22.1, $rates->czkPerUnit('USD', '2025-03-31'));
    assertSame(1, count($http->requests), 'kompletní měsíc se už znovu nestahuje');
});

test('ExchangeRates: při výpadku ČNB se použije uložený kurz', function (): void {
    $db = testDatabase();
    $month = substr(Clock::todayLocal(), 0, 7);
    $yesterday = (new DateTimeImmutable(Clock::todayLocal()))->modify('-1 day')->format('Y-m-d');
    $db->insert('exchange_rates', ['currency' => 'EUR', 'rate_date' => $month . '-01', 'czk_per_unit' => 24.5]);
    $db->insert('exchange_rate_months', ['currency' => 'EUR', 'year_month' => $month, 'fetched_at' => '2000-01-01 00:00:00']);
    $previous = (new DateTimeImmutable(Clock::todayLocal()))->modify('-7 days')->format('Y-m');
    if ($previous !== $month) {
        $db->insert('exchange_rate_months', ['currency' => 'EUR', 'year_month' => $previous, 'fetched_at' => '2099-01-01 00:00:00']);
    }
    $http = new FakeHttpClient([new HttpResponse(503, [], 'down'), new HttpResponse(503, [], 'down')]);
    $rates = new ExchangeRates($db, $http);
    assertSame(24.5, $rates->czkPerUnit('EUR', max($month . '-01', $yesterday)));
    $requests = count($http->requests);
    assertSame(24.5, $rates->czkPerUnit('EUR', max($month . '-01', $yesterday)));
    assertSame($requests, count($http->requests), 'neúspěšné stažení se v jednom běhu neopakuje');
});

test('CsvImporter: soubor ve Windows-1250 (český Excel) se převede celý', function (): void {
    $db = testDatabase();
    $accountId = seedAccount($db, 'CZK', 20);
    $importer = new CsvImporter(new Ledger($db, new ExchangeRates($db, new FakeHttpClient([]))));
    $file = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($file, (string) iconv('UTF-8', 'Windows-1250', "Datum;Typ;Částka;Fanoušek\n2026-09-02;Předplatné;100;Novák\n"));
    $data = $importer->read($file);
    assertSame(['Datum', 'Typ', 'Částka', 'Fanoušek'], $data['headers']);
    assertSame('Novák', $data['rows'][0][3]);
    $result = $importer->prepare($file, ['date' => 0, 'type' => 1, 'gross' => 2, 'fan' => 3], ['account_id' => $accountId, 'currency' => 'CZK', 'fee_percent' => 20.0, 'timezone' => 'UTC']);
    assertSame('subscription', $result['rows'][0]['row']['type'], 'typ „Předplatné“ rozpoznán');
    assertSame('Novák', $result['rows'][0]['fan']);
    unlink($file);
});

test('Stats: cena za obrázek jen z nákladů s počtem kusů', function (): void {
    $db = testDatabase();
    $accountId = seedAccount($db);
    $modelId = (int) $db->scalar('SELECT model_id FROM accounts WHERE id = :id', ['id' => $accountId]);
    foreach ([[50000, 100], [100000, null]] as [$amount, $quantity]) {
        $db->insert('costs', ['model_id' => $modelId, 'category' => 'generation', 'incurred_on' => '2025-04-01', 'amount_minor' => $amount,
            'currency' => 'CZK', 'fx_rate' => 1, 'amount_czk_minor' => $amount, 'quantity' => $quantity, 'created_at' => Clock::nowUtc()]);
    }
    $lifetime = (new Stats($db))->modelLifetime($modelId);
    assertSame(500, $lifetime['cost_per_image'], '500 Kč / 100 ks = 5 Kč');
    assertSame(150000, $lifetime['costs']);
});
