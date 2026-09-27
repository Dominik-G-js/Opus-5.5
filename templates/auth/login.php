<?php /** @var App\Kernel\ViewHelpers $v */ ?>
<form method="post" action="<?= $v->url('/login') ?>" autocomplete="on">
  <?= $v->csrfField() ?>
  <div class="field">
    <label for="username">Uživatelské jméno</label>
    <input type="text" id="username" name="username" required autocomplete="username" autocapitalize="none" spellcheck="false" autofocus>
  </div>
  <div class="field">
    <label for="password">Heslo</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">
  </div>
  <button type="submit" class="btn btn-primary">Přihlásit</button>
</form>
