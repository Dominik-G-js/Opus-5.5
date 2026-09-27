<?php

declare(strict_types=1);

use App\Http\HttpResponse;
use App\Integration\Fanvue\FanvueClient;
use App\Integration\Fanvue\FanvueException;
use App\Integration\Fanvue\FanvueSync;
use App\Integration\Fanvue\TokenSet;
use App\Security\Crypto;
use App\Service\ExchangeRates;
use App\Service\Ledger;
use App\Support\Logger;

function fanvueEarning(string $date, int $gross, int $net, string $source, ?string $fan): array
{
    return [
        'date' => $date, 'gross' => $gross, 'net' => $net, 'source' => $source,
        'user' => $fan === null ? null : ['uuid' => $fan, 'handle' => $fan . '-h', 'displayName' => strtoupper($fan), 'nickname' => null, 'isTopSpender' => $fan === 'whale'],
    ];
}

test('FanvueClient: PKCE a autorizační URL', function (): void {
    $pkce = FanvueClient::createPkce();
    assertSame(43, strlen($pkce['verifier']));
    $expected = rtrim(strtr(base64_encode(hash('sha256', $pkce['verifier'], true)), '+/', '-_'), '=');
    assertSame($expected, $pkce['challenge']);
    $url = (new FanvueClient(new FakeHttpClient([]), 'cid', 'secret'))->authorizationUrl('https://s.example/cb', 'st', $pkce['challenge']);
    assertTrue(str_starts_with($url, 'https://auth.fanvue.com/oauth2/auth?'));
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    assertSame('S256', $query['code_challenge_method']);
    assertSame('code', $query['response_type']);
    assertTrue(str_contains($query['scope'], 'read:insights') && str_contains($query['scope'], 'offline_access'));
});

test('FanvueClient: výměna kódu (Basic auth), refresh s rotací tokenu', function (): void {
    $http = new FakeHttpClient([
        FakeHttpClient::json(['access_token' => 'a1', 'refresh_token' => 'r1', 'expires_in' => 3600, 'token_type' => 'Bearer']),
        FakeHttpClient::json(['access_token' => 'a2', 'refresh_token' => 'r2', 'expires_in' => 3600]),
        FakeHttpClient::json(['error' => 'invalid_grant'], 400),
    ]);
    $client = new FanvueClient($http, 'cid', 'secret');
    $tokens = $client->exchangeCode('code', 'verifier', 'https://s.example/cb');
    assertSame('r1', $tokens->refreshToken);
    assertSame('Basic ' . base64_encode('cid:secret'), $http->requests[0]['headers']['Authorization']);
    parse_str((string) $http->requests[0]['body'], $body);
    assertSame('authorization_code', $body['grant_type']);
    assertSame('verifier', $body['code_verifier']);
    $fresh = $client->refresh($tokens);
    assertSame('r2', $fresh->refreshToken);
    try {
        $client->refresh($fresh);
        throw new AssertionError('čekal jsem výjimku');
    } catch (FanvueException $e) {
        assertTrue($e->reconnectRequired, 'invalid_grant = nutné znovu připojit');
    }
});

test('FanvueClient: stránkování kurzorem, hlavička API verze a 429 s Retry-After', function (): void {
    $slept = [];
    $http = new FakeHttpClient([
        FakeHttpClient::json(['data' => [fanvueEarning('2025-01-02T10:00:00.000Z', 500, 400, 'tip', 'a')], 'nextCursor' => 'c2']),
        new HttpResponse(429, ['retry-after' => '2'], ''),
        FakeHttpClient::json(['data' => [fanvueEarning('2025-01-03T10:00:00.000Z', 900, 720, 'subscription', 'b')], 'nextCursor' => null]),
    ]);
    $client = new FanvueClient($http, 'cid', 'secret', '2025-06-26', function (int $s) use (&$slept): void {
        $slept[] = $s;
    });
    $items = $client->earnings('tok', new DateTimeImmutable('2025-01-01T00:00:00Z'), new DateTimeImmutable('2025-02-01T00:00:00Z'));
    assertSame(2, count($items));
    assertSame([2], $slept);
    assertSame('2025-06-26', $http->requests[0]['headers']['X-Fanvue-API-Version']);
    assertSame('Bearer tok', $http->requests[0]['headers']['Authorization']);
    assertTrue(str_contains($http->requests[0]['url'], '/insights/earnings?startDate=2025-01-01T00%3A00%3A00Z'));
    assertTrue(str_contains($http->requests[2]['url'], 'cursor=c2'));
});

test('FanvueSync: import, idempotentní opakování, obnovení tokenu a statistiky', function (): void {
    $db = testDatabase();
    $accountId = seedAccount($db, 'USD');
    $crypto = new Crypto(Crypto::generateKey());
    $expired = new TokenSet('old-access', 'old-refresh', time() - 10, '');
    $db->update('accounts', ['integration' => 'fanvue', 'credentials_enc' => $crypto->encryptJson($expired->toArray()), 'sync_since' => '2025-01-01'], ['id' => $accountId]);
    $db->insert('exchange_rates', ['currency' => 'USD', 'rate_date' => '2025-01-02', 'czk_per_unit' => 24.0]);
    $db->insert('exchange_rate_months', ['currency' => 'USD', 'year_month' => '2025-01', 'fetched_at' => '2099-01-01 00:00:00']);
    $db->insert('exchange_rate_months', ['currency' => 'USD', 'year_month' => '2024-12', 'fetched_at' => '2099-01-01 00:00:00']);

    $earnings = [
        fanvueEarning('2025-01-02T10:00:00.000Z', 1000, 800, 'subscription', 'whale'),
        fanvueEarning('2025-01-02T10:00:00.000Z', 1000, 800, 'subscription', 'whale'),
        fanvueEarning('2025-01-03T12:00:00.000Z', 500, 400, 'mediaLink', null),
    ];
    // $chunks = počet 31denních bloků okna (každý blok = jeden dotaz na earnings; API vrací jen data v bloku,
    // fake vrací vše a synchronizace si mimo-blokové položky sama odfiltruje).
    $responses = static fn (bool $refresh, int $chunks): array => array_merge(
        $refresh ? [FakeHttpClient::json(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600])] : [],
        array_fill(0, $chunks, FakeHttpClient::json(['data' => $earnings, 'nextCursor' => null])),
        [FakeHttpClient::json(['data' => [['date' => '2025-01-02T00:00:00.000Z', 'total' => 3, 'newSubscribersCount' => 3, 'cancelledSubscribersCount' => 1]], 'nextCursor' => null])]
    );
    $now = new DateTimeImmutable('2025-01-20T08:00:00Z');
    $makeSync = static function (FakeHttpClient $http) use ($db, $crypto): FanvueSync {
        $ledger = new Ledger($db, new ExchangeRates($db, new FakeHttpClient([])));

        return new FanvueSync($db, $crypto, new FanvueClient($http, 'cid', 'secret'), $ledger, new Logger(sys_get_temp_dir() . '/ams-test-logs'));
    };

    $http = new FakeHttpClient($responses(true, 1)); // okno 1.–21. 1. = 1 blok
    $result = $makeSync($http)->sync($accountId, $now);
    assertSame(3, $result['imported']);
    assertSame('Bearer new-access', $http->requests[1]['headers']['Authorization'], 'po refreshi se používá nový token');
    $stored = TokenSet::fromArray($crypto->decryptJson((string) $db->scalar('SELECT credentials_enc FROM accounts WHERE id = :id', ['id' => $accountId])));
    assertSame('new-refresh', $stored->refreshToken, 'rotovaný refresh token je uložen');

    // Druhý běh (okno 35 dní zpět od poslední synchronizace = 2 bloky) nesmí zdvojit data.
    $makeSync(new FakeHttpClient($responses(false, 2)))->sync($accountId, $now);
    assertSame(3, (int) $db->scalar('SELECT COUNT(*) FROM transactions WHERE account_id = :a', ['a' => $accountId]));
    assertSame(19200 * 2 + 9600, (int) $db->scalar('SELECT SUM(net_czk_minor) FROM transactions'), '8 USD × 24 Kč × 2 + 4 USD × 24 Kč');
    assertSame('media_link', $db->scalar("SELECT type FROM transactions WHERE fan_id IS NULL"));
    assertSame(1, (int) $db->scalar('SELECT is_top_spender FROM fans WHERE external_id = :e', ['e' => 'whale']));
    assertSame(3, (int) $db->scalar('SELECT new_subscribers FROM subscriber_stats_daily'));
    assertSame('ok', $db->scalar('SELECT status FROM sync_runs ORDER BY id DESC LIMIT 1'));
});

test('FanvueSync: chyba API se uloží k účtu a nic nerozbije', function (): void {
    $db = testDatabase();
    $accountId = seedAccount($db, 'USD');
    $crypto = new Crypto(Crypto::generateKey());
    $db->update('accounts', ['integration' => 'fanvue', 'credentials_enc' => $crypto->encryptJson((new TokenSet('a', 'r', time() + 3600, ''))->toArray())], ['id' => $accountId]);
    $ledger = new Ledger($db, new ExchangeRates($db, new FakeHttpClient([])));
    $sync = new FanvueSync($db, $crypto, new FanvueClient(new FakeHttpClient([new HttpResponse(401, [], '{}')]), 'cid', 'secret'), $ledger, new Logger(sys_get_temp_dir() . '/ams-test-logs'));
    assertThrows(FanvueException::class, fn () => $sync->sync($accountId));
    assertTrue(str_contains((string) $db->scalar('SELECT last_sync_error FROM accounts WHERE id = :id', ['id' => $accountId]), '401'));
    assertSame('error', $db->scalar('SELECT status FROM sync_runs'));
});
