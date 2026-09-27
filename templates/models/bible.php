<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var array<string, mixed> $model */
$look = [
    'Obličej' => $model['look_face'],
    'Vlasy' => $model['look_hair'],
    'Oči' => $model['look_eyes'],
    'Pleť' => $model['look_skin'],
    'Postava' => $model['look_body'],
    'Poznávací znaky' => $model['look_marks'],
    'Styl' => $model['look_style'],
];
?>
<div class="page-head">
  <h1>Character bible — <?= $v->e($model['name']) ?></h1>
  <div class="actions no-print">
    <button type="button" class="btn" data-print>Tisk / PDF</button>
    <a class="btn" href="<?= $v->url('/models/' . $model['id']) ?>">Zpět</a>
  </div>
</div>

<section class="card">
  <p class="muted">Jediný zdroj pravdy o vzhledu a povaze postavy. Při každém generování drž tyto popisy doslova — mění se jen scéna, oblečení a světlo.</p>
  <dl class="kv">
    <dt>Jméno</dt><dd><?= $v->e($model['name']) ?> (<?= (int) $model['persona_age'] ?> let)</dd>
    <dt>Nika</dt><dd><?= $v->e($model['niche'] ?? '—') ?></dd>
    <?php foreach ($look as $label => $value): ?>
      <dt><?= $v->e($label) ?></dt><dd><?= $v->nl2br($value ?? '—') ?></dd>
    <?php endforeach; ?>
    <dt>Povaha</dt><dd><?= $v->nl2br($model['personality'] ?? '—') ?></dd>
    <dt>Příběh</dt><dd><?= $v->nl2br($model['backstory'] ?? '—') ?></dd>
  </dl>
</section>

<section class="card">
  <h2>Technika</h2>
  <dl class="kv">
    <dt>Základní model</dt><dd><?= $v->e($model['base_model'] ?? '—') ?></dd>
    <dt>LoRA</dt><dd><?= $v->e($model['lora_name'] ?? '—') ?> · trigger <code><?= $v->e($model['lora_trigger'] ?? '—') ?></code> · síla <?= $v->e($model['lora_weight'] ?? '—') ?></dd>
    <dt>Uložení LoRA</dt><dd><?= $v->e($model['lora_location'] ?? '—') ?></dd>
    <dt>Seed</dt><dd><code><?= $v->e($model['default_seed'] ?? '—') ?></code></dd>
    <dt>Negativní prompt</dt><dd><pre><?= $v->e($model['default_negative'] ?? '—') ?></pre></dd>
    <dt>Nástroje</dt><dd><?php foreach ($tools as $tool): ?><?= $v->e($tool['name']) ?><?= $tool['purpose'] ? ' (' . $v->e($tool['purpose']) . ')' : '' ?>; <?php endforeach; ?><?= $tools === [] ? '—' : '' ?></dd>
  </dl>
</section>

<section class="card">
  <h2>Klíčové prompty</h2>
  <?php if ($prompts === []): ?><p class="empty">Žádné master / nejlépe hodnocené prompty.</p><?php endif; ?>
  <?php foreach ($prompts as $prompt): ?>
    <h3><?= $v->e($prompt['title']) ?> <span class="badge"><?= $v->label('prompt_kind', $prompt['kind']) ?></span><?= (int) $prompt['is_master'] === 1 ? ' <span class="badge badge-accent">master</span>' : '' ?></h3>
    <div class="prompt-box">
      <pre id="bible-prompt-<?= (int) $prompt['id'] ?>"><?= $v->e($prompt['prompt']) ?></pre>
      <button type="button" class="btn btn-sm copy-btn no-print" data-copy="#bible-prompt-<?= (int) $prompt['id'] ?>">Kopírovat</button>
    </div>
    <?php if ($prompt['negative_prompt']): ?><p class="small"><strong>Negativní:</strong> <?= $v->e($prompt['negative_prompt']) ?></p><?php endif; ?>
    <p class="small muted"><?= $v->e($prompt['tool_name'] ?? '') ?><?= $prompt['seed'] ? ' · seed ' . $v->e($prompt['seed']) : '' ?><?= $prompt['settings'] ? ' · ' . $v->e($prompt['settings']) : '' ?></p>
  <?php endforeach; ?>
</section>

<?php if ($references !== []): ?>
<section class="card">
  <h2>Referenční obrázky</h2>
  <div class="gallery">
    <?php foreach ($references as $image): ?>
      <div class="tile"><img src="<?= $v->url('/images/' . $image['id']) ?>" alt="<?= $v->e($image['alt_text'] ?? '') ?>" loading="lazy" width="<?= (int) $image['width'] ?>" height="<?= (int) $image['height'] ?>"></div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
