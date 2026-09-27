<?php /** @var App\Kernel\ViewHelpers $v */ ?>
<div class="page-head"><h1>Import příjmů z CSV</h1><a class="btn" href="<?= $v->url('/earnings') ?>">Zpět</a></div>

<?php if ($token === null): ?>
<section class="card">
  <p>Nahraj CSV export plateb z platformy (výpis, statement). V dalším kroku přiřadíš sloupce. Opakovaný import stejného souboru nevytvoří duplicity.</p>
  <?php if ($accountOptions === []): ?>
    <div class="callout">Nejdřív přidej AI modelku a na jejím profilu jí založ účet na platformě.
      <p class="mt-1"><a class="btn btn-primary" href="<?= $v->url('/models/new') ?>">+ Přidat AI modelku</a></p></div>
  <?php else: ?>
  <form method="post" action="<?= $v->url('/earnings/import') ?>" enctype="multipart/form-data">
    <?= $v->csrfField() ?>
    <div class="form-grid">
      <div class="field"><label for="account_id">Účet *</label><select id="account_id" name="account_id" required><?= $v->options($accountOptions, '', true, '— vyber —') ?></select></div>
      <div class="field"><label for="csv">CSV soubor (max. 5 MB)</label><input type="file" id="csv" name="csv" accept=".csv,.txt,text/csv" required></div>
    </div>
    <p><button type="submit" class="btn btn-primary">Pokračovat</button></p>
  </form>
  <?php endif; ?>
</section>
<?php else: ?>
<section class="card">
  <h2>Přiřazení sloupců — <?= $v->e($pending['name']) ?></h2>
  <form method="post" action="<?= $v->url('/earnings/import/confirm') ?>">
    <?= $v->csrfField() ?>
    <input type="hidden" name="token" value="<?= $v->e($token) ?>">
    <?php
    $columns = [];
    foreach ($preview['headers'] as $index => $header) {
        $columns[$index] = ($header !== '' ? $header : 'Sloupec ' . ($index + 1));
    }
    ?>
    <div class="form-grid">
      <?php foreach ($fields as $field => $label):
          $guess = '';
          foreach ($columns as $index => $header) {
              $h = mb_strtolower($header);
              $hints = [
                  'date' => ['date', 'datum', 'time', 'čas'],
                  'gross' => ['gross', 'hrub', 'amount', 'částka', 'price'],
                  'net' => ['net', 'čist', 'earning'],
                  'type' => ['type', 'typ', 'source', 'category', 'description'],
                  'fan' => ['fan', 'user', 'name', 'subscriber', 'handle'],
                  'currency' => ['currency', 'měna'],
                  'note' => ['note', 'poznámka', 'comment'],
              ][$field];
              foreach ($hints as $hint) {
                  if (str_contains($h, $hint)) {
                      $guess = (string) $index;
                      break 2;
                  }
              }
          }
          ?>
        <div class="field"><label for="map_<?= $v->e($field) ?>"><?= $v->e($label) ?></label><select id="map_<?= $v->e($field) ?>" name="map_<?= $v->e($field) ?>"><?= $v->options($columns, $guess, true, '— nepoužít —') ?></select></div>
      <?php endforeach; ?>
      <div class="field"><label for="timezone">Časová zóna v souboru</label><select id="timezone" name="timezone"><?= $v->options(['local' => 'Europe/Prague (místní)', 'UTC' => 'UTC'], 'UTC') ?></select><p class="hint">Platformy obvykle exportují v UTC.</p></div>
    </div>
    <p class="hint">Když chybí čistá částka, dopočítá se z poplatku účtu. Typ platby se odhadne z textu (subscription, tip, message/PPV…).</p>
    <p><button type="submit" class="btn btn-primary">Importovat</button></p>
  </form>
</section>
<section class="card table-wrap">
  <h2>Náhled (prvních <?= count($preview['rows']) ?> řádků)</h2>
  <table>
    <thead><tr><?php foreach ($columns as $header): ?><th><?= $v->e($header) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php foreach ($preview['rows'] as $row): ?>
        <tr><?php foreach (array_keys($columns) as $index): ?><td class="small"><?= $v->e($row[$index] ?? '') ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
