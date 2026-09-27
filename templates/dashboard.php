<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var array<string, mixed> $summary */
$basisLabel = $goalBasis === 'net' ? 'příjmy po poplatcích' : 'čistý zisk (příjmy − náklady)';
?>
<div class="page-head">
  <h1>Přehled · <?= $v->e($month) ?></h1>
  <div class="actions">
    <a class="btn btn-sm" href="<?= $v->url('', ['month' => $prevMonth]) ?>">← <?= $v->e($prevMonth) ?></a>
    <?php if ($nextMonth !== null): ?>
      <a class="btn btn-sm" href="<?= $v->url('', ['month' => $nextMonth]) ?>"><?= $v->e($nextMonth) ?> →</a>
    <?php endif; ?>
    <a class="btn btn-sm btn-primary" href="<?= $v->url('/earnings/new') ?>">+ Příjem</a>
    <a class="btn btn-sm" href="<?= $v->url('/costs/new') ?>">+ Náklad</a>
  </div>
</div>

<?php if ($modelCount === 0): ?>
  <div class="callout">
    <strong>Začni tady:</strong> <a href="<?= $v->url('/models/new') ?>">vytvoř první modelku</a>,
    přidej jí účty na platformách a náklady na tvorbu. Návod a strategie jsou v <code>docs/</code>.
  </div>
<?php endif; ?>

<?php foreach ($syncErrors as $error): ?>
  <div class="callout callout-bad">
    Synchronizace <a href="<?= $v->url('/accounts/' . $error['id']) ?>"><?= $v->e($error['platform']) ?> @<?= $v->e($error['handle']) ?></a> selhala:
    <?= $v->e($error['last_sync_error']) ?>
  </div>
<?php endforeach; ?>

<div class="grid grid-2">
  <section class="card" aria-labelledby="goal-title">
    <h2 id="goal-title">Měsíční cíl</h2>
    <div class="hero-value"><?= $v->money($goalValue) ?></div>
    <progress class="meter" max="100" value="<?= $v->e(round($goalPercent, 1)) ?>" aria-label="Splnění cíle"><?= $v->e(round($goalPercent)) ?> %</progress>
    <div class="small muted">
      <?= $v->e(round($goalPercent)) ?> % z cíle <?= $v->money($goal) ?> · počítáno jako <?= $v->e($basisLabel) ?>
      <?php if ($isCurrentMonth): ?>
        <br>Odhad za celý měsíc při současném tempu: <strong><?= $v->money($projected) ?></strong>
      <?php endif; ?>
    </div>
  </section>

  <section class="card" aria-labelledby="chart-title">
    <h2 id="chart-title">Posledních 12 měsíců</h2>
    <div class="legend" aria-hidden="true">
      <span><span class="key key-1"></span>Příjmy po poplatcích</span>
      <span><span class="key key-2"></span>Náklady</span>
    </div>
    <?= $chart ?>
    <details class="table-view">
      <summary>Zobrazit jako tabulku</summary>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Měsíc</th><th class="num">Příjmy</th><th class="num">Náklady</th><th class="num">Zisk</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($series) as $point): ?>
            <tr>
              <td><?= $v->e($point['month']) ?></td>
              <td class="num"><?= $v->money($point['net']) ?></td>
              <td class="num"><?= $v->money($point['costs']) ?></td>
              <td class="num"><?= $v->money($point['net'] - $point['costs']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  </section>
</div>

<div class="grid grid-4">
  <div class="stat"><div class="stat-label">Hrubé tržby</div><div class="stat-value"><?= $v->money($summary['gross']) ?></div><div class="stat-note"><?= (int) $summary['count'] ?> plateb</div></div>
  <div class="stat"><div class="stat-label">Poplatky platforem</div><div class="stat-value"><?= $v->money($summary['fees']) ?></div></div>
  <div class="stat"><div class="stat-label">Náklady</div><div class="stat-value"><?= $v->money($summary['costs']) ?></div><div class="stat-note">z toho společné <?= $v->money($sharedCosts) ?></div></div>
  <div class="stat"><div class="stat-label">Noví předplatitelé</div><div class="stat-value"><?= (int) $summary['new_subs'] ?></div><div class="stat-note">zrušilo <?= (int) $summary['cancelled'] ?> (z API)</div></div>
</div>

<div class="grid grid-2">
  <section class="card">
    <div class="card-head"><h2>Modelky tento měsíc</h2><a class="small" href="<?= $v->url('/models') ?>">Všechny</a></div>
    <?php if ($models === []): ?>
      <p class="empty">Zatím žádná modelka.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Modelka</th><th class="num">Příjmy</th><th class="num">Náklady</th><th class="num">Zisk</th><th class="num">ROI</th></tr></thead>
      <tbody>
      <?php foreach ($models as $row): ?>
        <tr>
          <td><a href="<?= $v->url('/models/' . $row['id']) ?>"><?= $v->e($row['name']) ?></a> <span class="badge"><?= $v->label('model_status', $row['status']) ?></span></td>
          <td class="num"><?= $v->money($row['net']) ?></td>
          <td class="num"><?= $v->money($row['costs']) ?></td>
          <td class="num <?= $row['profit'] >= 0 ? 'pos' : 'neg' ?>"><?= $v->money($row['profit']) ?></td>
          <td class="num"><?= $row['roi'] === null ? '—' : $v->e(number_format($row['roi'], 0, ',', ' ')) . ' %' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>Kdo mi nejvíc vydělal</h2><a class="small" href="<?= $v->url('/fans', ['month' => $month]) ?>">Všichni</a></div>
    <?php if ($topFans === []): ?>
      <p class="empty">Zatím žádné platby s přiřazeným fanouškem.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Fanoušek</th><th>Modelka</th><th class="num">Plateb</th><th class="num">Čistě</th></tr></thead>
      <tbody>
      <?php foreach ($topFans as $fan): ?>
        <tr>
          <td><a href="<?= $v->url('/fans/' . $fan['id']) ?>"><?= $v->e($fan['display_name'] ?? $fan['handle'] ?? '—') ?></a><?= (int) $fan['is_top_spender'] === 1 ? ' <span class="badge badge-accent">top</span>' : '' ?></td>
          <td><?= $v->e($fan['model']) ?> <span class="muted small"><?= $v->e($fan['platform']) ?></span></td>
          <td class="num"><?= (int) $fan['payments'] ?></td>
          <td class="num"><?= $v->money((int) $fan['net']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
</div>

<div class="grid grid-3">
  <section class="card">
    <h2>Podle platformy</h2>
    <?php if ($accounts === []): ?>
      <p class="empty">Žádné výdělečné účty.</p>
    <?php else: ?>
    <table>
      <tbody>
      <?php foreach ($accounts as $account): ?>
        <tr>
          <td><a href="<?= $v->url('/accounts/' . $account['id']) ?>"><?= $v->e($account['platform']) ?></a><br><span class="muted small"><?= $v->e($account['model']) ?> · @<?= $v->e($account['handle']) ?></span></td>
          <td class="num"><?= $v->money((int) $account['net']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Podle typu platby</h2>
    <?php if ($types === []): ?>
      <p class="empty">Žádné platby.</p>
    <?php else: ?>
    <table>
      <tbody>
      <?php foreach ($types as $type): ?>
        <tr><td><?= $v->label('tx_type', $type['type']) ?> <span class="muted small">× <?= (int) $type['count'] ?></span></td><td class="num"><?= $v->money((int) $type['net']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Prokliky podle zdroje</h2>
    <?php if ($clicks === []): ?>
      <p class="empty">Žádné prokliky. Vytvoř <a href="<?= $v->url('/links/new') ?>">sledovací odkaz</a> do bia.</p>
    <?php else: ?>
    <table>
      <tbody>
      <?php foreach ($clicks as $click): ?>
        <tr><td><?= $v->label('link_source', $click['source']) ?></td><td class="num"><?= (int) $click['clicks'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
</div>
