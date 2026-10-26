<?php
/**
 * Veřejná úvodní stránka studia: rozcestník zveřejněných modelek (karta = fotka, jméno, popisek, odkaz
 * na landing page). Dokud žádná zveřejněná není, krátké představení studia. Pouze SFW obsah, jasné označení AI,
 * žádný odkaz ani zmínka o administraci. Bez JavaScriptu, CSS inline s CSP nonce.
 *
 * @var App\Kernel\ViewHelpers $v
 * @var string $studio
 * @var string $canonical
 * @var list<array{name: string, url: string, text: string, photo: ?string, alt: string}> $cards
 * @var string $nonce
 */
$hasCards = $cards !== [];
$pageTitle = $hasCards ? $studio . ' — virtuální tvůrkyně' : $studio;
$description = $hasCards
    ? 'Virtuální tvůrkyně vytvořené umělou inteligencí: ' . implode(', ', array_map(static fn (array $c): string => $c['name'], array_slice($cards, 0, 6))) . '.'
    : 'Studio, které navrhuje a provozuje virtuální tvůrkyně vytvořené umělou inteligencí.';

$jsonLd = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $studio, 'url' => $canonical, 'inLanguage' => 'cs'];
if ($hasCards) {
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'CollectionPage',
        'name' => $pageTitle,
        'url' => $canonical,
        'inLanguage' => 'cs',
        'mainEntity' => [
            '@type' => 'ItemList',
            'itemListElement' => array_map(static fn (array $c, int $i): array => [
                '@type' => 'ListItem', 'position' => $i + 1, 'url' => $c['url'], 'name' => $c['name'],
            ], $cards, array_keys($cards)),
        ],
    ];
}
$jsonLdOut = json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$firstPhoto = null;
foreach ($cards as $card) {
    if ($card['photo'] !== null) {
        $firstPhoto = $card['photo'];
        break;
    }
}
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $v->e($pageTitle) ?></title>
<meta name="description" content="<?= $v->e($description) ?>">
<meta name="robots" content="index, follow, max-image-preview:large">
<link rel="canonical" href="<?= $v->e($canonical) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $v->e($pageTitle) ?>">
<meta property="og:description" content="<?= $v->e($description) ?>">
<meta property="og:url" content="<?= $v->e($canonical) ?>">
<?php if ($firstPhoto !== null): ?>
<meta property="og:image" content="<?= $v->e($firstPhoto) ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= $firstPhoto !== null ? 'summary_large_image' : 'summary' ?>">
<meta name="theme-color" content="#16121a">
<link rel="icon" href="data:,">
<script type="application/ld+json"><?= $jsonLdOut ?></script>
<style nonce="<?= $v->e($nonce) ?>">
:root{color-scheme:dark;--bg:#16121a;--card:#221c28;--ink:#fbf8ff;--ink2:#cfc6da;--muted:#a79db3;--line:rgba(255,255,255,.12);--accent:#f0a3c4;--accent-ink:#1b1020}
@media (prefers-color-scheme:light){:root{color-scheme:light;--bg:#faf7fb;--card:#ffffff;--ink:#1d1622;--ink2:#4c4254;--muted:#6e6477;--line:rgba(20,10,30,.12);--accent:#b8467a;--accent-ink:#ffffff}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:1040px;margin:0 auto;padding:48px 16px 48px}
header{text-align:center;max-width:620px;margin:0 auto 36px}
.badge{display:inline-block;font-size:.8rem;font-weight:600;padding:3px 10px;border-radius:999px;border:1px solid var(--line);color:var(--ink2);margin-bottom:14px}
h1{font-size:clamp(1.9rem,4.5vw,2.6rem);line-height:1.15;margin:0 0 12px;letter-spacing:-.015em}
.lead{font-size:1.08rem;color:var(--ink2);margin:0}
.grid{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,240px));justify-content:center;gap:16px}
@media (max-width:520px){.grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.body{padding:12px 12px 14px}.name{font-size:1.02rem}.text{font-size:.88rem}}
.card{display:flex;flex-direction:column;height:100%;border-radius:18px;overflow:hidden;background:var(--card);border:1px solid var(--line);color:var(--ink);text-decoration:none;transition:border-color .15s,transform .15s}
.card:hover,.card:focus-visible{border-color:var(--accent);transform:translateY(-2px)}
.photo{display:block;width:100%;height:auto;aspect-ratio:4/5;object-fit:cover;background:var(--bg)}
.photo.empty{display:flex;align-items:center;justify-content:center;font-size:3.4rem;font-weight:700;color:var(--accent);background:linear-gradient(160deg,var(--card),var(--bg))}
.body{padding:14px 16px 16px}
.name{display:block;font-size:1.15rem;font-weight:700;margin:0 0 4px}
.text{display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden;color:var(--ink2);font-size:.95rem;margin:0}
.more{display:block;margin-top:10px;font-size:.88rem;font-weight:600;color:var(--accent)}
.intro{max-width:620px;margin:0 auto;display:grid;gap:12px}
.intro section{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px 18px}
.intro h2{font-size:1rem;margin:0 0 4px}
.intro p{margin:0;color:var(--ink2)}
.soon{text-align:center;color:var(--muted);margin:24px 0 0}
footer{max-width:620px;margin:40px auto 0;font-size:.8rem;color:var(--muted);text-align:center}
:focus-visible{outline:2px solid var(--accent);outline-offset:3px}
@media (prefers-reduced-motion:reduce){.card{transition:none}.card:hover,.card:focus-visible{transform:none}}
</style>
</head>
<body>
<main>
  <header>
    <div class="badge">Virtuální tvůrkyně vytvořené AI</div>
    <h1><?= $v->e($studio) ?></h1>
    <?php if ($hasCards): ?>
      <p class="lead">Seznam se s našimi virtuálními tvůrkyněmi. Každá má vlastní příběh, styl i stránku s odkazy.</p>
    <?php else: ?>
      <p class="lead">Navrhujeme a provozujeme virtuální tvůrkyně — fiktivní postavy, jejichž vzhled, osobnost i obsah vznikají s pomocí umělé inteligence.</p>
    <?php endif; ?>
  </header>

  <?php if ($hasCards): ?>
    <ul class="grid">
      <?php foreach ($cards as $i => $card): ?>
        <li>
          <a class="card" href="<?= $v->e($card['url']) ?>">
            <?php if ($card['photo'] !== null): ?>
              <img class="photo" src="<?= $v->e($card['photo']) ?>" alt="<?= $v->e($card['alt'] !== '' ? $card['alt'] : $card['name'] . ' — fotka vytvořená AI') ?>" width="400" height="500" loading="<?= $i < 4 ? 'eager' : 'lazy' ?>" decoding="async">
            <?php else: ?>
              <span class="photo empty" aria-hidden="true"><?= $v->e(mb_strtoupper(mb_substr($card['name'], 0, 1))) ?></span>
            <?php endif; ?>
            <span class="body">
              <span class="name"><?= $v->e($card['name']) ?></span>
              <?php if ($card['text'] !== ''): ?><span class="text"><?= $v->e($card['text']) ?></span><?php endif; ?>
              <span class="more">Zobrazit profil →</span>
            </span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <div class="intro">
      <section>
        <h2>Postavy s příběhem</h2>
        <p>Každá tvůrkyně má promyšlenou osobnost, zázemí a styl, kterého se drží napříč všemi sítěmi.</p>
      </section>
      <section>
        <h2>Konzistentní vizuál</h2>
        <p>Fotky vznikají s pomocí AI podle pevně daného vzhledu, takže postava vypadá všude stejně.</p>
      </section>
      <section>
        <h2>Otevřeně jako AI</h2>
        <p>Nic neskrýváme: všechny naše tvůrkyně jsou fiktivní a jejich obsah je jasně označený jako vytvořený umělou inteligencí.</p>
      </section>
      <p class="soon">První tvůrkyně tu představíme už brzy.</p>
    </div>
  <?php endif; ?>

  <footer><p>Všechny osoby na této stránce jsou fiktivní postavy. Obrázky jsou vytvořené umělou inteligencí a nezobrazují skutečné lidi.</p></footer>
</main>
</body>
</html>
