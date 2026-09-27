<?php

declare(strict_types=1);

namespace App\Form;

use App\Database\Database;
use App\Support\Labels;
use App\Support\Validator;

/**
 * Pravidla účtu modelky na platformě — sdílí je formulář v administraci i import ze souboru.
 */
final class AccountForm
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{0: array<string, mixed>, 1: Validator} */
    public function validate(FormInput $in): array
    {
        $v = new Validator();
        $platformId = $this->db->idIfExists('platforms', $in->input('platform_id'));
        if ($platformId === null) {
            $v->addError('platform_id', 'Vyber platformu.');
        }
        $platform = $platformId !== null ? $this->db->one('SELECT * FROM platforms WHERE id = :id', ['id' => $platformId]) : null;
        $fee = $in->input('fee_percent');
        $currency = $in->input('currency');
        $syncSince = $in->input('sync_since');

        $data = [
            'platform_id' => $platformId,
            'handle' => ltrim($v->required('handle', $in->input('handle'), 'Uživatelské jméno', 100), '@'),
            'profile_url' => $v->url('profile_url', $in->input('profile_url'), 'Odkaz na profil'),
            'status' => $v->oneOf('status', $in->input('status'), Labels::group('account_status'), 'Stav'),
            'currency' => $currency === '' ? (string) ($platform['default_currency'] ?? 'USD') : $v->currency('currency', $currency, 'Měna'),
            'fee_percent' => $fee === '' ? (float) ($platform['default_fee_percent'] ?? 0) : $v->decimal('fee_percent', $fee, 'Poplatek %', 0, 100),
            'show_on_page' => $in->checkbox('show_on_page') ? 1 : 0,
            'sync_since' => $syncSince === '' ? null : $v->date('sync_since', $syncSince, 'Importovat od'),
            'notes' => $v->optional('notes', trim($in->rawInput('notes')), 'Poznámky', 3000),
        ];

        return [$data, $v];
    }
}
