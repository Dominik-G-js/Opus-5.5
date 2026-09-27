<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var int $status */
/** @var string $message */
/** @var string|null $backUrl */
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="data:,">
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex">
<title><?= (int) $status ?></title>
<link rel="stylesheet" href="<?= $v->asset('app.css') ?>">
</head>
<body>
<div class="auth">
  <div class="card">
    <h1><?= (int) $status ?></h1>
    <p><?= $v->e($message) ?></p>
    <?php if ($backUrl !== null): ?>
      <p><a class="btn" href="<?= $v->e($backUrl) ?>">Zpět do administrace</a></p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
