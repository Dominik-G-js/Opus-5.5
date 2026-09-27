<?php /** @var App\Kernel\ViewHelpers $v */ ?>
<div class="page-head"><h1>Přidat příjem</h1><a class="btn" href="<?= $v->url('/earnings') ?>">Zpět</a></div>
<?php if ($accountOptions === []): ?>
  <div class="callout">Nejdřív vytvoř modelku a přidej jí účet na platformě.</div>
<?php else: ?>
<section class="card">
<form method="post" action="<?= $v->url('/earnings') ?>">
  <?= $v->csrfField() ?>
  <div class="form-grid">
    <div class="field full"><label for="account_id">Účet *</label><select id="account_id" name="account_id" required><?= $v->options($accountOptions, $v->oldRaw('account_id', $presetAccount ?? ''), true, '— vyber —') ?></select><?= $v->error('account_id') ?></div>
    <div class="field"><label for="occurred_on">Datum *</label><input type="date" id="occurred_on" name="occurred_on" required value="<?= $v->old('occurred_on', App\Support\Clock::todayLocal()) ?>"><?= $v->error('occurred_on') ?></div>
    <div class="field"><label for="occurred_time">Čas</label><input type="time" id="occurred_time" name="occurred_time" value="<?= $v->old('occurred_time', '12:00') ?>"><?= $v->error('occurred_time') ?></div>
    <div class="field"><label for="type">Typ platby</label><select id="type" name="type"><?= $v->options(App\Support\Labels::group('tx_type'), $v->oldRaw('type', 'subscription')) ?></select></div>
    <div class="field"><label for="currency">Měna</label><select id="currency" name="currency"><?= $v->options(App\Support\Labels::group('currency'), $v->oldRaw('currency', ''), true, 'dle účtu') ?></select></div>
    <div class="field"><label for="gross">Hrubá částka</label><input type="text" id="gross" name="gross" inputmode="decimal" placeholder="10,00" value="<?= $v->old('gross') ?>"><?= $v->error('gross') ?></div>
    <div class="field"><label for="net">Čistá částka (po poplatku)</label><input type="text" id="net" name="net" inputmode="decimal" placeholder="dopočítá se" value="<?= $v->old('net') ?>"><p class="hint">Stačí vyplnit jednu — druhá se dopočítá z poplatku účtu.</p><?= $v->error('net') ?></div>
    <div class="field"><label for="fan">Fanoušek</label><input type="text" id="fan" name="fan" maxlength="150" placeholder="jméno nebo @handle" value="<?= $v->old('fan') ?>"></div>
    <div class="field"><label for="fx_rate">Kurz CZK (volitelné)</label><input type="text" id="fx_rate" name="fx_rate" inputmode="decimal" placeholder="automaticky dle ČNB" value="<?= $v->old('fx_rate') ?>"><?= $v->error('fx_rate') ?></div>
    <div class="field full"><label for="note">Poznámka</label><input type="text" id="note" name="note" maxlength="500" value="<?= $v->old('note') ?>"></div>
  </div>
  <p><button type="submit" class="btn btn-primary">Uložit</button></p>
</form>
</section>
<?php endif; ?>
