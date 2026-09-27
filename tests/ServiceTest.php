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
