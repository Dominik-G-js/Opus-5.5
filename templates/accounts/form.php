<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var array<string, mixed> $model */
/** @var array<string, mixed>|null $account */
$a = $account ?? [];
$val = static fn (string $key, mixed $default = '') => $v->old($key, $a[$key] ?? $default);
$platformOptions = [];
$banned = [];
foreach ($platforms as $platform) {
    $platformOptions[(int) $platform['id']] = $platform['name'] . ' — ' . App\Support\Labels::get('platform_role', $platform['role'])
        . ' · poplatek ' . rtrim(rtrim(number_format((float) $platform['default_fee_percent'], 1, ',', ''), '0'), ',') . ' %';
    if ($platform['ai_policy'] === 'banned') {
        $banned[] = $platform['name'];
    }
}
?>
<div class="page-head"><h1><?= $v->e($title) ?></h1><a class="btn" href="<?= $v->url('/models/' . $model['id']) ?>">Zpět na <?= $v->e($model['name']) ?></a></div>
<?php if ($banned !== []): ?>
  <div class="callout callout-bad">Pozor: <?= $v->e(implode(', ', $banned)) ?> podle svých podmínek nepovoluje čistě AI persony (obsah musí zobrazovat ověřeného člověka). Účet s AI modelkou tam riskuje ban a ztrátu výdělku.</div>
<?php endif; ?>
<section class="card">
<form method="post" action="<?= $account === null ? $v->url('/models/' . $model['id'] . '/accounts') : $v->url('/accounts/' . $account['id']) ?>">
  <?= $v->csrfField() ?>
  <div class="form-grid">
    <div class="field"><label for="platform_id">Platforma *</label><select id="platform_id" name="platform_id" required><?= $v->options($platformOptions, $v->oldRaw('platform_id', $a['platform_id'] ?? ''), true, '— vyber —') ?></select><?= $v->error('platform_id') ?></div>
    <div class="field"><label for="handle">Uživatelské jméno *</label><input type="text" id="handle" name="handle" required maxlength="100" value="<?= $val('handle') ?>"><?= $v->error('handle') ?></div>
    <div class="field"><label for="profile_url">Odkaz na profil</label><input type="url" id="profile_url" name="profile_url" value="<?= $val('profile_url') ?>"><?= $v->error('profile_url') ?></div>
    <div class="field"><label for="status">Stav</label><select id="status" name="status"><?= $v->options(App\Support\Labels::group('account_status'), $v->oldRaw('status', $a['status'] ?? 'active')) ?></select></div>
    <div class="field"><label for="fee_percent">Poplatek platformy %</label><input type="text" id="fee_percent" name="fee_percent" inputmode="decimal" placeholder="dle platformy" value="<?= $val('fee_percent') ?>"><p class="hint">Prázdné = výchozí poplatek platformy. Použije se, když zadáš jen hrubou nebo jen čistou částku.</p><?= $v->error('fee_percent') ?></div>
    <div class="field"><label for="currency">Měna výplat</label><select id="currency" name="currency"><?= $v->options(App\Support\Labels::group('currency'), $v->oldRaw('currency', $a['currency'] ?? ''), true, 'dle platformy') ?></select></div>
    <div class="field"><label for="sync_since">Při 1. synchronizaci importovat od</label><input type="date" id="sync_since" name="sync_since" value="<?= $val('sync_since') ?>"><p class="hint">Jen pro Fanvue API. Prázdné = posledních 90 dní.</p><?= $v->error('sync_since') ?></div>
    <div class="field check"><input type="checkbox" id="show_on_page" name="show_on_page" value="1"<?= $v->checked((bool) $v->oldRaw('show_on_page', $a['show_on_page'] ?? 0)) ?>><label for="show_on_page">Uvést profil na landing page (SEO „sameAs“)</label></div>
    <div class="field full"><label for="notes">Poznámky</label><textarea id="notes" name="notes" rows="3"><?= $val('notes') ?></textarea></div>
  </div>
  <p><button type="submit" class="btn btn-primary">Uložit</button></p>
</form>
</section>
<?php if ($account !== null): ?>
<section class="card">
  <h2>Smazat účet</h2>
  <form method="post" action="<?= $v->url('/accounts/' . $account['id'] . '/delete') ?>" data-confirm="Opravdu smazat účet se všemi příjmy?">
    <?= $v->csrfField() ?>
    <label class="check"><input type="checkbox" name="confirm" value="1"> Rozumím, že se smažou i příjmy a fanoušci tohoto účtu.</label>
    <p><button type="submit" class="btn btn-danger">Smazat účet</button></p>
  </form>
</section>
<?php endif; ?>
