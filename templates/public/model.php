<?php
/**
 * Veřejná SEO landing page modelky (link-in-bio). Pouze SFW obsah, jasné označení AI (AI Act čl. 50,
 * pravidla platforem), 18+ odkazy za potvrzením věku. Bez JavaScriptu, CSS inline s CSP nonce.
 *
 * @var App\Kernel\ViewHelpers $v
 * @var array<string, mixed> $model
 */
$t = [
    'en' => [
        'badge' => 'AI-generated virtual creator',
        'follow' => 'Follow me',
        'premium' => 'Exclusive content (18+)',
        'confirm' => 'I am 18 or older — show links',
        'gallery' => 'Gallery',
        'disclosure' => '%s is a fictional character. All images on this page are generated with artificial intelligence and do not depict a real person.',
        'photo' => '%s — AI-generated photo',
        'default_title' => '%s — AI creator',
    ],
    'cs' => [
        'badge' => 'Virtuální tvůrkyně vytvořená AI',
        'follow' => 'Sleduj mě',
        'premium' => 'Exkluzivní obsah (18+)',
        'confirm' => 'Je mi 18 let nebo víc — zobrazit odkazy',
        'gallery' => 'Galerie',
        'disclosure' => '%s je fiktivní postava. Všechny obrázky na této stránce jsou vytvořené umělou inteligencí a nezobrazují skutečnou osobu.',
        'photo' => '%s — fotka vytvořená AI',
        'default_title' => '%s — AI tvůrkyně',
    ],
][$lang];

$name = (string) $model['name'];
$pageTitle = $model['seo_title'] ?: sprintf($t['default_title'], $name);
$bio = trim((string) ($model['public_bio'] ?? ''));
$description = $model['seo_description'] ?: ($model['tagline'] ?: App\Support\Str::limit(preg_replace('/\s+/', ' ', $bio) ?? '', 160));
$toIso = static function (?string $utc): ?string {
    if ($utc === null || $utc === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

    return $date === false ? null : $date->format(DATE_ATOM);
};

$person = array_filter([
    '@type' => 'Person',
    'name' => $name,
    'alternateName' => $model['slug'],
    'description' => $bio !== '' ? $bio : $description,
    'image' => $avatarUrl,
    'sameAs' => array_values(array_map(static fn (array $p): string => (string) $p['profile_url'], $profiles)) ?: null,
]);
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'ProfilePage',
    'url' => $canonical,
    'name' => $pageTitle,
    'dateCreated' => $toIso($model['created_at']),
    'dateModified' => $toIso($model['updated_at']),
    'mainEntity' => $person,
];
$jsonLdOut = json_encode(
    array_filter($jsonLd, static fn ($value): bool => $value !== null),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?><!doctype html>
<html lang="<?= $v->e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $v->e($pageTitle) ?></title>
<meta name="description" content="<?= $v->e($description) ?>">
<meta name="robots" content="index, follow, max-image-preview:large">
<link rel="canonical" href="<?= $v->e($canonical) ?>">
<meta property="og:type" content="profile">
<meta property="og:title" content="<?= $v->e($pageTitle) ?>">
<meta property="og:description" content="<?= $v->e($description) ?>">
<meta property="og:url" content="<?= $v->e($canonical) ?>">
<?php if ($avatarUrl !== null): ?>
<meta property="og:image" content="<?= $v->e($avatarUrl) ?>">
<meta property="og:image:alt" content="<?= $v->e(sprintf($t['photo'], $name)) ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= $avatarUrl !== null ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= $v->e($pageTitle) ?>">
<meta name="twitter:description" content="<?= $v->e($description) ?>">
<meta name="theme-color" content="#16121a">
<?php if ($avatarUrl !== null): ?><link rel="icon" href="<?= $v->e($avatarUrl) ?>" type="image/jpeg"><?php else: ?><link rel="icon" href="data:,"><?php endif; ?>
<script type="application/ld+json"><?= $jsonLdOut ?></script>
<style nonce="<?= $v->e($nonce) ?>">
:root{color-scheme:dark;--bg:#16121a;--card:#221c28;--ink:#fbf8ff;--ink2:#cfc6da;--muted:#a79db3;--line:rgba(255,255,255,.12);--accent:#f0a3c4;--accent-ink:#1b1020}
@media (prefers-color-scheme:light){:root{color-scheme:light;--bg:#faf7fb;--card:#ffffff;--ink:#1d1622;--ink2:#4c4254;--muted:#6e6477;--line:rgba(20,10,30,.12);--accent:#b8467a;--accent-ink:#ffffff}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:520px;margin:0 auto;padding:40px 16px 48px;text-align:center}
.avatar{width:128px;height:128px;border-radius:50%;object-fit:cover;border:3px solid var(--accent);display:block;margin:0 auto 16px}
h1{font-size:1.9rem;line-height:1.2;margin:0 0 8px;letter-spacing:-.01em}
.badge{display:inline-block;font-size:.8rem;font-weight:600;padding:3px 10px;border-radius:999px;border:1px solid var(--line);color:var(--ink2);margin-bottom:12px}
.tagline{font-size:1.05rem;color:var(--ink2);margin:0 0 12px}
.bio{color:var(--ink2);margin:0 0 24px;white-space:pre-line}
h2{font-size:.85rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin:28px 0 12px;font-weight:600}
.links{list-style:none;margin:0;padding:0;display:grid;gap:10px}
.links a{display:block;padding:14px 18px;border-radius:14px;background:var(--card);border:1px solid var(--line);color:var(--ink);text-decoration:none;font-weight:600}
.links a:hover,.links a:focus-visible{border-color:var(--accent)}
.links a.primary{background:var(--accent);color:var(--accent-ink);border-color:var(--accent)}
details.gate{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:4px 14px 14px;text-align:left}
details.gate .links a{text-align:center}
details.gate summary{cursor:pointer;padding:12px 4px;font-weight:600;list-style:none;text-align:center}
details.gate summary::-webkit-details-marker{display:none}
details.gate[open] summary{margin-bottom:8px}
.gallery{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.gallery img{width:100%;height:auto;aspect-ratio:4/5;object-fit:cover;border-radius:12px;display:block;background:var(--card)}
footer{margin-top:32px;font-size:.8rem;color:var(--muted)}
:focus-visible{outline:2px solid var(--accent);outline-offset:3px}
</style>
</head>
<body>
<main>
  <?php if ($avatarUrl !== null): ?>
    <img class="avatar" src="<?= $v->e($avatarUrl) ?>" alt="<?= $v->e($avatar['alt_text'] ?: sprintf($t['photo'], $name)) ?>" width="128" height="128" fetchpriority="high">
  <?php endif; ?>
  <h1><?= $v->e($name) ?></h1>
  <div class="badge"><?= $v->e($t['badge']) ?></div>
  <?php if (!empty($model['tagline'])): ?><p class="tagline"><?= $v->e($model['tagline']) ?></p><?php endif; ?>
  <?php if ($bio !== ''): ?><p class="bio"><?= $v->e($bio) ?></p><?php endif; ?>

  <?php if ($socialLinks !== []): ?>
    <h2><?= $v->e($t['follow']) ?></h2>
    <ul class="links">
      <?php foreach ($socialLinks as $link): ?>
        <li><a href="<?= $v->e($goUrl((string) $link['code'])) ?>" rel="nofollow noopener"><?= $v->e($link['label']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($premiumLinks !== []): ?>
    <h2><?= $v->e($t['premium']) ?></h2>
    <details class="gate">
      <summary><?= $v->e($t['confirm']) ?></summary>
      <ul class="links">
        <?php foreach ($premiumLinks as $i => $link): ?>
          <li><a class="<?= $i === 0 ? 'primary' : '' ?>" href="<?= $v->e($goUrl((string) $link['code'])) ?>" rel="nofollow noopener"><?= $v->e($link['label']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </details>
  <?php endif; ?>

  <?php if ($gallery !== []): ?>
    <h2><?= $v->e($t['gallery']) ?></h2>
    <div class="gallery">
      <?php foreach ($gallery as $i => $image): ?>
        <img src="<?= $v->e($mediaUrl((string) $image['public_id'])) ?>" alt="<?= $v->e($image['alt_text'] ?: sprintf($t['photo'], $name)) ?>" width="<?= (int) $image['width'] ?>" height="<?= (int) $image['height'] ?>" loading="<?= $i < 2 ? 'eager' : 'lazy' ?>" decoding="async">
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <footer><p><?= $v->e(sprintf($t['disclosure'], $name)) ?></p></footer>
</main>
</body>
</html>
