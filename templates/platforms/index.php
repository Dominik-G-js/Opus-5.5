<?php /** @var App\Kernel\ViewHelpers $v */ ?>
<div class="page-head">
  <h1>Platformy</h1>
  <a class="btn btn-primary" href="<?= $v->url('/platforms/new') ?>">+ Platforma</a>
</div>
<p class="muted small">Pravidla pro AI obsah se často mění — před založením účtu si vždy přečti aktuální podmínky platformy. Podrobnosti a zdroje v <code>docs/01-platformy-a-pravidla.md</code>.</p>
<div class="card table-wrap">
  <table>
    <thead><tr><th>Platforma</th><th>Role</th><th>AI persona</th><th class="num">Poplatek</th><th class="num">Účty</th><th>Poznámka</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($platforms as $platform):
        $policyClass = ['allowed' => 'badge-good', 'restricted' => 'badge-warn', 'banned' => 'badge-bad'][$platform['ai_policy']] ?? '';
        ?>
      <tr>
        <td><strong><?= $v->e($platform['name']) ?></strong></td>
        <td><?= $v->label('platform_role', $platform['role']) ?></td>
        <td><span class="badge <?= $policyClass ?>"><?= $v->label('ai_policy', $platform['ai_policy']) ?></span></td>
        <td class="num"><?= $v->e(rtrim(rtrim(number_format((float) $platform['default_fee_percent'], 1, ',', ''), '0'), ',')) ?> %</td>
        <td class="num"><?= (int) $platform['account_count'] ?></td>
        <td class="small"><?= $v->e($platform['notes'] ?? '') ?></td>
        <td class="num"><a class="small" href="<?= $v->url('/platforms/' . $platform['id'] . '/edit') ?>">upravit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
