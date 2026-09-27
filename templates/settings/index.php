<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var array<string, mixed> $user */
?>
<div class="page-head"><h1>Nastavení</h1></div>

<div class="grid grid-2">
  <section class="card">
    <h2>Měsíční cíl</h2>
    <form method="post" action="<?= $v->url('/settings/goal') ?>">
      <?= $v->csrfField() ?>
      <div class="field"><label for="goal">Cíl v Kč za měsíc</label><input type="text" id="goal" name="goal" inputmode="decimal" value="<?= $v->old('goal', App\Support\Money::toInput($goal)) ?>"><?= $v->error('goal') ?></div>
      <div class="field mt-1"><label for="goal_basis">Počítat z</label><select id="goal_basis" name="goal_basis"><?= $v->options(['profit' => 'Čistý zisk (příjmy po poplatcích − náklady)', 'net' => 'Příjmy po poplatcích platforem'], $v->oldRaw('goal_basis', $goalBasis)) ?></select></div>
      <p class="mt-1"><button type="submit" class="btn btn-primary">Uložit cíl</button></p>
    </form>
  </section>

  <section class="card">
    <h2>Kurzy ČNB</h2>
    <p class="small muted">Příjmy a náklady v cizí měně se přepočítávají kurzem ČNB ke dni platby (víkend = poslední předchozí kurz). Kurzy se stahují automaticky.</p>
    <?php if ($rates !== []): ?>
      <table><tbody>
        <?php foreach ($rates as $rate): ?>
          <tr><td>1 <?= $v->e($rate['currency']) ?></td><td class="num"><?= $v->e(number_format((float) $rate['czk_per_unit'], 3, ',', '')) ?> Kč</td><td class="small muted"><?= $v->date($rate['rate_date']) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
    <form method="post" action="<?= $v->url('/settings/rates') ?>" class="mt-1"><?= $v->csrfField() ?><button type="submit" class="btn">Aktualizovat kurzy</button></form>
  </section>
</div>

<div class="grid grid-2">
  <section class="card">
    <h2>Přihlašovací jméno</h2>
    <form method="post" action="<?= $v->url('/settings/username') ?>" autocomplete="off">
      <?= $v->csrfField() ?>
      <div class="field"><label for="username">Jméno</label><input type="text" id="username" name="username" required minlength="3" maxlength="50" pattern="[a-zA-Z0-9._\-]{3,50}" autocomplete="username" value="<?= $v->old('username', $user['username'] ?? '') ?>"><?= $v->error('username') ?></div>
      <div class="field mt-1"><label for="username_password">Současné heslo</label><input type="password" id="username_password" name="current_password" required autocomplete="current-password"><?= $v->error('username_password') ?></div>
      <p class="mt-1"><button type="submit" class="btn">Změnit jméno</button></p>
    </form>

    <h2 class="mt-1">Změna hesla</h2>
    <form method="post" action="<?= $v->url('/settings/password') ?>" autocomplete="off">
      <?= $v->csrfField() ?>
      <div class="field"><label for="current_password">Současné heslo</label><input type="password" id="current_password" name="current_password" required autocomplete="current-password"><?= $v->error('current_password') ?></div>
      <div class="field mt-1"><label for="password">Nové heslo (min. <?= App\Security\Passwords::MIN_LENGTH ?> znaků)</label><input type="password" id="password" name="password" required minlength="<?= App\Security\Passwords::MIN_LENGTH ?>" autocomplete="new-password"><?= $v->error('password') ?></div>
      <div class="field mt-1"><label for="password_confirm">Nové heslo znovu</label><input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password"></div>
      <p class="mt-1"><button type="submit" class="btn btn-primary">Změnit heslo</button></p>
    </form>
  </section>

  <section class="card" id="twofactor">
    <h2>Dvoufázové ověření (2FA)</h2>
    <?php if ((int) ($user['totp_enabled'] ?? 0) === 1): ?>
      <p><span class="badge badge-good">zapnuto</span> Při přihlášení se vyžaduje kód z aplikace.</p>
      <form method="post" action="<?= $v->url('/settings/2fa/disable') ?>" data-confirm="Opravdu vypnout 2FA?">
        <?= $v->csrfField() ?>
        <div class="field"><label for="disable_password">Heslo pro potvrzení</label><input type="password" id="disable_password" name="current_password" required autocomplete="current-password"><?= $v->error('disable_password') ?></div>
        <p class="mt-1"><button type="submit" class="btn btn-danger">Vypnout 2FA</button></p>
      </form>
    <?php elseif ($pendingSecret !== null): ?>
      <ol class="small">
        <li>V aplikaci (Google Authenticator, Aegis, 1Password…) přidej účet ručně tímto klíčem:</li>
      </ol>
      <p><code class="mono"><?= $v->e(trim(chunk_split($pendingSecret, 4, ' '))) ?></code></p>
      <p class="small">Na mobilu můžeš klepnout: <a href="<?= $v->e($provisioningUri) ?>">otevřít v autentizační aplikaci</a></p>
      <form method="post" action="<?= $v->url('/settings/2fa/enable') ?>">
        <?= $v->csrfField() ?>
        <div class="field"><label for="code">Opiš 6místný kód z aplikace</label><input type="text" id="code" name="code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code"><?= $v->error('code') ?></div>
        <p class="mt-1"><button type="submit" class="btn btn-primary">Zapnout 2FA</button></p>
      </form>
    <?php else: ?>
      <p>Silně doporučeno: i při uniklém hesle se nikdo bez telefonu nepřihlásí.</p>
      <form method="post" action="<?= $v->url('/settings/2fa/start') ?>"><?= $v->csrfField() ?><button type="submit" class="btn btn-primary">Nastavit 2FA</button></form>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <h2>Fanvue API</h2>
  <?php if ($fanvueConfigured): ?>
    <p><span class="badge badge-good">nakonfigurováno</span> Účty připojíš na detailu Fanvue účtu tlačítkem „Připojit Fanvue“.</p>
  <?php else: ?>
    <p>Vyplň <code>fanvue.client_id</code> a <code>fanvue.client_secret</code> v <code>config/config.php</code>. Postup je v README.</p>
  <?php endif; ?>
  <p class="small">Redirect URI pro registraci aplikace ve Fanvue: <code><?= $v->e($redirectUri) ?></code></p>
</section>
