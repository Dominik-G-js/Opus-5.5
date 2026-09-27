<?php

declare(strict_types=1);

namespace App\Form;

use App\Database\Database;
use App\Support\Labels;
use App\Support\Validator;

/**
 * Pravidla promptu — sdílí je formulář v administraci i import ze souboru.
 */
final class PromptForm
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{0: array<string, mixed>, 1: Validator} */
    public function validate(FormInput $in): array
    {
        $v = new Validator();
        $rating = $v->int('rating', $in->input('rating'), 'Hodnocení', 1, 5, false);
        $data = [
            'kind' => $v->oneOf('kind', $in->input('kind'), Labels::group('prompt_kind'), 'Typ'),
            'title' => $v->required('title', $in->input('title'), 'Název', 150),
            'prompt' => $v->required('prompt', trim($in->rawInput('prompt')), 'Prompt', 20000),
            'negative_prompt' => $v->optional('negative_prompt', trim($in->rawInput('negative_prompt')), 'Negativní prompt', 5000),
            'seed' => $v->optional('seed', $in->input('seed'), 'Seed', 50),
            'settings' => $v->optional('settings', trim($in->rawInput('settings')), 'Nastavení', 2000),
            'tool_id' => $this->db->idIfExists('ai_tools', $in->input('tool_id')),
            'is_master' => $in->checkbox('is_master') ? 1 : 0,
            'rating' => $rating,
            'notes' => $v->optional('notes', trim($in->rawInput('notes')), 'Poznámky', 5000),
        ];

        return [$data, $v];
    }
}
