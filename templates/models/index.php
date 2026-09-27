<?php /** @var App\Kernel\ViewHelpers $v */ ?>
<div class="page-head">
  <h1>Modelky</h1>
  <a class="btn btn-primary" href="<?= $v->url('/models/new') ?>">+ Přidat AI modelku</a>
</div>

<?php if ($models === []): ?>
  <div class="card">
    <p>Zatím žádná modelka. Inspiraci na koncepty, prompty a postup najdeš v <code>docs/03-modelky-koncepty-a-prompty.md</code>.</p>
    <a class="btn btn-primary" href="<?= $v->url('/models/new') ?>">+ Přidat AI modelku</a>
  </div>
<?php else: ?>
<div class="card table-wrap">
  <table>
    <thead><tr><th></th><th>Jméno</th><th>Nika</th><th>Stav</th><th class="num">Prompty</th><th class="num">Obrázky</th><th class="num">Účty</th><th>Stránka</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($models as $model): ?>
      <tr>
        <td><?php if ($model['avatar_image_id'] !== null): ?><img class="avatar" src="<?= $v->url('/images/' . $model['avatar_image_id']) ?>" alt="" loading="lazy" width="44" height="44"><?php else: ?><span class="avatar avatar-initials" aria-hidden="true"><?= $v->e(mb_strtoupper(mb_substr((string) $model['name'], 0, 1))) ?></span><?php endif; ?></td>
        <td><a href="<?= $v->url('/models/' . $model['id']) ?>"><strong><?= $v->e($model['name']) ?></strong></a><br><span class="muted small"><?= $v->e($model['tagline']) ?></span></td>
        <td><?= $v->e($model['niche']) ?></td>
        <td><span class="badge"><?= $v->label('model_status', $model['status']) ?></span></td>
        <td class="num"><?= (int) $model['prompt_count'] ?></td>
        <td class="num"><?= (int) $model['image_count'] ?></td>
        <td class="num"><?= (int) $model['account_count'] ?></td>
        <td><?= (int) $model['page_published'] === 1 ? '<span class="badge badge-good">zveřejněná</span>' : '<span class="badge">skrytá</span>' ?></td>
        <td class="num"><a class="small" href="<?= $v->url('/models/' . $model['id'] . '/edit') ?>">upravit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
