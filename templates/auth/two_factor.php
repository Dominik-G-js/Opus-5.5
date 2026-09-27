<?php /** @var App\Kernel\ViewHelpers $v */ ?>
<p>Zadej 6místný kód z autentizační aplikace.</p>
<form method="post" action="<?= $v->url('/login/2fa') ?>">
  <?= $v->csrfField() ?>
  <div class="field">
    <label for="code">Kód</label>
    <input type="text" id="code" name="code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" autofocus>
  </div>
  <button type="submit" class="btn btn-primary">Ověřit</button>
</form>
