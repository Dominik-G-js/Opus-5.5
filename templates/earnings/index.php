<?php /** @var App\Kernel\ViewHelpers $v */ ?>
<div class="page-head">
  <h1>Příjmy · <?= $v->e($month) ?></h1>
  <div class="actions">
    <a class="btn" href="<?= $v->url('/earnings/export', ['month' => $month]) ?>">Export CSV</a>
    <a class="btn" href="<?= $v->url('/earnings/import') ?>">Import CSV</a>
    <a class="btn btn-primary" href="<?= $v->url('/earnings/new') ?>">+ Příjem</a>
  </div>
</div>
<form method="get" action="<?= $v->url('/earnings') ?>" class="filters">
  <div class="field"><label for="month">Měsíc</label><input type="text" id="month" name="month" pattern="\d{4}-\d{2}" value="<?= $v->e($month) ?>"></div>
  <div class="field"><label for="model">Modelka</label><select id="model" name="model"><?= $v->options($modelOptions, $modelId ?? '', true, '— všechny —') ?></select></div>
  <div class="field"><label for="account">Účet</label><select id="account" name="account"><?= $v->options($accountOptions, $accountId ?? '', true, '— všechny —') ?></select></div>
  <div class="field"><label for="type">Typ</label><select id="type" name="type"><?= $v->options(App\Support\Labels::group('tx_type'), $type, true, '— všechny —') ?></select></div>
  <button type="submit" class="btn">Filtrovat</button>
</form>
<div class="grid grid-3">
  <div class="stat"><div class="stat-label">Hrubě</div><div class="stat-value"><?= $v->money((int) $totals['gross']) ?></div></div>
  <div class="stat"><div class="stat-label">Po poplatcích</div><div class="stat-value"><?= $v->money((int) $totals['net']) ?></div></div>
  <div class="stat"><div class="stat-label">Plateb</div><div class="stat-value"><?= (int) $totals['count'] ?></div></div>
</div>
<div class="card table-wrap">
  <?php if ($rows === []): ?>
    <p class="empty">Žádné příjmy pro zvolený filtr.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Kdy</th><th>Modelka · účet</th><th>Typ</th><th>Fanoušek</th><th class="num">Hrubě</th><th class="num">Čistě</th><th class="num">Kurz</th><th class="num">V CZK</th><th>Zdroj</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td class="small"><?= $v->dateTime($row['occurred_at']) ?></td>
        <td><?= $v->e($row['model']) ?><br><span class="small muted"><?= $v->e($row['platform']) ?> @<?= $v->e($row['account']) ?></span></td>
        <td><?= $v->label('tx_type', $row['type']) ?></td>
        <td class="small"><?php if ($row['fan_id'] !== null): ?><a href="<?= $v->url('/fans/' . $row['fan_id']) ?>"><?= $v->e($row['fan_name'] ?? $row['fan_handle']) ?></a><?php else: ?>—<?php endif; ?></td>
        <td class="num"><?= $v->money((int) $row['gross_minor'], $row['currency'], true) ?></td>
        <td class="num"><?= $v->money((int) $row['net_minor'], $row['currency'], true) ?></td>
        <td class="num small"><?= $row['currency'] === 'CZK' ? '—' : $v->e(number_format((float) $row['fx_rate'], 3, ',', '')) ?></td>
        <td class="num"><?= $v->money((int) $row['net_czk_minor'], 'CZK', true) ?></td>
        <td><span class="badge"><?= $v->label('tx_source', $row['source']) ?></span></td>
        <td class="row-actions">
          <?php if (!empty($row['locked'])): ?>
            <span class="small muted" title="Platbu přepisuje synchronizace s Fanvue. Upravit ji jde po odpojení účtu.">z API</span>
          <?php else: ?>
            <a class="small" href="<?= $v->url('/earnings/' . $row['id'] . '/edit') ?>">upravit</a>
            <form method="post" action="<?= $v->url('/earnings/' . $row['id'] . '/delete') ?>" data-confirm="Smazat platbu?" class="inline"><?= $v->csrfField() ?><button type="submit" class="link-btn danger small">smazat</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
