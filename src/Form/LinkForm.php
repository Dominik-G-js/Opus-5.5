<?php

declare(strict_types=1);

namespace App\Form;

use App\Database\Database;
use App\Support\Labels;
use App\Support\Str;
use App\Support\Validator;

/**
 * Pravidla sledovacího odkazu — sdílí je formulář v administraci i import ze souboru.
 */
final class LinkForm
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{0: array<string, mixed>, 1: Validator} */
    public function validate(FormInput $in, ?int $id): array
    {
        $v = new Validator();
        $modelId = $this->db->idIfExists('models', $in->input('model_id'));
        if ($modelId === null) {
            $v->addError('model_id', 'Vyber modelku.');
        }
        $code = $in->input('code');
        if ($code === '') {
            $code = Str::randomCode(7);
        } elseif (preg_match('/^[A-Za-z0-9_-]{3,40}$/', $code) !== 1) {
            $v->addError('code', 'Kód: 3–40 znaků, písmena, číslice, - a _.');
        }
        if ($this->db->scalar('SELECT 1 FROM links WHERE code = :c AND id != :id', ['c' => $code, 'id' => $id ?? 0]) !== null) {
            $v->addError('code', 'Kód už používá jiný odkaz.');
        }
        $data = [
            'model_id' => $modelId,
            'code' => $code,
            'label' => $v->required('label', $in->input('label'), 'Popisek', 80),
            'source' => $v->oneOf('source', $in->input('source'), Labels::group('link_source'), 'Zdroj'),
            'target_url' => $v->url('target_url', $in->input('target_url'), 'Cílová adresa', true),
            'is_active' => $in->checkbox('is_active') ? 1 : 0,
            'is_premium' => $in->checkbox('is_premium') ? 1 : 0,
            'show_on_page' => $in->checkbox('show_on_page') ? 1 : 0,
            'sort_order' => $v->int('sort_order', $in->input('sort_order', '0'), 'Pořadí', 0, 999) ?? 0,
        ];

        return [$data, $v];
    }
}
