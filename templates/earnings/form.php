<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var array<string, mixed>|null $tx */
$t = $tx ?? [];
$local = isset($t['occurred_at']) ? App\Support\Clock::formatLocal((string) $t['occurred_at'], 'Y-m-d H:i') : '';
?>
<div class="page-head"><h1><?= $v->e($title) ?></h1><a class="btn" href="<?= $v->url('/earnings', $tx !== null ? ['month' => substr((string) $tx['occurred_on'], 0, 7)] : []) ?>">Zpět</a></div>
<?php if ($accountOptions === []): ?>
  <div class="callout">Příjmy se zapisují k účtu modelky na platformě. Nejdřív přidej AI modelku a na jejím profilu jí založ účet (např. Fanvue).
    <p class="mt-1"><a class="btn btn-primary" href="<?= $v->url('/models/new') ?>">+ Přidat AI modelku</a></p></div>
<?php else: ?>
<section class="card">
<form method="post" action="<?= $tx === null ? $v->url('/earnings') : $v->url('/earnings/' . $tx['id']) ?>">
  <?= $v->csrfField() ?>
  <div class="form-grid">
    <div class="field full"><label for="account_id">Účet *</label><select id="account_id" name="account_id" required><?= $v->options($accountOptions, $v->oldRaw('account_id', $presetAccount ?? ''), true, '— vyber —') ?></select><?= $v->error('account_id') ?></div>
    <div class="field"><label for="occurred_on">Datum *</label><input type="date" id="occurred_on" name="occurred_on" required value="<?= $v->old('occurred_on', $local !== '' ? substr($local, 0, 10) : App\Support\Clock::todayLocal()) ?>"><?= $v->error('occurred_on') ?></div>
    <div class="field"><label for="occurred_time">Čas</label><input type="time" id="occurred_time" name="occurred_time" value="<?= $v->old('occurred_time', $local !== '' ? substr($local, 11, 5) : '12:00') ?>"><?= $v->error('occurred_time') ?></div>
    <div class="field"><label for="type">Typ platby</label><select id="type" name="type"><?= $v->options(App\Support\Labels::group('tx_type'), $v->oldRaw('type', $t['type'] ?? 'subscription')) ?></select></div>
    <div class="field"><label for="currency">Měna</label><select id="currency" name="currency"><?= $v->options(App\Support\Labels::group('currency'), $v->oldRaw('currency', $t['currency'] ?? ''), true, 'dle účtu') ?></select></div>
    <div class="field"><label for="gross">Hrubá částka</label><input type="text" id="gross" name="gross" inputmode="decimal" placeholder="10,00" value="<?= $v->old('gross', App\Support\Money::toInput(isset($t['gross_minor']) ? (int) $t['gross_minor'] : null)) ?>"><?= $v->error('gross') ?></div>
    <div class="field"><label for="net">Čistá částka (po poplatku)</label><input type="text" id="net" name="net" inputmode="decimal" placeholder="dopočítá se" value="<?= $v->old('net', App\Support\Money::toInput(isset($t['net_minor']) ? (int) $t['net_minor'] : null)) ?>">
      <p class="hint"><?= $tx === null ? 'Stačí vyplnit jednu — druhá se dopočítá z poplatku účtu.' : 'Když měníš částku, druhé pole vymaž — dopočítá se z poplatku účtu.' ?></p><?= $v->error('net') ?></div>
    <div class="field"><label for="fan">Fanoušek</label><input type="text" id="fan" name="fan" maxlength="150" placeholder="jméno nebo @handle" value="<?= $v->old('fan', $fanName ?? '') ?>"></div>
    <div class="field"><label for="fx_rate">Kurz CZK (volitelné)</label><input type="text" id="fx_rate" name="fx_rate" inputmode="decimal" placeholder="automaticky dle ČNB" value="<?= $v->old('fx_rate') ?>">
      <?php if ($tx !== null && $tx['currency'] !== 'CZK'): ?><p class="hint">Uložený kurz <?= $v->e(number_format((float) $tx['fx_rate'], 3, ',', '')) ?>. Prázdné = kurz ČNB k datu platby.</p><?php endif; ?><?= $v->error('fx_rate') ?></div>
    <div class="field full"><label for="note">Poznámka</label><input type="text" id="note" name="note" maxlength="500" value="<?= $v->old('note', $t['note'] ?? '') ?>"></div>
  </div>
  <p><button type="submit" class="btn btn-primary">Uložit</button></p>
</form>
<?php if ($tx !== null): ?>
  <form method="post" action="<?= $v->url('/earnings/' . $tx['id'] . '/delete') ?>" data-confirm="Smazat platbu?"><?= $v->csrfField() ?><button type="submit" class="link-btn danger">Smazat příjem</button></form>
<?php endif; ?>
</section>
<?php endif; ?>
