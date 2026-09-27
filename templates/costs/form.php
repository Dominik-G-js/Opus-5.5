<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var array<string, mixed>|null $cost */
$c = $cost ?? [];
$val = static fn (string $key, mixed $default = '') => $v->old($key, $c[$key] ?? $default);
?>
<div class="page-head"><h1><?= $v->e($title) ?></h1><a class="btn" href="<?= $v->url('/costs') ?>">Zpět</a></div>
<section class="card">
<form method="post" action="<?= $cost === null ? $v->url('/costs') : $v->url('/costs/' . $cost['id']) ?>">
  <?= $v->csrfField() ?>
  <div class="form-grid">
    <div class="field"><label for="incurred_on">Datum *</label><input type="date" id="incurred_on" name="incurred_on" required value="<?= $val('incurred_on', App\Support\Clock::todayLocal()) ?>"><?= $v->error('incurred_on') ?></div>
    <div class="field"><label for="category">Kategorie</label><select id="category" name="category"><?= $v->options(App\Support\Labels::group('cost_category'), $v->oldRaw('category', $c['category'] ?? 'generation')) ?></select></div>
    <div class="field"><label for="model_id">Modelka</label><select id="model_id" name="model_id"><?= $v->options($modelOptions, $v->oldRaw('model_id', $c['model_id'] ?? $presetModel ?? ''), true, '— společný náklad —') ?></select></div>
    <div class="field"><label for="tool_id">AI nástroj</label><select id="tool_id" name="tool_id"><?= $v->options($toolOptions, $v->oldRaw('tool_id', $c['tool_id'] ?? ''), true) ?></select></div>
    <div class="field"><label for="amount">Částka *</label><input type="text" id="amount" name="amount" required inputmode="decimal" placeholder="12,50" value="<?= $v->old('amount', App\Support\Money::toInput(isset($c['amount_minor']) ? (int) $c['amount_minor'] : null)) ?>"><?= $v->error('amount') ?></div>
    <div class="field"><label for="currency">Měna</label><select id="currency" name="currency"><?= $v->options(App\Support\Labels::group('currency'), $v->oldRaw('currency', $c['currency'] ?? 'USD')) ?></select></div>
    <div class="field"><label for="quantity">Počet kusů</label><input type="number" id="quantity" name="quantity" min="1" value="<?= $val('quantity') ?>"><p class="hint">Počet obrázků / sekund videa — pro výpočet ceny za kus.</p><?= $v->error('quantity') ?></div>
    <div class="field"><label for="fx_rate">Kurz CZK (volitelné)</label><input type="text" id="fx_rate" name="fx_rate" inputmode="decimal" placeholder="automaticky dle ČNB" value="<?= $v->old('fx_rate') ?>"><p class="hint">Prázdné = kurz ČNB k datu. Vyplň, pokud chceš kurz z výpisu banky.</p><?= $v->error('fx_rate') ?></div>
    <div class="field full"><label for="note">Poznámka</label><input type="text" id="note" name="note" maxlength="500" value="<?= $val('note') ?>"></div>
  </div>
  <p><button type="submit" class="btn btn-primary">Uložit</button></p>
</form>
<?php if ($cost !== null): ?>
  <form method="post" action="<?= $v->url('/costs/' . $cost['id'] . '/delete') ?>" data-confirm="Smazat náklad?"><?= $v->csrfField() ?><button type="submit" class="link-btn danger">Smazat náklad</button></form>
<?php endif; ?>
</section>
