<?php

declare(strict_types=1);

use App\Kernel\HttpException;
use App\Kernel\IpRange;
use App\Kernel\Request;
use App\Kernel\Router;
use App\Security\Crypto;
use App\Security\Passwords;
use App\Security\Totp;
use App\Support\ColumnChart;
use App\Support\Money;
use App\Support\Str;

test('Money: parsování českých i anglických formátů', function (): void {
    assertSame(1250, Money::parseToMinor('12,50'));
    assertSame(1250, Money::parseToMinor('12.50'));
    assertSame(123456, Money::parseToMinor('1 234,56'));
    assertSame(123456, Money::parseToMinor('$1,234.56'));
    assertSame(123456, Money::parseToMinor('1.234,56'));
    assertSame(123400, Money::parseToMinor('1,234'));
    assertSame(1000000, Money::parseToMinor('10 000 Kč'));
    assertSame(-500, Money::parseToMinor('-5'));
    assertSame(50, Money::parseToMinor('0,5'));
    assertThrows(InvalidArgumentException::class, fn () => Money::parseToMinor('abc'));
    assertThrows(InvalidArgumentException::class, fn () => Money::parseToMinor('0,123'));
    assertThrows(InvalidArgumentException::class, fn () => Money::parseToMinor(''));
});

test('Money: formát a přepočet', function (): void {
    assertSame("10\u{00A0}000\u{00A0}Kč", Money::format(1000000, 'CZK', false));
    assertSame('$12.50', Money::format(1250, 'USD'));
    assertSame(26438, Money::convert(1250, 21.15));
    assertSame('12,50', Money::toInput(1250));
});

test('TOTP: testovací vektory RFC 6238 (SHA-1)', function (): void {
    $secret = Totp::base32Encode('12345678901234567890');
    assertSame('287082', Totp::codeAt($secret, intdiv(59, 30)));
    assertSame('081804', Totp::codeAt($secret, intdiv(1111111109, 30)));
    assertSame('005924', Totp::codeAt($secret, intdiv(1234567890, 30)));
});

test('TOTP: ověření, tolerance ±1 krok a ochrana proti opakování', function (): void {
    $secret = Totp::generateSecret();
    $time = 1_800_000_000;
    $step = Totp::currentStep($time);
    $code = Totp::codeAt($secret, $step);
    assertSame($step, Totp::verify($secret, $code, 0, $time));
    assertSame(null, Totp::verify($secret, $code, $step, $time), 'stejný kód podruhé');
    assertSame($step - 1, Totp::verify($secret, Totp::codeAt($secret, $step - 1), 0, $time));
    assertSame(null, Totp::verify($secret, Totp::codeAt($secret, $step - 3), 0, $time));
    assertSame(null, Totp::verify($secret, '12345', 0, $time));
    assertSame('12345678901234567890', Totp::base32Decode(Totp::base32Encode('12345678901234567890')));
});

test('Crypto: šifrování, integrita a špatný klíč', function (): void {
    $crypto = new Crypto(Crypto::generateKey());
    $payload = $crypto->encryptJson(['refresh_token' => 'abc', 'expires_at' => 5]);
    assertSame(['refresh_token' => 'abc', 'expires_at' => 5], $crypto->decryptJson($payload));
    assertTrue($crypto->encrypt('x') !== $crypto->encrypt('x'), 'nonce musí být náhodná');
    $tampered = substr($payload, 0, -4) . 'AAAA';
    assertThrows(RuntimeException::class, fn () => $crypto->decrypt($tampered));
    assertThrows(RuntimeException::class, fn () => (new Crypto(Crypto::generateKey()))->decrypt($payload));
    assertThrows(RuntimeException::class, fn () => new Crypto('base64:krátký'));
});

test('Passwords: validace síly hesla a hash', function (): void {
    assertTrue(Passwords::validate('kratke') !== null);
    assertTrue(Passwords::validate('aaaaaaaaaaaaaaaa') !== null);
    assertSame(null, Passwords::validate('Velmi-Tajne-Heslo-2026!'));
    $hash = Passwords::hash('Velmi-Tajne-Heslo-2026!');
    assertTrue(Passwords::verify('Velmi-Tajne-Heslo-2026!', $hash));
    assertTrue(!Passwords::verify('jine', $hash));
    assertTrue(password_get_info(Passwords::dummyHash())['algo'] !== null, 'dummy hash musí být platný');
});

test('IpRange: IPv4 i IPv6 CIDR', function (): void {
    assertTrue(IpRange::matches('173.245.48.10', '173.245.48.0/20'));
    assertTrue(!IpRange::matches('173.245.64.1', '173.245.48.0/20'));
    assertTrue(IpRange::matches('2400:cb00::1', '2400:cb00::/32'));
    assertTrue(!IpRange::matches('10.0.0.1', '2400:cb00::/32'));
    assertTrue(IpRange::matches('10.1.2.3', '10.1.2.3'));
    assertTrue(!IpRange::matches('nonsense', '10.0.0.0/8'));
});

test('Request: host se normalizuje a odmítne nesmysly', function (): void {
    assertSame('lunavale.com', Request::normalizeHost('LunaVale.com:443'));
    assertSame('::1', Request::normalizeHost('[::1]:8080'));
    assertSame('', Request::normalizeHost('evil.com/<script>'));
});

test('Router: parametry, doslovné tečky a 404/405', function (): void {
    $router = new Router();
    $router->get('/media/p/{publicId}.jpg', fn () => 'media');
    $router->post('/models/{id}', fn () => 'update');
    [$handler, $params] = $router->match('GET', '/media/p/' . str_repeat('a', 32) . '.jpg');
    assertSame('media', $handler());
    assertSame(str_repeat('a', 32), $params['publicId']);
    assertThrows(HttpException::class, fn () => $router->match('GET', '/media/p/' . str_repeat('a', 32) . 'xjpg'));
    try {
        $router->match('GET', '/models/5');
        throw new AssertionError('čekal jsem 405');
    } catch (HttpException $e) {
        assertSame(405, $e->status);
    }
    assertThrows(HttpException::class, fn () => $router->match('POST', '/models/0'));
});

test('Str: slug a náhodný kód', function (): void {
    assertSame('lucie-dvorakova', Str::slug('Lucie Dvořáková'));
    assertSame('model', Str::slug('!!!'));
    assertTrue(preg_match('/^[a-z2-9]{7}$/', Str::randomCode(7)) === 1);
});

test('ColumnChart: SVG s kulatou osou a escapovanými popisky', function (): void {
    $svg = ColumnChart::render(['led', '<b>'], [
        ['name' => 'a', 'class' => 'bar-series-1', 'values' => [123456, 0]],
    ], ['tip "1"', 'tip 2'], 'Graf');
    assertTrue(str_starts_with($svg, '<svg'));
    assertTrue(str_contains($svg, '&lt;b&gt;'), 'popisek musí být escapovaný');
    assertTrue(!str_contains($svg, '<b>'));
    assertTrue(str_contains($svg, '1&nbsp;500') || str_contains($svg, "1\u{00A0}500"), 'osa má kulaté hodnoty');
    assertSame('', ColumnChart::render([], [], [], 'x'));
});

test('LoginThrottle: po 5 neúspěších z jedné IP na účet blokuje, jiná IP může', function (): void {
    $throttle = new App\Security\LoginThrottle(testDatabase());
    for ($i = 0; $i < 4; $i++) {
        $throttle->record('1.2.3.4', 'Dominik', false);
    }
    assertSame(0, $throttle->retryAfter('1.2.3.4', 'dominik'));
    $throttle->record('1.2.3.4', 'dominik', false);
    $wait = $throttle->retryAfter('1.2.3.4', 'DOMINIK');
    assertTrue($wait > 800 && $wait <= 900, "čekání ~15 min, je {$wait}");
    assertSame(0, $throttle->retryAfter('5.6.7.8', 'dominik'));
    assertSame(0, $throttle->purgeOlderThanDays(30));
});

test('CsvExport: ochrana proti formula injection, čísla zůstávají čísly', function (): void {
    assertSame("'=HYPERLINK(\"x\")", App\Support\CsvExport::cell('=HYPERLINK("x")'));
    assertSame("'+1", App\Support\CsvExport::cell('+1'));
    assertSame("'@SUM(A1)", App\Support\CsvExport::cell('@SUM(A1)'));
    assertSame('-12,5', App\Support\CsvExport::cell(App\Support\CsvExport::amount(-1250)));
    assertSame('bigspender', App\Support\CsvExport::cell('bigspender'));
    $response = App\Support\CsvExport::response('prijmy 2026-09.csv', ['A', 'B'], [['=1+1', 5]]);
    assertTrue(str_starts_with($response->body(), "\xEF\xBB\xBFA;B"));
    assertTrue(str_contains($response->body(), "'=1+1;5"));
    assertSame('attachment; filename="prijmy_2026-09.csv"', $response->header('Content-Disposition'));
});
