<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var array<string, mixed>|null $platform */
$p = $platform ?? [];
$val = static fn (string $key, mixed $default = '') => $v->old($key, $p[$key] ?? $default);
?>
<div class="page-head"><h1><?= $v->e($title) ?></h1><a class="btn" href="<?= $v->url('/platforms') ?>">Zpět</a></div>
<section class="card">
<form method="post" action="<?= $platform === null ? $v->url('/platforms') : $v->url('/platforms/' . $platform['id']) ?>">
  <?= $v->csrfField() ?>
  <div class="form-grid">
    <div class="field"><label for="name">Název *</label><input type="text" id="name" name="name" required maxlength="80" value="<?= $val('name') ?>"><?= $v->error('name') ?></div>
    <div class="field"><label for="url">Web</label><input type="url" id="url" name="url" value="<?= $val('url') ?>"><?= $v->error('url') ?></div>
    <div class="field"><label for="role">Role</label><select id="role" name="role"><?= $v->options(App\Support\Labels::group('platform_role'), $v->oldRaw('role', $p['role'] ?? 'monetization')) ?></select></div>
    <div class="field"><label for="ai_policy">Pravidla pro AI personu</label><select id="ai_policy" name="ai_policy"><?= $v->options(App\Support\Labels::group('ai_policy'), $v->oldRaw('ai_policy', $p['ai_policy'] ?? 'unknown')) ?></select></div>
    <div class="field"><label for="default_fee_percent">Poplatek platformy %</label><input type="text" id="default_fee_percent" name="default_fee_percent" inputmode="decimal" value="<?= $val('default_fee_percent', 0) ?>"><?= $v->error('default_fee_percent') ?></div>
    <div class="field"><label for="default_currency">Měna výplat</label><select id="default_currency" name="default_currency"><?= $v->options(App\Support\Labels::group('currency'), $v->oldRaw('default_currency', $p['default_currency'] ?? 'USD')) ?></select></div>
    <div class="field full"><label for="notes">Poznámky / pravidla</label><textarea id="notes" name="notes" rows="4"><?= $val('notes') ?></textarea></div>
  </div>
  <p><button type="submit" class="btn btn-primary">Uložit</button></p>
</form>
</section>
