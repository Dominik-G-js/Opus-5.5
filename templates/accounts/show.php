<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var array<string, mixed> $account */
$id = (int) $account['id'];
$isFanvue = $account['platform'] === 'Fanvue';
$connected = $account['integration'] === 'fanvue' && !empty($account['credentials_enc']);
?>
<div class="page-head">
  <h1><?= $v->e($account['platform']) ?> · @<?= $v->e($account['handle']) ?></h1>
  <div class="actions">
    <a class="btn" href="<?= $v->url('/models/' . $account['model_id']) ?>"><?= $v->e($account['model_name']) ?></a>
    <a class="btn" href="<?= $v->url('/earnings/new', ['account' => $id]) ?>">+ Příjem</a>
    <a class="btn" href="<?= $v->url('/earnings/import') ?>">Import CSV</a>
    <a class="btn btn-primary" href="<?= $v->url('/accounts/' . $id . '/edit') ?>">Upravit</a>
  </div>
</div>

<?php if ($account['ai_policy'] === 'banned'): ?>
  <div class="callout callout-bad"><?= $v->e($account['platform_notes'] ?? 'Tato platforma nepovoluje AI persony.') ?></div>
<?php endif; ?>

<div class="grid grid-3">
  <div class="stat"><div class="stat-label">Tento měsíc (po poplatcích)</div><div class="stat-value"><?= $v->money($monthNet) ?></div></div>
  <div class="stat"><div class="stat-label">Celkem</div><div class="stat-value"><?= $v->money($lifetimeNet) ?></div></div>
  <div class="stat"><div class="stat-label">Poplatek · měna</div><div class="stat-value"><?= $v->e(rtrim(rtrim(number_format((float) $account['fee_percent'], 1, ',', ''), '0'), ',')) ?> % · <?= $v->e($account['currency']) ?></div><div class="stat-note"><?= $v->label('account_status', $account['status']) ?></div></div>
</div>

<?php if ($isFanvue): ?>
<section class="card">
  <h2>Fanvue API</h2>
  <?php if (!$fanvueConfigured): ?>
    <p>Pro automatické stahování příjmů si ve Fanvue developer portálu vytvoř OAuth aplikaci a vyplň <code>fanvue.client_id</code> a <code>fanvue.client_secret</code> v <code>config/config.php</code>.</p>
    <p class="small">Redirect URI pro registraci: <code><?= $v->e($redirectUri) ?></code></p>
  <?php elseif (!$connected): ?>
    <p>Účet zatím není připojený. Po připojení se stahují platby (s fanouškem a typem) a denní počty nových předplatitelů.</p>
    <form method="post" action="<?= $v->url('/accounts/' . $id . '/fanvue/connect') ?>"><?= $v->csrfField() ?><button type="submit" class="btn btn-primary">Připojit Fanvue</button></form>
  <?php else: ?>
    <dl class="kv">
      <dt>Stav</dt><dd><span class="badge badge-good">připojeno</span></dd>
      <dt>Poslední synchronizace</dt><dd><?= $v->dateTime($account['last_synced_at']) ?></dd>
      <?php if ($account['last_sync_error']): ?><dt>Chyba</dt><dd class="neg"><?= $v->e($account['last_sync_error']) ?></dd><?php endif; ?>
    </dl>
    <div class="actions mt-1">
      <form method="post" action="<?= $v->url('/accounts/' . $id . '/sync') ?>" class="inline"><?= $v->csrfField() ?><button type="submit" class="btn btn-primary">Synchronizovat teď</button></form>
      <form method="post" action="<?= $v->url('/accounts/' . $id . '/fanvue/connect') ?>" class="inline"><?= $v->csrfField() ?><button type="submit" class="btn">Připojit znovu</button></form>
      <form method="post" action="<?= $v->url('/accounts/' . $id . '/disconnect') ?>" class="inline" data-confirm="Odpojit Fanvue? Stažená data zůstanou."><?= $v->csrfField() ?><button type="submit" class="btn btn-danger">Odpojit</button></form>
    </div>
    <p class="hint">Automaticky: nastav cron <code>php bin/console sync</code> (např. každou hodinu). Fanvue vrací částky v centech; měna účtu je <?= $v->e($account['currency']) ?>.</p>
  <?php endif; ?>
  <?php if ($syncRuns !== []): ?>
    <details class="mt-1"><summary>Historie synchronizací</summary>
      <table><tbody>
      <?php foreach ($syncRuns as $run): ?>
        <tr><td class="small"><?= $v->dateTime($run['started_at']) ?></td><td><span class="badge <?= $run['status'] === 'ok' ? 'badge-good' : ($run['status'] === 'error' ? 'badge-bad' : '') ?>"><?= $v->e($run['status']) ?></span></td><td class="num"><?= (int) $run['imported'] ?></td><td class="small"><?= $v->e($run['message'] ?? '') ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </details>
  <?php endif; ?>
</section>
<?php else: ?>
<section class="card small">
  <h2>Jak sem dostat data</h2>
  <p><?= $v->e($account['platform']) ?> nemá oficiální API pro tvůrce, takže příjmy zadávej <a href="<?= $v->url('/earnings/new', ['account' => $id]) ?>">ručně</a> nebo nahraj <a href="<?= $v->url('/earnings/import') ?>">CSV export</a> z platformy. Neoficiální „API“ služby porušují podmínky platforem a hrozí ban účtu.</p>
</section>
<?php endif; ?>

<div class="grid grid-2">
  <section class="card">
    <h2>Fanoušci podle útraty</h2>
    <?php if ($fans === []): ?><p class="empty">Zatím žádní.</p><?php else: ?>
    <table><tbody>
      <?php foreach ($fans as $fan): ?>
        <tr><td><a href="<?= $v->url('/fans/' . $fan['id']) ?>"><?= $v->e($fan['display_name'] ?? $fan['handle'] ?? $fan['external_id']) ?></a><?= (int) $fan['is_top_spender'] === 1 ? ' <span class="badge badge-accent">top</span>' : '' ?></td><td class="num small"><?= (int) $fan['payments'] ?>×</td><td class="num"><?= $v->money((int) $fan['net']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </section>
  <section class="card">
    <h2>Poslední platby</h2>
    <?php if ($transactions === []): ?><p class="empty">Zatím žádné.</p><?php else: ?>
    <div class="table-wrap"><table><tbody>
      <?php foreach ($transactions as $tx): ?>
        <tr>
          <td class="small"><?= $v->dateTime($tx['occurred_at']) ?><br><span class="muted"><?= $v->label('tx_type', $tx['type']) ?><?= $tx['fan_name'] || $tx['fan_handle'] ? ' · ' . $v->e($tx['fan_name'] ?? $tx['fan_handle']) : '' ?></span></td>
          <td class="num"><?= $v->money((int) $tx['net_minor'], $tx['currency'], true) ?><br><span class="small muted"><?= $v->money((int) $tx['net_czk_minor']) ?></span></td>
          <td class="num"><?php if (empty($tx['locked'])): ?><a class="small" href="<?= $v->url('/earnings/' . $tx['id'] . '/edit') ?>">upravit</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </section>
</div>
