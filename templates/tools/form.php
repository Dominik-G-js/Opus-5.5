<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var array<string, mixed>|null $tool */
$t = $tool ?? [];
$val = static fn (string $key, mixed $default = '') => $v->old($key, $t[$key] ?? $default);
?>
<div class="page-head"><h1><?= $v->e($title) ?></h1><a class="btn" href="<?= $v->url('/tools') ?>">Zpět</a></div>
<section class="card">
<form method="post" action="<?= $tool === null ? $v->url('/tools') : $v->url('/tools/' . $tool['id']) ?>">
  <?= $v->csrfField() ?>
  <div class="form-grid">
    <div class="field"><label for="name">Název *</label><input type="text" id="name" name="name" required maxlength="100" value="<?= $val('name') ?>"><?= $v->error('name') ?></div>
    <div class="field"><label for="url">Web</label><input type="url" id="url" name="url" value="<?= $val('url') ?>"><?= $v->error('url') ?></div>
    <div class="field"><label for="category">Kategorie</label><select id="category" name="category"><?= $v->options(App\Support\Labels::group('tool_category'), $v->oldRaw('category', $t['category'] ?? 'image')) ?></select></div>
    <div class="field"><label for="pricing_model">Cenový model</label><select id="pricing_model" name="pricing_model"><?= $v->options(App\Support\Labels::group('pricing_model'), $v->oldRaw('pricing_model', $t['pricing_model'] ?? 'per_use')) ?></select></div>
    <div class="field"><label for="monthly_price">Měsíční cena (u předplatného)</label><input type="text" id="monthly_price" name="monthly_price" inputmode="decimal" value="<?= $v->old('monthly_price', App\Support\Money::toInput(isset($t['monthly_price_minor']) ? (int) $t['monthly_price_minor'] : null)) ?>"><?= $v->error('monthly_price') ?></div>
    <div class="field"><label for="currency">Měna</label><select id="currency" name="currency"><?= $v->options(App\Support\Labels::group('currency'), $v->oldRaw('currency', $t['currency'] ?? 'USD')) ?></select></div>
    <div class="field full"><label for="notes">Poznámky (ceník, limity, co umí)</label><textarea id="notes" name="notes" rows="4"><?= $val('notes') ?></textarea></div>
  </div>
  <p><button type="submit" class="btn btn-primary">Uložit</button></p>
</form>
<?php if ($tool !== null): ?>
  <form method="post" action="<?= $v->url('/tools/' . $tool['id'] . '/delete') ?>" data-confirm="Smazat nástroj?"><?= $v->csrfField() ?><button type="submit" class="link-btn danger">Smazat nástroj</button></form>
<?php endif; ?>
</section>
