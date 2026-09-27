<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var string $content */
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="data:,">
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex, nofollow">
<title>Přihlášení · <?= $v->e($v->appName) ?></title>
<link rel="stylesheet" href="<?= $v->asset('app.css') ?>">
</head>
<body>
<div class="auth">
  <div class="card card-auth">
    <div class="brand brand-auth">
      <span class="brand-mark"><?= $v->icon('gem') ?></span>
      <span class="brand-text"><span class="brand-name"><?= $v->e($v->appName) ?></span><span class="brand-sub">administrace · 18+</span></span>
    </div>
    <h1 class="visually-hidden"><?= $v->e($v->appName) ?></h1>
    <?= $view->partial('partials/flashes') ?>
    <?= $content ?>
  </div>
</div>
</body>
</html>
