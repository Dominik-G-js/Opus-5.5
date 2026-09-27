<?php /** @var App\Kernel\ViewHelpers $v */ ?>
<div class="page-head">
  <h1>AI nástroje</h1>
  <a class="btn btn-primary" href="<?= $v->url('/tools/new') ?>">+ Nástroj</a>
</div>
<p class="muted small">Katalog nástrojů s cenami (ověřeno 09/2026 — ceny se často mění, kontroluj je). Útrata se počítá z nákladů přiřazených k nástroji.</p>
<div class="card table-wrap">
  <table>
    <thead><tr><th>Nástroj</th><th>Kategorie</th><th>Cena</th><th>Modelky</th><th class="num">Utraceno</th><th class="num">Cena / kus</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tools as $tool): ?>
      <tr>
        <td><strong><?= $v->e($tool['name']) ?></strong><?php if ($tool['url']): ?> <a class="small" href="<?= $v->e($tool['url']) ?>" target="_blank" rel="noopener noreferrer">web ↗</a><?php endif; ?><br><span class="small muted"><?= $v->e($tool['notes'] ?? '') ?></span></td>
        <td><?= $v->label('tool_category', $tool['category']) ?></td>
        <td class="small"><?= $v->label('pricing_model', $tool['pricing_model']) ?><?= $tool['monthly_price_minor'] !== null ? '<br>' . $v->money((int) $tool['monthly_price_minor'], $tool['currency'], true) . '/měs.' : '' ?></td>
        <td class="small"><?= $v->e($tool['models'] ?? '—') ?></td>
        <td class="num"><?= $v->money((int) $tool['spent']) ?></td>
        <td class="num"><?= (int) $tool['quantity'] > 0 ? $v->money((int) round((int) $tool['spent_with_quantity'] / (int) $tool['quantity']), 'CZK', true) : '—' ?></td>
        <td class="num"><a class="small" href="<?= $v->url('/tools/' . $tool['id'] . '/edit') ?>">upravit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
