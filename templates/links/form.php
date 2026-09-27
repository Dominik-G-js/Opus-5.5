<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var array<string, mixed>|null $link */
$l = $link ?? [];
$val = static fn (string $key, mixed $default = '') => $v->old($key, $l[$key] ?? $default);
?>
<div class="page-head"><h1><?= $v->e($title) ?></h1><a class="btn" href="<?= $v->url('/links') ?>">Zpět</a></div>
<?php if ($modelOptions === []): ?>
  <div class="callout">Odkaz patří ke konkrétní modelce — nejdřív ji přidej.
    <p class="mt-1"><a class="btn btn-primary" href="<?= $v->url('/models/new') ?>">+ Přidat AI modelku</a></p></div>
<?php else: ?>
<section class="card">
<form method="post" action="<?= $link === null ? $v->url('/links') : $v->url('/links/' . $link['id']) ?>">
  <?= $v->csrfField() ?>
  <div class="form-grid">
    <div class="field"><label for="model_id">Modelka *</label><select id="model_id" name="model_id" required><?= $v->options($modelOptions, $v->oldRaw('model_id', $l['model_id'] ?? $presetModel ?? ''), true, '— vyber —') ?></select><?= $v->error('model_id') ?></div>
    <div class="field"><label for="label">Popisek *</label><input type="text" id="label" name="label" required maxlength="80" placeholder="Fanvue — TikTok bio" value="<?= $val('label') ?>"><?= $v->error('label') ?></div>
    <div class="field"><label for="source">Zdroj návštěvnosti</label><select id="source" name="source"><?= $v->options(App\Support\Labels::group('link_source'), $v->oldRaw('source', $l['source'] ?? 'tiktok')) ?></select></div>
    <div class="field"><label for="code">Kód v adrese</label><input type="text" id="code" name="code" maxlength="40" placeholder="náhodný" value="<?= $val('code') ?>"><p class="hint">/go/kód — prázdné = vygeneruje se.</p><?= $v->error('code') ?></div>
    <div class="field full"><label for="target_url">Cílová adresa *</label><input type="url" id="target_url" name="target_url" required placeholder="https://www.fanvue.com/…" value="<?= $val('target_url') ?>"><p class="hint">Ideálně Fanvue tracking link pro tento zdroj — uvidíš i konverze ve Fanvue.</p><?= $v->error('target_url') ?></div>
    <div class="field"><label for="sort_order">Pořadí na stránce</label><input type="number" id="sort_order" name="sort_order" min="0" max="999" value="<?= $val('sort_order', 0) ?>"></div>
    <div class="field">
      <label class="check"><input type="checkbox" name="is_active" value="1"<?= $v->checked((bool) $v->oldRaw('is_active', $l['is_active'] ?? 1)) ?>> Aktivní</label>
      <label class="check"><input type="checkbox" name="show_on_page" value="1"<?= $v->checked((bool) $v->oldRaw('show_on_page', $l['show_on_page'] ?? 0)) ?>> Zobrazit na landing page</label>
      <label class="check"><input type="checkbox" name="is_premium" value="1"<?= $v->checked((bool) $v->oldRaw('is_premium', $l['is_premium'] ?? 0)) ?>> 18+ obsah (skrytý za potvrzením věku)</label>
    </div>
  </div>
  <p><button type="submit" class="btn btn-primary">Uložit</button></p>
</form>
<?php if ($link !== null): ?>
  <form method="post" action="<?= $v->url('/links/' . $link['id'] . '/delete') ?>" data-confirm="Smazat odkaz i statistiky?"><?= $v->csrfField() ?><button type="submit" class="link-btn danger">Smazat odkaz</button></form>
<?php endif; ?>
</section>
<?php endif; ?>
