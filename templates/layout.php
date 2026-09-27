<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var string $content */
$title ??= $v->appName;
$user = $v->user();
$nav = [
    '' => 'Přehled',
    '/models' => 'Modelky',
    '/earnings' => 'Příjmy',
    '/fans' => 'Fanoušci',
    '/costs' => 'Náklady',
    '/links' => 'Odkazy a prokliky',
    '/tools' => 'AI nástroje',
    '/platforms' => 'Platformy',
    '/settings' => 'Nastavení',
];
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="data:,">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<title><?= $v->e($title) ?> · <?= $v->e($v->appName) ?></title>
<link rel="stylesheet" href="<?= $v->asset('app.css') ?>">
<script src="<?= $v->asset('app.js') ?>" defer></script>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <a class="brand" href="<?= $v->url() ?>"><?= $v->e($v->appName) ?></a>
    <?php if ($user !== null): ?>
    <a class="btn btn-primary btn-add" href="<?= $v->url('/models/new') ?>">+ Přidat AI modelku</a>
    <nav aria-label="Hlavní menu">
      <ul class="nav">
        <?php foreach ($nav as $path => $label): ?>
          <li><a href="<?= $v->url($path) ?>"<?= $v->isActive($path) ? ' aria-current="page"' : '' ?>><?= $v->e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </nav>
    <div class="nav-footer small">
      <span class="muted"><?= $v->e($user['username']) ?><?= (int) $user['totp_enabled'] === 1 ? '' : ' · <a href="' . $v->url('/settings') . '#twofactor">zapnout 2FA</a>' ?></span>
      <form method="post" action="<?= $v->url('/logout') ?>" class="inline">
        <?= $v->csrfField() ?>
        <button type="submit" class="link-btn">Odhlásit</button>
      </form>
    </div>
    <?php endif; ?>
  </aside>
  <main class="main">
    <?= $view->partial('partials/flashes') ?>
    <?= $v->errorSummary() ?>
    <?= $content ?>
  </main>
</div>
</body>
</html>
