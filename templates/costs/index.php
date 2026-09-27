<?php /** @var App\Kernel\ViewHelpers $v */ ?>
<div class="page-head">
  <h1>Náklady</h1>
  <div class="actions">
    <a class="btn" href="<?= $v->url('/costs/export', ['month' => $month !== '' ? $month : substr(App\Support\Clock::todayLocal(), 0, 7)]) ?>">Export CSV</a>
    <a class="btn btn-primary" href="<?= $v->url('/costs/new') ?>">+ Náklad</a>
  </div>
</div>

<form method="get" action="<?= $v->url('/costs') ?>" class="filters">
  <div class="field"><label for="month">Měsíc</label><input type="text" id="month" name="month" placeholder="RRRR-MM" pattern="\d{4}-\d{2}" value="<?= $v->e($month) ?>"></div>
  <div class="field"><label for="model">Modelka</label><select id="model" name="model"><?= $v->options($modelOptions, $modelId ?? '', true, '— všechny —') ?></select></div>
  <button type="submit" class="btn">Filtrovat</button>
</form>

<div class="grid grid-2">
  <section class="card">
    <h2>Celkem <?= $v->money($total) ?></h2>
    <table><tbody>
      <?php foreach ($byCategory as $row): ?>
        <tr><td><?= $v->label('cost_category', $row['category']) ?></td><td class="num"><?= $v->money((int) $row['total']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </section>
  <section class="card small">
    <h2>Co zapisovat</h2>
    <ul>
      <li><strong>Trénink LoRA</strong> — jednorázově při tvorbě modelky.</li>
      <li><strong>Generování</strong> — i s <em>počtem kusů</em>, aby šla spočítat cena za obrázek.</li>
      <li><strong>Předplatné</strong> nástrojů (Higgsfield, ElevenLabs…) — pokud slouží všem modelkám, nech modelku prázdnou (společný náklad).</li>
      <li><strong>Reklama, chatter, hosting, doména</strong>.</li>
    </ul>
  </section>
</div>

<div class="card table-wrap">
  <?php if ($costs === []): ?>
    <p class="empty">Žádné náklady pro zvolený filtr.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Datum</th><th>Kategorie</th><th>Modelka</th><th>Nástroj</th><th class="num">Ks</th><th class="num">Částka</th><th class="num">V CZK</th><th>Poznámka</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($costs as $cost): ?>
      <tr>
        <td><?= $v->date($cost['incurred_on']) ?></td>
        <td><?= $v->label('cost_category', $cost['category']) ?></td>
        <td><?= $v->e($cost['model_name'] ?? 'společné') ?></td>
        <td><?= $v->e($cost['tool_name'] ?? '—') ?></td>
        <td class="num"><?= $cost['quantity'] !== null ? (int) $cost['quantity'] : '—' ?></td>
        <td class="num"><?= $v->money((int) $cost['amount_minor'], $cost['currency'], true) ?></td>
        <td class="num"><?= $v->money((int) $cost['amount_czk_minor'], 'CZK', true) ?></td>
        <td class="small"><?= $v->e($cost['note'] ?? '') ?></td>
        <td class="num"><a class="small" href="<?= $v->url('/costs/' . $cost['id'] . '/edit') ?>">upravit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
