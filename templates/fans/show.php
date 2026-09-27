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
    <h2>Poznámky pro chat</h2>
    <form method="post" action="<?= $v->url('/fans/' . $fan['id']) ?>">
      <?= $v->csrfField() ?>
      <textarea name="notes" rows="6" aria-label="Poznámky" placeholder="Co má rád, o čem jste mluvili, jaký obsah kupuje…"><?= $v->e($fan['notes'] ?? '') ?></textarea>
      <p><button type="submit" class="btn">Uložit</button></p>
    </form>
  </section>
</div>
<section class="card table-wrap">
  <h2>Platby</h2>
  <table>
    <thead><tr><th>Kdy</th><th>Typ</th><th class="num">Hrubě</th><th class="num">Čistě</th><th class="num">V CZK</th></tr></thead>
    <tbody>
    <?php foreach ($transactions as $tx): ?>
      <tr>
        <td><?= $v->dateTime($tx['occurred_at']) ?></td>
        <td><?= $v->label('tx_type', $tx['type']) ?></td>
        <td class="num"><?= $v->money((int) $tx['gross_minor'], $tx['currency'], true) ?></td>
        <td class="num"><?= $v->money((int) $tx['net_minor'], $tx['currency'], true) ?></td>
        <td class="num"><?= $v->money((int) $tx['net_czk_minor']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
