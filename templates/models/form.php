<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var array<string, mixed>|null $model */
$m = $model ?? [];
$val = static fn (string $key, mixed $default = '') => $v->old($key, $m[$key] ?? $default);
$action = $model === null ? $v->url('/models') : $v->url('/models/' . $model['id']);
$textarea = static function (string $key, string $label, string $hint = '', int $rows = 3) use ($v, $val): string {
    return '<div class="field full"><label for="' . $key . '">' . $v->e($label) . '</label>'
        . '<textarea id="' . $key . '" name="' . $key . '" rows="' . $rows . '">' . $val($key) . '</textarea>'
        . ($hint !== '' ? '<p class="hint">' . $v->e($hint) . '</p>' : '') . $v->error($key) . '</div>';
};
$input = static function (string $key, string $label, string $hint = '') use ($v, $val): string {
    return '<div class="field"><label for="' . $key . '">' . $v->e($label) . '</label>'
        . '<input type="text" id="' . $key . '" name="' . $key . '" value="' . $val($key) . '">'
        . ($hint !== '' ? '<p class="hint">' . $v->e($hint) . '</p>' : '') . $v->error($key) . '</div>';
};
?>
<div class="page-head">
  <h1><?= $v->e($title) ?></h1>
  <?php if ($model !== null): ?><a class="btn" href="<?= $v->url('/models/' . $model['id']) ?>">Zpět na profil</a><?php endif; ?>
</div>

<form method="post" action="<?= $action ?>">
  <?= $v->csrfField() ?>

  <fieldset>
    <legend>Identita</legend>
    <div class="form-grid">
      <div class="field">
        <label for="name">Jméno modelky *</label>
        <input type="text" id="name" name="name" required maxlength="100" value="<?= $val('name') ?>">
        <p class="hint">Unikátní a snadno vyhledatelné (ověř, že jméno nepoužívá reálná osoba ani jiná AI modelka).</p>
        <?= $v->error('name') ?>
      </div>
      <div class="field">
        <label for="slug">URL slug</label>
        <input type="text" id="slug" name="slug" maxlength="80" pattern="[a-z0-9-]+" value="<?= $val('slug') ?>">
        <p class="hint">Prázdné = vytvoří se ze jména. Stejné jako handle na sítích.</p>
        <?= $v->error('slug') ?>
      </div>
      <div class="field">
        <label for="status">Stav</label>
        <select id="status" name="status"><?= $v->options(App\Support\Labels::group('model_status'), $v->oldRaw('status', $m['status'] ?? 'concept')) ?></select>
      </div>
      <div class="field">
        <label for="persona_age">Věk postavy *</label>
        <input type="number" id="persona_age" name="persona_age" min="<?= App\Form\ModelForm::MIN_PERSONA_AGE ?>" max="99" required value="<?= $val('persona_age', 24) ?>">
        <p class="hint">Minimálně <?= App\Form\ModelForm::MIN_PERSONA_AGE ?> let a postava musí jednoznačně vypadat dospěle.</p>
        <?= $v->error('persona_age') ?>
      </div>
      <?= $input('niche', 'Nika', 'Např. fitness girl next door, cosplay gamerka, alt/goth…') ?>
      <?= $input('tagline', 'Slogan', 'Jedna věta do bia.') ?>
      <?= $textarea('public_bio', 'Veřejné bio', 'Zobrazí se na landing page. Uveď, že jde o AI postavu.', 4) ?>
      <?= $textarea('backstory', 'Příběh postavy (interní)', 'Odkud je, co dělá, co má ráda — zdroj pro captiony a chat.', 5) ?>
      <?= $textarea('personality', 'Povaha a styl komunikace (interní)', 'Tón, oblíbené fráze, emoji, co nikdy neříká.', 4) ?>
    </div>
  </fieldset>

  <fieldset>
    <legend>Vzhled — character bible</legend>
    <p class="hint">Popis musí být vždy stejný. Z těchto polí skládej základní prompt, aby tvář a postava nikdy „neujely“.</p>
    <div class="form-grid">
      <?= $textarea('look_face', 'Obličej', 'Tvar obličeje, nos, rty, lícní kosti, výraz.') ?>
      <?= $textarea('look_hair', 'Vlasy', 'Barva, délka, střih, typický účes.') ?>
      <?= $textarea('look_eyes', 'Oči', '', 2) ?>
      <?= $textarea('look_skin', 'Pleť', 'Odstín, pihy, textura.', 2) ?>
      <?= $textarea('look_body', 'Postava', 'Výška, typ postavy — dospělé proporce.') ?>
      <?= $textarea('look_marks', 'Poznávací znaky', 'Znaménko, tetování, piercing — pomáhají konzistenci i rozpoznatelnosti.') ?>
      <?= $textarea('look_style', 'Styl a oblečení', 'Typické outfity, barvy, doplňky.') ?>
    </div>
  </fieldset>

  <fieldset>
    <legend>Technické nastavení generování</legend>
    <div class="form-grid">
      <?= $input('base_model', 'Základní model', 'Např. FLUX.1 dev, SDXL (Juggernaut), Higgsfield Soul…') ?>
      <?= $input('lora_name', 'Název LoRA', 'Soubor / verze, např. jmeno_v1.safetensors') ?>
      <?= $input('lora_trigger', 'Trigger slovo', 'Unikátní, např. ohwx_jmeno') ?>
      <?= $input('lora_weight', 'Síla LoRA', 'Typicky 0.6–0.9') ?>
      <?= $input('lora_location', 'Kde je LoRA uložená', 'Cesta / úložiště (záloha!)') ?>
      <?= $input('default_seed', 'Výchozí seed') ?>
      <?= $textarea('default_negative', 'Výchozí negativní prompt', '', 3) ?>
    </div>
  </fieldset>

  <fieldset>
    <legend>Veřejná landing page (SEO)</legend>
    <div class="form-grid">
      <div class="field full check">
        <input type="checkbox" id="page_published" name="page_published" value="1"<?= $v->checked((bool) $v->oldRaw('page_published', $m['page_published'] ?? 0)) ?>>
        <label for="page_published">Zveřejnit landing page</label>
      </div>
      <div class="field">
        <label for="page_lang">Jazyk stránky</label>
        <select id="page_lang" name="page_lang"><?= $v->options(App\Support\Labels::group('page_lang'), $v->oldRaw('page_lang', $m['page_lang'] ?? 'en')) ?></select>
      </div>
      <div class="field">
        <label for="page_domain">Vlastní doména</label>
        <input type="text" id="page_domain" name="page_domain" placeholder="jmeno-modelky.com" value="<?= $val('page_domain') ?>">
        <p class="hint">Volitelné. Doména musí mířit na tento server (složka public/). Jinak adresa /m/slug.</p>
        <?= $v->error('page_domain') ?>
      </div>
      <div class="field">
        <label for="seo_title">SEO titulek (max. 70)</label>
        <input type="text" id="seo_title" name="seo_title" maxlength="70" value="<?= $val('seo_title') ?>">
        <p class="hint">Např. „Jméno — AI creator | Fanvue, X &amp; TikTok“</p>
        <?= $v->error('seo_title') ?>
      </div>
      <div class="field">
        <label for="seo_description">SEO popis (max. 170)</label>
        <input type="text" id="seo_description" name="seo_description" maxlength="170" value="<?= $val('seo_description') ?>">
        <?= $v->error('seo_description') ?>
      </div>
      <?php if ($model !== null): ?>
      <div class="field">
        <label for="avatar_image_id">Profilový obrázek</label>
        <select id="avatar_image_id" name="avatar_image_id"><?= $v->options($imageOptions ?? [], $v->oldRaw('avatar_image_id', $m['avatar_image_id'] ?? ''), true, '— bez obrázku —') ?></select>
        <p class="hint">Na landing page se zobrazí jen zveřejněný obrázek (bez metadat).</p>
      </div>
      <?php endif; ?>
    </div>
  </fieldset>

  <fieldset>
    <legend>Poznámky</legend>
    <?= $textarea('notes', 'Interní poznámky', '', 4) ?>
  </fieldset>

  <button type="submit" class="btn btn-primary"><?= $model === null ? 'Přidat AI modelku' : 'Uložit' ?></button>
</form>

<?php if ($model !== null): ?>
<section class="card mt-2">
  <h2>Smazat modelku</h2>
  <p class="small muted">Nevratně smaže modelku včetně promptů, obrázků, účtů, fanoušků a příjmů. Náklady zůstanou jako společné.</p>
  <form method="post" action="<?= $v->url('/models/' . $model['id'] . '/delete') ?>" data-confirm="Opravdu nevratně smazat modelku?">
    <?= $v->csrfField() ?>
    <div class="field"><label for="confirm_name">Pro potvrzení napiš jméno „<?= $v->e($model['name']) ?>“</label><input type="text" id="confirm_name" name="confirm_name"></div>
    <p><button type="submit" class="btn btn-danger">Smazat</button></p>
  </form>
</section>
<?php endif; ?>
