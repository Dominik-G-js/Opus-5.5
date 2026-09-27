<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var array<string, mixed> $model */
$id = (int) $model['id'];
$masterPrompts = array_filter($prompts, static fn (array $p): bool => (int) $p['is_master'] === 1);
?>
<div class="page-head">
  <h1>
    <?php if ($model['avatar_image_id'] !== null): ?><img class="avatar" src="<?= $v->url('/images/' . $model['avatar_image_id']) ?>" alt=""><?php endif; ?>
    <?= $v->e($model['name']) ?>
    <span class="badge"><?= $v->label('model_status', $model['status']) ?></span>
  </h1>
  <div class="actions">
    <a class="btn" href="<?= $v->url('/models/' . $id . '/bible') ?>">Character bible</a>
    <?php if ((int) $model['page_published'] === 1): ?><a class="btn" href="<?= $v->e($pageUrl) ?>" target="_blank" rel="noopener">Landing page ↗</a><?php endif; ?>
    <a class="btn btn-primary" href="<?= $v->url('/models/' . $id . '/edit') ?>">Upravit profil</a>
  </div>
</div>

<div class="grid grid-4">
  <div class="stat"><div class="stat-label">Vydělala celkem</div><div class="stat-value"><?= $v->money($lifetime['net']) ?></div><div class="stat-note">po poplatcích platforem</div></div>
  <div class="stat"><div class="stat-label">Stála celkem</div><div class="stat-value"><?= $v->money($lifetime['costs']) ?></div><div class="stat-note">z toho tvorba <?= $v->money($lifetime['creation_costs']) ?></div></div>
  <div class="stat"><div class="stat-label">Zisk celkem</div><div class="stat-value <?= $lifetime['profit'] >= 0 ? 'pos' : 'neg' ?>"><?= $v->money($lifetime['profit']) ?></div><div class="stat-note">tento měsíc <?= $v->money($monthSummary['profit']) ?></div></div>
  <div class="stat"><div class="stat-label">Cena za 1 obrázek</div><div class="stat-value"><?= $lifetime['cost_per_image'] === null ? '—' : $v->money($lifetime['cost_per_image'], 'CZK', true) ?></div><div class="stat-note"><?= (int) $lifetime['generated_images'] ?> vygenerovaných (z nákladů s počtem kusů)</div></div>
</div>

<div class="grid grid-2">
  <section class="card">
    <h2>Profil</h2>
    <dl class="kv">
      <dt>Věk postavy</dt><dd><?= (int) $model['persona_age'] ?></dd>
      <dt>Nika</dt><dd><?= $v->e($model['niche'] ?? '—') ?></dd>
      <dt>Slogan</dt><dd><?= $v->e($model['tagline'] ?? '—') ?></dd>
      <dt>Obličej</dt><dd><?= $v->nl2br($model['look_face'] ?? '—') ?></dd>
      <dt>Vlasy</dt><dd><?= $v->nl2br($model['look_hair'] ?? '—') ?></dd>
      <dt>Oči</dt><dd><?= $v->nl2br($model['look_eyes'] ?? '—') ?></dd>
      <dt>Pleť</dt><dd><?= $v->nl2br($model['look_skin'] ?? '—') ?></dd>
      <dt>Postava</dt><dd><?= $v->nl2br($model['look_body'] ?? '—') ?></dd>
      <dt>Znaky</dt><dd><?= $v->nl2br($model['look_marks'] ?? '—') ?></dd>
      <dt>Styl</dt><dd><?= $v->nl2br($model['look_style'] ?? '—') ?></dd>
    </dl>
  </section>

  <section class="card">
    <h2>Generování</h2>
    <dl class="kv">
      <dt>Základní model</dt><dd><?= $v->e($model['base_model'] ?? '—') ?></dd>
      <dt>LoRA</dt><dd><?= $v->e($model['lora_name'] ?? '—') ?><?= $model['lora_weight'] ? ' · síla ' . $v->e($model['lora_weight']) : '' ?></dd>
      <dt>Trigger</dt><dd><code><?= $v->e($model['lora_trigger'] ?? '—') ?></code></dd>
      <dt>Uložení LoRA</dt><dd><?= $v->e($model['lora_location'] ?? '—') ?></dd>
      <dt>Výchozí seed</dt><dd><code><?= $v->e($model['default_seed'] ?? '—') ?></code></dd>
    </dl>
    <h3 class="mt-1">Použité AI nástroje</h3>
    <?php if ($tools === []): ?>
      <p class="empty">Zatím žádný přiřazený nástroj.</p>
    <?php else: ?>
      <table><tbody>
      <?php foreach ($tools as $tool): ?>
        <tr>
          <td><strong><?= $v->e($tool['name']) ?></strong> <span class="badge"><?= $v->label('tool_category', $tool['category']) ?></span><br><span class="muted small"><?= $v->e($tool['purpose'] ?? '') ?></span></td>
          <td class="num">
            <form method="post" action="<?= $v->url('/models/' . $id . '/tools/' . $tool['id'] . '/detach') ?>" class="inline">
              <?= $v->csrfField() ?><button type="submit" class="link-btn danger small">odebrat</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
    <form method="post" action="<?= $v->url('/models/' . $id . '/tools') ?>" class="filters mt-1">
      <?= $v->csrfField() ?>
      <div class="field"><label for="tool_id">Nástroj</label><select id="tool_id" name="tool_id"><?= $v->options($toolOptions, '', true, '— vyber —') ?></select></div>
      <div class="field"><label for="purpose">K čemu</label><input type="text" id="purpose" name="purpose" placeholder="např. trénink LoRA"></div>
      <button type="submit" class="btn">Přiřadit</button>
    </form>
  </section>
</div>

<section class="card" id="prompts">
  <div class="card-head">
    <h2>Prompty (<?= count($prompts) ?>)</h2>
    <div class="actions">
      <a class="btn btn-sm" href="<?= $v->url('/models/' . $id . '/prompts/new', ['kind' => 'character_base']) ?>">+ Master prompt postavy</a>
      <a class="btn btn-sm btn-primary" href="<?= $v->url('/models/' . $id . '/prompts/new') ?>">+ Prompt</a>
    </div>
  </div>
  <?php if ($masterPrompts === []): ?>
    <div class="callout">Chybí <strong>master prompt postavy</strong>. Je to základ konzistence — stejný popis obličeje a těla v každém generování.</div>
  <?php endif; ?>
  <?php if ($prompts !== []): ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Název</th><th>Typ</th><th>Nástroj</th><th>Hodnocení</th><th>Upraveno</th></tr></thead>
    <tbody>
    <?php foreach ($prompts as $prompt): ?>
      <tr>
        <td><a href="<?= $v->url('/prompts/' . $prompt['id'] . '/edit') ?>"><?= $v->e($prompt['title']) ?></a><?= (int) $prompt['is_master'] === 1 ? ' <span class="badge badge-accent">master</span>' : '' ?></td>
        <td><?= $v->label('prompt_kind', $prompt['kind']) ?></td>
        <td><?= $v->e($prompt['tool_name'] ?? '—') ?></td>
        <td><?= $prompt['rating'] !== null ? str_repeat('★', (int) $prompt['rating']) : '—' ?></td>
        <td class="small muted"><?= $v->dateTime($prompt['updated_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="card" id="images">
  <div class="card-head"><h2>Obrázky (<?= count($images) ?>)</h2></div>
  <form method="post" action="<?= $v->url('/models/' . $id . '/images') ?>" enctype="multipart/form-data" class="filters">
    <?= $v->csrfField() ?>
    <div class="field"><label for="image">Soubor (JPG, PNG, WebP do 15 MB)</label><input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" required></div>
    <div class="field"><label for="img_prompt">Prompt</label><select id="img_prompt" name="prompt_id"><?= $v->options($promptOptions, '', true) ?></select></div>
    <div class="field"><label for="img_tool">Nástroj</label><select id="img_tool" name="tool_id"><?= $v->options($toolOptions, '', true) ?></select></div>
    <div class="field"><label for="img_seed">Seed</label><input type="text" id="img_seed" name="seed" maxlength="50"></div>
    <div class="field"><label for="img_alt">Popis (alt)</label><input type="text" id="img_alt" name="alt_text" maxlength="300"></div>
    <label class="check small"><input type="checkbox" name="is_reference" value="1"> referenční</label>
    <button type="submit" class="btn btn-primary">Nahrát</button>
  </form>
  <p class="hint">Originály jsou soukromé (i s metadaty z generátoru). „Zveřejnit“ vytvoří zmenšenou kopii bez metadat pro landing page — jen SFW obrázky.</p>
  <?php if ($images !== []): ?>
  <div class="gallery">
    <?php foreach ($images as $image): ?>
      <div class="tile">
        <a href="<?= $v->url('/images/' . $image['id']) ?>" target="_blank" rel="noopener"><img src="<?= $v->url('/images/' . $image['id']) ?>" alt="<?= $v->e($image['alt_text'] ?? '') ?>" loading="lazy" width="<?= (int) $image['width'] ?>" height="<?= (int) $image['height'] ?>"></a>
        <div class="tile-body">
          <?= (int) $image['is_reference'] === 1 ? '<span class="badge badge-accent">reference</span> ' : '' ?>
          <?= (int) $image['is_public'] === 1 ? '<span class="badge badge-good">veřejný</span>' : '' ?>
          <div class="muted"><?= (int) $image['width'] ?>×<?= (int) $image['height'] ?><?= $image['seed'] ? ' · seed ' . $v->e($image['seed']) : '' ?></div>
          <form method="post" action="<?= $v->url('/images/' . $image['id']) ?>">
            <?= $v->csrfField() ?>
            <input type="text" name="alt_text" value="<?= $v->e($image['alt_text'] ?? '') ?>" placeholder="popis (alt)" maxlength="300" aria-label="Popis obrázku">
            <label class="check"><input type="checkbox" name="is_reference" value="1"<?= $v->checked((int) $image['is_reference'] === 1) ?>> reference</label>
            <label class="check"><input type="checkbox" name="is_public" value="1"<?= $v->checked((int) $image['is_public'] === 1) ?>> zveřejnit (SFW)</label>
            <button type="submit" class="btn btn-sm">Uložit</button>
          </form>
          <form method="post" action="<?= $v->url('/images/' . $image['id'] . '/delete') ?>" data-confirm="Smazat obrázek?">
            <?= $v->csrfField() ?><button type="submit" class="link-btn danger">smazat</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<div class="grid grid-2">
  <section class="card">
    <div class="card-head"><h2>Účty na platformách</h2><a class="btn btn-sm btn-primary" href="<?= $v->url('/models/' . $id . '/accounts/new') ?>">+ Účet</a></div>
    <?php if ($accounts === []): ?>
      <p class="empty">Žádné účty. Začni Fanvue (výdělek) + X, TikTok, Reddit (návštěvnost).</p>
    <?php else: ?>
    <table><tbody>
      <?php foreach ($accounts as $account): ?>
        <tr>
          <td>
            <a href="<?= $v->url('/accounts/' . $account['id']) ?>"><?= $v->e($account['platform']) ?> · @<?= $v->e($account['handle']) ?></a>
            <?php if ($account['ai_policy'] === 'banned'): ?><span class="badge badge-bad">AI persona zakázána</span><?php endif; ?>
            <br><span class="small muted"><?= $v->label('platform_role', $account['role']) ?> · <?= $v->label('account_status', $account['status']) ?></span>
          </td>
          <td class="num small"><?= $account['integration'] === 'fanvue' ? '<span class="badge badge-good">API</span>' : '<span class="badge">ručně</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>Sledovací odkazy</h2><a class="btn btn-sm" href="<?= $v->url('/links/new', ['model' => $id]) ?>">+ Odkaz</a></div>
    <?php if ($links === []): ?>
      <p class="empty">Žádné odkazy. Každý zdroj (TikTok bio, X bio, Reddit) má mít vlastní odkaz — uvidíš, co funguje.</p>
    <?php else: ?>
    <table><tbody>
      <?php foreach ($links as $link): ?>
        <tr><td><a href="<?= $v->url('/links/' . $link['id'] . '/edit') ?>"><?= $v->e($link['label']) ?></a> <span class="muted small"><?= $v->label('link_source', $link['source']) ?></span></td><td class="num"><?= (int) $link['clicks'] ?> prokliků</td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </section>
</div>

<div class="grid grid-2">
  <section class="card">
    <div class="card-head"><h2>Poslední náklady</h2><a class="btn btn-sm" href="<?= $v->url('/costs/new', ['model' => $id]) ?>">+ Náklad</a></div>
    <?php if ($costs === []): ?>
      <p class="empty">Žádné náklady. Zapisuj trénink LoRA, generování (i s počtem obrázků) a předplatné nástrojů.</p>
    <?php else: ?>
    <table><tbody>
      <?php foreach ($costs as $cost): ?>
        <tr>
          <td><?= $v->date($cost['incurred_on']) ?><br><span class="small muted"><?= $v->label('cost_category', $cost['category']) ?><?= $cost['tool_name'] ? ' · ' . $v->e($cost['tool_name']) : '' ?><?= $cost['quantity'] ? ' · ' . (int) $cost['quantity'] . ' ks' : '' ?></span></td>
          <td class="num"><?= $v->money((int) $cost['amount_czk_minor'], 'CZK', true) ?><?php if ($cost['currency'] !== 'CZK'): ?><br><span class="small muted"><?= $v->money((int) $cost['amount_minor'], $cost['currency'], true) ?></span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <?php if ($lifetime['costs_by_category'] !== []): ?>
      <h3 class="mt-1">Náklady podle kategorie (celkem)</h3>
      <table><tbody>
        <?php foreach ($lifetime['costs_by_category'] as $row): ?>
          <tr><td><?= $v->label('cost_category', $row['category']) ?></td><td class="num"><?= $v->money((int) $row['total']) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Nejlepší fanoušci</h2>
    <?php if ($topFans === []): ?>
      <p class="empty">Zatím nikdo.</p>
    <?php else: ?>
    <table><tbody>
      <?php foreach ($topFans as $fan): ?>
        <tr><td><a href="<?= $v->url('/fans/' . $fan['id']) ?>"><?= $v->e($fan['display_name'] ?? $fan['handle'] ?? '—') ?></a> <span class="muted small"><?= $v->e($fan['platform']) ?></span></td><td class="num"><?= $v->money((int) $fan['net']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </section>
</div>

<?php if (!empty($model['notes'])): ?>
<section class="card"><h2>Poznámky</h2><p><?= $v->nl2br($model['notes']) ?></p></section>
<?php endif; ?>
