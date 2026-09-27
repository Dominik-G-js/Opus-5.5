<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var array<string, mixed> $model */
/** @var array<string, mixed>|null $prompt */
$p = $prompt ?? [];
$val = static fn (string $key, mixed $default = '') => $v->old($key, $p[$key] ?? $default);
$action = $prompt === null ? $v->url('/models/' . $model['id'] . '/prompts') : $v->url('/prompts/' . $prompt['id']);
$defaultPrompt = '';
if ($prompt === null && $defaultKind === 'character_base') {
    // Předvyplnění master promptu z character bible.
    $parts = array_filter([
        $model['lora_trigger'] ?? null,
        'photo of a ' . (int) $model['persona_age'] . '-year-old woman',
        $model['look_face'] ?? null,
        $model['look_eyes'] ?? null,
        $model['look_hair'] ?? null,
        $model['look_skin'] ?? null,
        $model['look_body'] ?? null,
        $model['look_marks'] ?? null,
    ]);
    $defaultPrompt = implode(', ', $parts);
}
?>
<div class="page-head">
  <h1><?= $v->e($title) ?></h1>
  <div class="actions">
    <?php if ($prompt !== null): ?>
      <form method="post" action="<?= $v->url('/prompts/' . $prompt['id'] . '/duplicate') ?>" class="inline"><?= $v->csrfField() ?><button type="submit" class="btn">Duplikovat</button></form>
    <?php endif; ?>
    <a class="btn" href="<?= $v->url('/models/' . $model['id']) ?>#prompts">Zpět na <?= $v->e($model['name']) ?></a>
  </div>
</div>

<div class="grid grid-2">
<section class="card">
<form method="post" action="<?= $action ?>">
  <?= $v->csrfField() ?>
  <div class="form-grid">
    <div class="field">
      <label for="title">Název *</label>
      <input type="text" id="title" name="title" required maxlength="150" value="<?= $val('title', $defaultKind === 'character_base' ? 'Master — ' . $model['name'] : '') ?>">
      <?= $v->error('title') ?>
    </div>
    <div class="field">
      <label for="kind">Typ</label>
      <select id="kind" name="kind"><?= $v->options(App\Support\Labels::group('prompt_kind'), $v->oldRaw('kind', $p['kind'] ?? $defaultKind)) ?></select>
    </div>
    <div class="field full">
      <label for="prompt">Prompt *</label>
      <textarea class="prompt" id="prompt" name="prompt" required rows="10"><?= $val('prompt', $defaultPrompt) ?></textarea>
      <p class="hint">Struktura: trigger + popis postavy (vždy stejný) → scéna → oblečení → světlo → fotoaparát/objektiv → realismus (textura pleti, nedokonalosti).</p>
      <?= $v->error('prompt') ?>
    </div>
    <div class="field full">
      <label for="negative_prompt">Negativní prompt</label>
      <textarea class="prompt" id="negative_prompt" name="negative_prompt" rows="3"><?= $val('negative_prompt', $prompt === null ? ($model['default_negative'] ?? '') : '') ?></textarea>
      <?= $v->error('negative_prompt') ?>
    </div>
    <div class="field">
      <label for="tool_id">Nástroj</label>
      <select id="tool_id" name="tool_id"><?= $v->options($toolOptions, $v->oldRaw('tool_id', $p['tool_id'] ?? ''), true) ?></select>
    </div>
    <div class="field">
      <label for="seed">Seed</label>
      <input type="text" id="seed" name="seed" maxlength="50" value="<?= $val('seed', $prompt === null ? ($model['default_seed'] ?? '') : '') ?>">
    </div>
    <div class="field full">
      <label for="settings">Nastavení</label>
      <textarea id="settings" name="settings" rows="2" placeholder="steps 28, cfg 3.5, sampler euler, 832×1216, LoRA 0.8"><?= $val('settings') ?></textarea>
    </div>
    <div class="field">
      <label for="rating">Hodnocení výsledku</label>
      <select id="rating" name="rating"><?= $v->options([5 => '★★★★★', 4 => '★★★★', 3 => '★★★', 2 => '★★', 1 => '★'], $v->oldRaw('rating', $p['rating'] ?? ''), true, '— nehodnoceno —') ?></select>
    </div>
    <div class="field check">
      <input type="checkbox" id="is_master" name="is_master" value="1"<?= $v->checked((bool) $v->oldRaw('is_master', $p['is_master'] ?? ($defaultKind === 'character_base' ? 1 : 0))) ?>>
      <label for="is_master">Master (hlavní prompt tohoto typu)</label>
    </div>
    <div class="field full">
      <label for="notes">Poznámky</label>
      <textarea id="notes" name="notes" rows="3" placeholder="Co fungovalo, co ne, na jaké platformě to prošlo…"><?= $val('notes') ?></textarea>
    </div>
  </div>
  <p><button type="submit" class="btn btn-primary">Uložit</button></p>
</form>
<?php if ($prompt !== null): ?>
  <form method="post" action="<?= $v->url('/prompts/' . $prompt['id'] . '/delete') ?>" data-confirm="Smazat prompt i s historií?">
    <?= $v->csrfField() ?><button type="submit" class="link-btn danger">Smazat prompt</button>
  </form>
<?php endif; ?>
</section>

<section class="card">
  <?php if ($prompt !== null): ?>
    <h2>Aktuální text</h2>
    <div class="prompt-box">
      <pre id="current-prompt"><?= $v->e($prompt['prompt']) ?></pre>
      <button type="button" class="btn btn-sm copy-btn" data-copy="#current-prompt">Kopírovat</button>
    </div>
    <h2>Historie verzí</h2>
    <?php if ($versions === []): ?>
      <p class="empty">Žádné starší verze. Při každé změně textu se předchozí verze uloží sem.</p>
    <?php endif; ?>
    <?php foreach ($versions as $version): ?>
      <details>
        <summary><?= $v->dateTime($version['created_at']) ?><?= $version['seed'] ? ' · seed ' . $v->e($version['seed']) : '' ?></summary>
        <pre><?= $v->e($version['prompt']) ?></pre>
        <?php if ($version['negative_prompt']): ?><p class="small"><strong>Negativní:</strong> <?= $v->e($version['negative_prompt']) ?></p><?php endif; ?>
        <div class="actions">
          <form method="post" action="<?= $v->url('/prompts/' . $prompt['id'] . '/restore/' . $version['id']) ?>" data-confirm="Obnovit tuto verzi?" class="inline">
            <?= $v->csrfField() ?><button type="submit" class="btn btn-sm">Obnovit tuto verzi</button>
          </form>
          <form method="post" action="<?= $v->url('/prompts/' . $prompt['id'] . '/versions/' . $version['id'] . '/delete') ?>" data-confirm="Smazat tuto verzi z historie?" class="inline">
            <?= $v->csrfField() ?><button type="submit" class="link-btn danger small">smazat verzi</button>
          </form>
        </div>
      </details>
    <?php endforeach; ?>
  <?php else: ?>
    <h2>Tipy pro konzistentní postavu</h2>
    <ul class="small">
      <li>Popis postavy drž <strong>doslova stejný</strong> — kopíruj ho z master promptu, měň jen scénu, outfit a světlo.</li>
      <li>S LoRA vždy začínej trigger slovem a drž sílu 0.6–0.9.</li>
      <li>Realismus: „natural skin texture, visible pores, subtle imperfections, shot on iPhone, candid“; do negativu „plastic skin, airbrushed, waxy“.</li>
      <li>Ukládej seed a nastavení u každého povedeného výsledku a dávej hodnocení — do character bible jdou jen 4★+.</li>
      <li>Postava musí vždy vypadat jednoznačně dospěle a nesmí se podobat žádné reálné osobě.</li>
    </ul>
  <?php endif; ?>
</section>
</div>
