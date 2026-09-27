<?php /** @var App\Kernel\ViewHelpers $v */ ?>
<div class="page-head"><h1>Import modelky ze souboru</h1><a class="btn" href="<?= $v->url('/models') ?>">Zpět</a></div>

<section class="card">
  <p>Nahraj soubor <code>.json</code> s kompletní modelkou — profil a vzhled, AI nástroje, prompty, účty na platformách a sledovací odkazy se vytvoří najednou. Připravené modelky jsou v repozitáři ve složce <code>models/</code> (např. <code>models/tia-tempest.json</code>), popis formátu v <code>models/README.md</code>.</p>
  <form method="post" action="<?= $v->url('/models/import') ?>" enctype="multipart/form-data">
    <?= $v->csrfField() ?>
    <div class="form-grid">
      <div class="field full">
        <label for="model_file">Soubor s modelkou (.json, max. <?= (int) ($maxBytes / 1_000_000) ?> MB)</label>
        <input type="file" id="model_file" name="model_file" accept=".json,application/json" required>
        <?= $v->error('model_file') ?>
      </div>
    </div>
    <p><button type="submit" class="btn btn-primary">Importovat modelku</button></p>
  </form>
  <p class="hint">Soubor se kontroluje stejnými pravidly jako formuláře v administraci. Když najde chybu, neuloží nic a vypíše všechny chyby najednou. Po importu doplň na profilu fotky, avatar a odkazy na účty, až je založíš.</p>
</section>
