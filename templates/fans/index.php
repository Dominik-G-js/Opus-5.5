<?php /** @var App\Kernel\ViewHelpers $v */ ?>
<div class="page-head"><h1>Fanoušci — kdo mi kolik vydělal</h1></div>
<form method="get" action="<?= $v->url('/fans') ?>" class="filters">
  <div class="field"><label for="month">Měsíc</label><input type="text" id="month" name="month" placeholder="celá doba" pattern="\d{4}-\d{2}" value="<?= $v->e($month) ?>"></div>
  <div class="field"><label for="model">Modelka</label><select id="model" name="model"><?= $v->options($modelOptions, $modelId ?? '', true, '— všechny —') ?></select></div>
  <button type="submit" class="btn">Filtrovat</button>
</form>
<div class="card table-wrap">
  <?php if ($fans === []): ?>
    <p class="empty">Žádní fanoušci s platbami. U ručních plateb vyplň jméno fanouška, Fanvue API je doplní samo.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>#</th><th>Fanoušek</th><th>Modelka · platforma</th><th class="num">Plateb</th><th>Poslední platba</th><th class="num">Čistý příjem</th></tr></thead>
    <tbody>
    <?php foreach ($fans as $i => $fan): ?>
      <tr>
        <td class="muted"><?= $i + 1 ?></td>
        <td><a href="<?= $v->url('/fans/' . $fan['id']) ?>"><?= $v->e($fan['display_name'] ?? $fan['handle'] ?? '—') ?></a><?= (int) $fan['is_top_spender'] === 1 ? ' <span class="badge badge-accent">top</span>' : '' ?><?= $fan['handle'] ? '<br><span class="small muted">@' . $v->e($fan['handle']) . '</span>' : '' ?></td>
        <td><?= $v->e($fan['model']) ?> · <?= $v->e($fan['platform']) ?></td>
        <td class="num"><?= (int) $fan['payments'] ?></td>
        <td class="small"><?= $v->dateTime($fan['last_payment']) ?></td>
        <td class="num"><strong><?= $v->money((int) $fan['net']) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
