<?php

declare(strict_types=1);

use App\Database\Migrator;
use App\Kernel\App;
use App\Kernel\Config;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Support\Clock;

/** Aplikace s prázdnou DB v paměti; veřejná část se volá přes App::handle() jako skutečný požadavek. */
function publicApp(): App
{
    $app = new App(new Config([
        'base_url' => 'https://studio.example.test',
        'admin_path' => '/tajna-sprava',
        'app_name' => 'Testovací Studio',
        'app_key' => 'base64:' . base64_encode(random_bytes(32)),
        'db_path' => ':memory:',
    ]), dirname(__DIR__));
    (new Migrator($app->db, dirname(__DIR__) . '/migrations'))->migrate();

    return $app;
}

function publicGet(App $app, string $path, string $host = 'studio.example.test'): Response
{
    return $app->handle(new Request('GET', $path, [], [], [], [], '203.0.113.5', true, $host));
}

/** @param array<string, mixed> $fields */
function seedPublicModel(App $app, string $name, string $slug, array $fields = []): int
{
    $now = Clock::nowUtc();

    return $app->db->insert('models', ['name' => $name, 'slug' => $slug, 'persona_age' => 23, 'created_at' => $now, 'updated_at' => $now] + $fields);
}

function seedPublicImage(App $app, int $modelId, string $publicId, bool $public): int
{
    $id = $app->db->insert('images', [
        'model_id' => $modelId, 'stored_name' => $publicId . '.jpg', 'original_name' => 'a.jpg', 'mime' => 'image/jpeg',
        'width' => 800, 'height' => 1000, 'size_bytes' => 1, 'sha256' => $publicId, 'is_public' => $public ? 1 : 0,
        'public_id' => $publicId, 'alt_text' => null, 'created_at' => Clock::nowUtc(),
    ]);
    $app->db->update('models', ['avatar_image_id' => $id], ['id' => $modelId]);

    return $id;
}

test('Úvodní stránka: bez zveřejněných modelek ukáže představení studia', function (): void {
    $app = publicApp();
    seedPublicModel($app, 'Skrytá', 'skryta', ['tagline' => 'Ještě se chystá']);
    $r = publicGet($app, '/');
    assertSame(200, $r->status());
    $html = $r->body();
    assertTrue(str_contains($html, '<h1>Testovací Studio</h1>'), 'název studia v nadpisu');
    assertTrue(str_contains($html, 'První tvůrkyně tu představíme'), 'představení studia');
    assertTrue(!str_contains($html, 'class="grid"') && !str_contains($html, 'Skrytá'), 'nezveřejněná modelka se neukáže');
    assertTrue(!str_contains($html, 'tajna-sprava'), 'žádná zmínka o administraci');
    assertTrue(str_contains($html, '<link rel="canonical" href="https://studio.example.test/">'));
    preg_match("/'nonce-([^']+)'/", (string) $r->header('Content-Security-Policy'), $m);
    assertTrue(isset($m[1]) && str_contains($html, 'nonce="' . $m[1] . '"'), 'CSP nonce sedí na inline styl');
    assertSame(null, $r->header('Set-Cookie'), 'veřejná stránka nenastavuje cookies');
});

test('Úvodní stránka: karty zveřejněných modelek s fotkou, jménem, popiskem a odkazem', function (): void {
    $app = publicApp();
    $anna = seedPublicModel($app, 'Anna <b>', 'anna', ['page_published' => 1, 'tagline' => 'Ranní káva a hory']);
    seedPublicImage($app, $anna, str_repeat('a', 32), true);
    seedPublicModel($app, 'Bára', 'bara', ['page_published' => 1, 'public_bio' => "Řádek jedna\nŘádek dva", 'page_domain' => 'bara.example.com']);
    $cecilie = seedPublicModel($app, 'Cecílie', 'cecilie', ['page_published' => 1]);
    seedPublicImage($app, $cecilie, str_repeat('c', 32), false);
    seedPublicModel($app, 'Dana', 'dana', ['tagline' => 'Koncept']);

    $html = publicGet($app, '/')->body();
    assertTrue(!str_contains($html, 'První tvůrkyně tu představíme'), 'bez úvodního představení');
    assertSame(3, substr_count($html, 'class="card"'), 'tři zveřejněné modelky');
    assertTrue(str_contains($html, 'href="https://studio.example.test/m/anna"'), 'odkaz na /m/{slug}');
    assertTrue(str_contains($html, 'src="https://studio.example.test/media/p/' . str_repeat('a', 32) . '.jpg"'), 'veřejná fotka');
    assertTrue(str_contains($html, 'Anna &lt;b&gt;') && !str_contains($html, 'Anna <b>'), 'jméno je escapované');
    assertTrue(str_contains($html, 'Ranní káva a hory'), 'popisek z taglinu');
    assertTrue(str_contains($html, 'href="https://bara.example.com/"'), 'vlastní doména má přednost');
    assertTrue(str_contains($html, 'Řádek jedna Řádek dva'), 'bez taglinu se použije bio na jednom řádku');
    assertTrue(!str_contains($html, str_repeat('c', 32)), 'soukromá fotka se nevydá');
    assertTrue(!str_contains($html, 'Dana'), 'nezveřejněná modelka chybí');
    assertTrue(strpos($html, 'Anna') < strpos($html, 'Bára') && strpos($html, 'Bára') < strpos($html, 'Cecílie'), 'řazení podle jména');
    assertTrue(str_contains($html, '"@type":"ItemList"'), 'JSON-LD seznam');
    assertTrue(!str_contains($html, 'tajna-sprava'), 'žádná zmínka o administraci');
});

test('Sitemap: úvodní stránka na hlavní doméně, na vlastní doméně ne', function (): void {
    $app = publicApp();
    seedPublicModel($app, 'Anna', 'anna', ['page_published' => 1]);
    seedPublicModel($app, 'Bára', 'bara', ['page_published' => 1, 'page_domain' => 'bara.example.com']);
    $main = publicGet($app, '/sitemap.xml')->body();
    assertTrue(str_contains($main, '<loc>https://studio.example.test/</loc>'), 'úvodní stránka v sitemapě');
    assertTrue(str_contains($main, '<loc>https://studio.example.test/m/anna</loc>'));
    $custom = publicGet($app, '/sitemap.xml', 'bara.example.com')->body();
    assertTrue(!str_contains($custom, 'studio.example.test'), 'sitemap vlastní domény jen s její stránkou');
    assertTrue(str_contains($custom, '<loc>https://bara.example.com/</loc>'));
    assertSame(404, publicGet($app, '/neexistuje')->status(), 'neznámá adresa dál 404');
});
