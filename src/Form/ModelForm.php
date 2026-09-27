<?php

declare(strict_types=1);

namespace App\Form;

use App\Database\Database;
use App\Support\Labels;
use App\Support\Str;
use App\Support\Validator;

/**
 * Pravidla profilu modelky — sdílí je formulář v administraci i import ze souboru.
 */
final class ModelForm
{
    public const MIN_PERSONA_AGE = 21;

    /** Textová pole profilu: název sloupce => [popisek, max. délka]. */
    public const TEXT_FIELDS = [
        'niche' => ['Nika', 200],
        'tagline' => ['Slogan', 200],
        'public_bio' => ['Veřejné bio', 2000],
        'backstory' => ['Příběh postavy', 5000],
        'personality' => ['Povaha a styl komunikace', 3000],
        'look_face' => ['Obličej', 1000],
        'look_hair' => ['Vlasy', 1000],
        'look_eyes' => ['Oči', 500],
        'look_body' => ['Postava', 1000],
        'look_skin' => ['Pleť', 500],
        'look_marks' => ['Poznávací znaky', 1000],
        'look_style' => ['Styl a oblečení', 2000],
        'base_model' => ['Základní model', 255],
        'lora_name' => ['Název LoRA', 255],
        'lora_trigger' => ['Trigger slovo', 100],
        'lora_weight' => ['Síla LoRA', 50],
        'lora_location' => ['Umístění LoRA souboru', 500],
        'default_seed' => ['Výchozí seed', 50],
        'default_negative' => ['Výchozí negativní prompt', 3000],
        'seo_title' => ['SEO titulek', 70],
        'seo_description' => ['SEO popis', 170],
        'notes' => ['Poznámky', 5000],
    ];

    /** @param string $adminHost doména administrace — vlastní doména modelky s ní nesmí kolidovat */
    public function __construct(
        private readonly Database $db,
        private readonly string $adminHost,
    ) {
    }

    /** @return array{0: array<string, mixed>, 1: Validator} */
    public function validate(FormInput $in, ?int $modelId): array
    {
        $v = new Validator();
        $data = [
            'name' => $v->required('name', $in->input('name'), 'Jméno', 100),
            'status' => $v->oneOf('status', $in->input('status'), Labels::group('model_status'), 'Stav'),
            'persona_age' => $v->int('persona_age', $in->input('persona_age'), 'Věk postavy', self::MIN_PERSONA_AGE, 99),
            'page_lang' => $v->oneOf('page_lang', $in->input('page_lang', 'en'), Labels::group('page_lang'), 'Jazyk stránky'),
            'page_published' => $in->checkbox('page_published') ? 1 : 0,
            'page_domain' => $v->domain('page_domain', $in->input('page_domain'), 'Vlastní doména'),
        ];
        foreach (self::TEXT_FIELDS as $field => [$label, $max]) {
            $value = in_array($field, ['default_negative', 'public_bio', 'backstory', 'personality'], true)
                ? trim($in->rawInput($field))
                : $in->input($field);
            $data[$field] = $v->optional($field, $value, $label, $max);
        }

        $slug = $in->input('slug');
        $slug = $slug === '' ? Str::slug($data['name']) : $slug;
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,78}[a-z0-9])?$/', $slug) !== 1) {
            $v->addError('slug', 'URL slug: jen malá písmena bez diakritiky, číslice a pomlčky.');
        } elseif ($this->db->scalar('SELECT 1 FROM models WHERE slug = :s AND id != :id', ['s' => $slug, 'id' => $modelId ?? 0]) !== null) {
            $v->addError('slug', 'URL slug už používá jiná modelka.');
        }
        $data['slug'] = $slug;

        if ($data['page_domain'] !== null) {
            if ($data['page_domain'] === $this->adminHost) {
                $v->addError('page_domain', 'Doména nesmí být stejná jako doména administrace.');
            } elseif ($this->db->scalar('SELECT 1 FROM models WHERE page_domain = :d AND id != :id', ['d' => $data['page_domain'], 'id' => $modelId ?? 0]) !== null) {
                $v->addError('page_domain', 'Doménu už používá jiná modelka.');
            }
        }

        if ($modelId !== null) {
            $avatar = $in->input('avatar_image_id');
            $data['avatar_image_id'] = $avatar === '' ? null : (
                $this->db->scalar('SELECT id FROM images WHERE id = :i AND model_id = :m', ['i' => (int) $avatar, 'm' => $modelId]) !== null
                    ? (int) $avatar
                    : null
            );
        }

        return [$data, $v];
    }
}
