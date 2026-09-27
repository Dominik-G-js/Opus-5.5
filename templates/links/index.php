<?php /** @var App\Kernel\ViewHelpers $v */ ?>
<div class="page-head">
  <h1>Odkazy a prokliky</h1>
  <a class="btn btn-primary" href="<?= $v->url('/links/new') ?>">+ Odkaz</a>
</div>
<p class="muted small">Každý zdroj návštěvnosti (bio na TikToku, X, Redditu, Instagramu…) má mít vlastní odkaz. Počítají se prokliky po dnech (bez cookies a bez ukládání IP). Na Fanvue navíc vytvoř tracking link a dej ho jako cíl — uvidíš i konverze.</p>
<div class="card table-wrap">
  <?php if ($links === []): ?>
    <p class="empty">Zatím žádné odkazy.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Modelka</th><th>Odkaz</th><th>Zdroj</th><th>Sledovací adresa</th><th class="num">30 dní</th><th class="num">Celkem</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($links as $link): ?>
      <tr>
        <td><?= $v->e($link['model_name']) ?></td>
        <td><strong><?= $v->e($link['label']) ?></strong>
          <?= (int) $link['is_active'] === 1 ? '' : ' <span class="badge badge-warn">vypnutý</span>' ?>
          <?= (int) $link['is_premium'] === 1 ? ' <span class="badge">18+</span>' : '' ?>
          <?= (int) $link['show_on_page'] === 1 ? ' <span class="badge badge-accent">na stránce</span>' : '' ?>
          <br><span class="small muted"><?= $v->e(App\Support\Str::limit((string) $link['target_url'], 60)) ?></span></td>
        <td><?= $v->label('link_source', $link['source']) ?></td>
        <td><code id="link-<?= (int) $link['id'] ?>"><?= $v->e($link['tracking_url']) ?></code> <button type="button" class="btn btn-sm" data-copy="#link-<?= (int) $link['id'] ?>">Kopírovat</button></td>
        <td class="num"><?= (int) $link['clicks_30'] ?></td>
        <td class="num"><?= (int) $link['clicks_total'] ?></td>
        <td class="num"><a class="small" href="<?= $v->url('/links/' . $link['id'] . '/edit') ?>">upravit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
