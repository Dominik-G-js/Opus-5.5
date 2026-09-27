<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var array<string, mixed> $user */
$items = [
    '' => ['Přehled', 'layout-dashboard'],
    '/models' => ['Modelky', 'sparkles'],
    '/earnings' => ['Příjmy', 'wallet'],
    '/fans' => ['Fanoušci', 'heart'],
    '/costs' => ['Náklady', 'receipt'],
    '/links' => ['Odkazy a prokliky', 'link-2'],
    '/tools' => ['AI nástroje', 'wand-sparkles'],
    '/platforms' => ['Platformy', 'layers'],
    '/settings' => ['Nastavení', 'settings'],
];
?>
<a class="btn btn-primary btn-add" href="<?= $v->url('/models/new') ?>">+ Přidat AI modelku</a>
<nav aria-label="Hlavní menu">
  <ul class="nav">
    <?php foreach ($items as $path => [$label, $icon]): ?>
      <li><a href="<?= $v->url($path) ?>"<?= $v->isActive($path) ? ' aria-current="page"' : '' ?>><?= $v->icon($icon) ?><span><?= $v->e($label) ?></span></a></li>
    <?php endforeach; ?>
  </ul>
</nav>
<div class="nav-footer small">
  <span class="muted"><?= $v->e($user['username']) ?><?= (int) $user['totp_enabled'] === 1 ? '' : ' · <a href="' . $v->url('/settings') . '#twofactor">zapnout 2FA</a>' ?></span>
  <form method="post" action="<?= $v->url('/logout') ?>" class="inline">
    <?= $v->csrfField() ?>
    <button type="submit" class="link-btn"><?= $v->icon('log-out', 'icon icon-xs') ?>Odhlásit</button>
  </form>
</div>
