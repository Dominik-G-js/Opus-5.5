<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var string $content */
$title ??= $v->appName;
$user = $v->user();
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="data:,">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<meta name="color-scheme" content="light dark">
<title><?= $v->e($title) ?> · <?= $v->e($v->appName) ?></title>
<link rel="stylesheet" href="<?= $v->asset('app.css') ?>">
<?php /* Přechod mezi stránkami začne až po načtení obsahu (jinak by se prolínalo do prázdné stránky). */ ?>
<link rel="expect" href="#main" blocking="render">
<script src="<?= $v->asset('app.js') ?>" defer></script>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="sidebar-top">
      <a class="brand" href="<?= $v->url() ?>">
        <span class="brand-mark"><?= $v->icon('gem') ?></span>
        <span class="brand-text"><span class="brand-name"><?= $v->e($v->appName) ?></span><span class="brand-sub">administrace · 18+</span></span>
      </a>
      <?php if ($user !== null): ?>
        <button type="button" class="btn btn-icon menu-button" popovertarget="nav-drawer" aria-label="Otevřít menu"><?= $v->icon('menu') ?></button>
      <?php endif; ?>
    </div>
    <?php if ($user !== null): ?>
      <div class="sidebar-nav"><?= $view->partial('partials/nav', ['user' => $user]) ?></div>
    <?php endif; ?>
  </aside>
  <?php if ($user !== null): ?>
    <div class="drawer" id="nav-drawer" popover aria-label="Menu">
      <div class="drawer-head">
        <span class="brand-name"><?= $v->e($v->appName) ?></span>
        <button type="button" class="btn btn-icon" popovertarget="nav-drawer" popovertargetaction="hide" aria-label="Zavřít menu"><?= $v->icon('x') ?></button>
      </div>
      <?= $view->partial('partials/nav', ['user' => $user]) ?>
    </div>
  <?php endif; ?>
  <main class="main" id="main">
    <?= $view->partial('partials/flashes') ?>
    <?= $v->errorSummary() ?>
    <?= $content ?>
  </main>
</div>
</body>
</html>
