<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var array<string, mixed> $fan */
?>
<div class="page-head">
  <h1><?= $v->e($fan['display_name'] ?? $fan['handle'] ?? $fan['external_id']) ?><?= (int) $fan['is_top_spender'] === 1 ? ' <span class="badge badge-accent">top spender</span>' : '' ?></h1>
  <a class="btn" href="<?= $v->url('/accounts/' . $fan['account_id']) ?>"><?= $v->e($fan['model']) ?> · <?= $v->e($fan['platform']) ?></a>
</div>
<div class="grid grid-2">
  <section class="card">
    <h2>Utratil celkem <?= $v->money($total) ?></h2>
    <table><tbody>
      <?php foreach ($byType as $row): ?>
        <tr><td><?= $v->label('tx_type', $row['type']) ?> <span class="muted small">× <?= (int) $row['count'] ?></span></td><td class="num"><?= $v->money((int) $row['net']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <dl class="kv mt-1">
      <dt>Uživatel</dt><dd><?= $fan['handle'] ? '@' . $v->e($fan['handle']) : '—' ?></dd>
      <dt>Poprvé viděn</dt><dd><?= $v->dateTime($fan['first_seen_at']) ?></dd>
    </dl>
  </section>
  <section class="card">
    <h2>Údaje a poznámky pro chat</h2>
    <form method="post" action="<?= $v->url('/fans/' . $fan['id']) ?>">
      <?= $v->csrfField() ?>
      <div class="form-grid">
        <div class="field"><label for="display_name">Jméno</label><input type="text" id="display_name" name="display_name" maxlength="150" value="<?= $v->old('display_name', $fan['display_name'] ?? '') ?>"><?= $v->error('display_name') ?></div>
        <div class="field"><label for="handle">Uživatelské jméno</label><input type="text" id="handle" name="handle" maxlength="150" value="<?= $v->old('handle', $fan['handle'] ?? '') ?>"><?= $v->error('handle') ?></div>
        <div class="field full check"><input type="checkbox" id="is_top_spender" name="is_top_spender" value="1"<?= $v->checked((bool) $v->oldRaw('is_top_spender', (int) $fan['is_top_spender'] === 1)) ?>><label for="is_top_spender">Top spender</label></div>
        <div class="field full"><label for="notes">Poznámky</label><textarea id="notes" name="notes" rows="6" placeholder="Co má rád, o čem jste mluvili, jaký obsah kupuje…"><?= $v->old('notes', $fan['notes'] ?? '') ?></textarea><?= $v->error('notes') ?></div>
      </div>
      <?php if (!str_starts_with((string) $fan['external_id'], 'manual:') && !str_starts_with((string) $fan['external_id'], 'csv:')): ?>
        <p class="hint">Jméno a uživatelské jméno z Fanvue se při synchronizaci aktualizují podle Fanvue. Poznámky zůstanou.</p>
      <?php endif; ?>
      <p><button type="submit" class="btn btn-primary">Uložit</button></p>
    </form>
    <form method="post" action="<?= $v->url('/fans/' . $fan['id'] . '/delete') ?>" data-confirm="Smazat fanouška? Jeho platby zůstanou v příjmech, jen bez jména.">
      <?= $v->csrfField() ?><button type="submit" class="link-btn danger">Smazat fanouška</button>
    </form>
  </section>
</div>
<section class="card table-wrap">
  <h2>Platby</h2>
  <table>
    <thead><tr><th>Kdy</th><th>Typ</th><th class="num">Hrubě</th><th class="num">Čistě</th><th class="num">V CZK</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($transactions as $tx): ?>
      <tr>
        <td><?= $v->dateTime($tx['occurred_at']) ?></td>
        <td><?= $v->label('tx_type', $tx['type']) ?></td>
        <td class="num"><?= $v->money((int) $tx['gross_minor'], $tx['currency'], true) ?></td>
        <td class="num"><?= $v->money((int) $tx['net_minor'], $tx['currency'], true) ?></td>
        <td class="num"><?= $v->money((int) $tx['net_czk_minor']) ?></td>
        <td class="num"><?php if (empty($tx['locked'])): ?><a class="small" href="<?= $v->url('/earnings/' . $tx['id'] . '/edit') ?>">upravit</a><?php else: ?><span class="small muted">z API</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
