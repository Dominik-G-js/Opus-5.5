<?php
/**
 * Veřejná úvodní stránka studia: rozcestník zveřejněných modelek (karta = fotka, jméno, popisek, odkaz
 * na landing page). Dokud žádná zveřejněná není, krátké představení studia. Vzhled administrace (app.css,
 * žádné inline styly), pouze SFW obsah, jasné označení AI, žádný odkaz ani zmínka o administraci.
 *
 * @var App\Kernel\ViewHelpers $v
 * @var string $studio
 * @var string $canonical
 * @var list<array{name: string, url: string, text: string, photo: ?string, alt: string}> $cards
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
$count = count($cards);
$intro = [
    ['sparkles', 'Postavy s příběhem', 'Každá tvůrkyně má promyšlenou osobnost, zázemí a styl, kterého se drží napříč všemi sítěmi.'],
    ['wand-sparkles', 'Konzistentní vizuál', 'Fotky vznikají s pomocí AI podle pevně daného vzhledu, takže postava vypadá všude stejně.'],
    ['shield-check', 'Otevřeně jako AI', 'Nic neskrýváme: všechny naše tvůrkyně jsou fiktivní a jejich obsah je jasně označený jako vytvořený umělou inteligencí.'],
];
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
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
<link rel="icon" href="data:,">
<link rel="stylesheet" href="<?= $v->asset('app.css') ?>">
<script type="application/ld+json"><?= $jsonLdOut ?></script>
</head>
<body class="home">
<header class="home-bar">
  <span class="brand">
    <span class="brand-mark"><?= $v->icon('gem') ?></span>
    <span class="brand-text"><span class="brand-name"><?= $v->e($studio) ?></span><span class="brand-sub">virtuální tvůrkyně · AI</span></span>
  </span>
</header>
<main class="main home-main" id="main">
  <section class="card card-hero glow-pink glow-violet home-hero" aria-labelledby="home-title">
    <div class="hero-grid">
      <div>
        <span class="badge badge-accent"><?= $v->icon('sparkles', 'icon icon-xs') ?> Fiktivní postavy vytvořené AI</span>
        <h1 class="hero-value home-title" id="home-title"><?= $hasCards ? 'Seznam se s našimi tvůrkyněmi' : 'Navrhujeme virtuální tvůrkyně' ?></h1>
        <p class="home-lead">
          <?php if ($hasCards): ?>
            Každá má vlastní příběh, styl i stránku s odkazy. Vyber si, koho chceš sledovat.
          <?php else: ?>
            Fiktivní postavy, jejichž vzhled, osobnost i obsah vznikají s pomocí umělé inteligence. Od nápadu po každodenní příspěvky.
          <?php endif; ?>
        </p>
      </div>
      <?php if ($hasCards): ?>
        <dl class="hero-side">
          <div><dt>Tvůrkyně</dt><dd><?= $count ?></dd></div>
          <div><dt>Obsah vytvořený AI</dt><dd>100&nbsp;%</dd></div>
        </dl>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($hasCards): ?>
    <section class="section" aria-labelledby="home-models">
      <div class="section-head"><h2 id="home-models">Tvůrkyně</h2></div>
      <ul class="home-grid">
        <?php foreach ($cards as $i => $card): ?>
          <li>
            <a class="card model-card home-card" href="<?= $v->e($card['url']) ?>">
              <span class="home-photo">
                <?php if ($card['photo'] !== null): ?>
                  <img src="<?= $v->e($card['photo']) ?>" alt="<?= $v->e($card['alt'] !== '' ? $card['alt'] : $card['name'] . ' — fotka vytvořená AI') ?>" width="400" height="500" loading="<?= $i < 4 ? 'eager' : 'lazy' ?>" decoding="async">
                <?php else: ?>
                  <span class="model-initials" aria-hidden="true"><?= $v->e(mb_strtoupper(mb_substr($card['name'], 0, 1))) ?></span>
                <?php endif; ?>
              </span>
              <span class="model-id">
                <span class="model-name"><?= $v->e($card['name']) ?></span>
                <?php if ($card['text'] !== ''): ?><span class="home-text"><?= $v->e($card['text']) ?></span><?php endif; ?>
              </span>
              <span class="home-card-foot">
                <span class="badge">AI</span>
                <span class="btn btn-sm">Zobrazit profil</span>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php else: ?>
    <section class="home-intro" aria-label="O studiu">
      <?php foreach ($intro as [$icon, $title, $text]): ?>
        <div class="card">
          <span class="kpi-icon"><?= $v->icon($icon) ?></span>
          <h2><?= $v->e($title) ?></h2>
          <p class="muted"><?= $v->e($text) ?></p>
        </div>
      <?php endforeach; ?>
    </section>
    <p class="home-soon">První tvůrkyně tu představíme už brzy.</p>
  <?php endif; ?>

  <footer class="home-foot"><p>Všechny osoby na této stránce jsou fiktivní postavy. Obrázky jsou vytvořené umělou inteligencí a nezobrazují skutečné lidi.</p></footer>
</main>
</body>
</html>
