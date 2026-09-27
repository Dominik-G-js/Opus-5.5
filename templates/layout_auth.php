<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var string $content */
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="data:,">
<meta name="robots" content="noindex, nofollow">
<title>Přihlášení · <?= $v->e($v->appName) ?></title>
<link rel="stylesheet" href="<?= $v->asset('app.css') ?>">
</head>
<body>
<div class="auth">
  <div class="card">
    <h1><?= $v->e($v->appName) ?></h1>
    <?= $view->partial('partials/flashes') ?>
    <?= $content ?>
  </div>
</div>
</body>
</html>
