<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Kernel\HttpException;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Service\Ledger;
use App\Service\Stats;
use App\Support\Validator;

/**
 * „Kdo mi kolik vydělal“ — fanoušci seřazení podle útraty.
 */
final class FanController extends Controller
{
    public function index(Request $request): Response
    {
        $month = $request->query('month');
        $modelId = $this->optionalId($request->query('model'), 'models');

        return $this->render('fans/index', [
            'title' => 'Fanoušci',
            'fans' => (new Stats($this->app->db))->topFans(Stats::isValidMonth($month) ? $month : null, 200, $modelId),
            'month' => Stats::isValidMonth($month) ? $month : '',
            'modelId' => $modelId,
            'modelOptions' => $this->modelOptions(),
        ]);
    }

    public function show(Request $request): Response
    {
        $fan = $this->app->db->one(
            'SELECT f.*, a.handle AS account, a.id AS account_id, p.name AS platform, m.name AS model, m.id AS model_id
             FROM fans f JOIN accounts a ON a.id = f.account_id JOIN platforms p ON p.id = a.platform_id JOIN models m ON m.id = a.model_id
             WHERE f.id = :id',
            ['id' => $request->intParam('id')]
        );
        if ($fan === null) {
            throw HttpException::notFound();
        }
        $transactions = $this->app->db->all(
            "SELECT t.*, " . Ledger::SYNC_LOCKED_SQL . " AS locked
             FROM transactions t JOIN accounts a ON a.id = t.account_id WHERE t.fan_id = :f ORDER BY t.occurred_at DESC LIMIT 200",
            ['f' => $fan['id']]
        );
        $byType = $this->app->db->all(
            'SELECT type, SUM(net_czk_minor) AS net, COUNT(*) AS count FROM transactions WHERE fan_id = :f GROUP BY type ORDER BY net DESC',
            ['f' => $fan['id']]
        );

        return $this->render('fans/show', [
            'title' => 'Fanoušek ' . ($fan['display_name'] ?? $fan['handle'] ?? $fan['external_id']),
            'fan' => $fan,
            'transactions' => $transactions,
            'byType' => $byType,
            'total' => array_sum(array_map('intval', array_column($byType, 'net'))),
        ]);
    }

    public function update(Request $request): Response
    {
        $fan = $this->findOrFail('fans', $request->intParam('id'));
        $v = new Validator();
        $displayName = $v->optional('display_name', $request->input('display_name'), 'Jméno', 150);
        $handle = $v->optional('handle', ltrim($request->input('handle'), '@'), 'Uživatelské jméno', 150);
        $notes = $v->optional('notes', trim($request->rawInput('notes')), 'Poznámky', 3000);
        if ($displayName === null && $handle === null) {
            $v->addError('display_name', 'Vyplň jméno nebo uživatelské jméno.');
        }

        // Ručně zadaní a importovaní fanoušci se párují podle jména — po přejmenování se musí změnit i klíč,
        // jinak by další platba se starým/novým jménem založila duplicitního fanouška.
        $externalId = (string) $fan['external_id'];
        $prefix = strstr($externalId, ':', true);
        if (in_array($prefix, ['manual', 'csv'], true) && $displayName !== null) {
            $externalId = mb_substr($prefix . ':' . mb_strtolower($displayName), 0, 190);
            $taken = $this->app->db->scalar(
                'SELECT 1 FROM fans WHERE account_id = :a AND external_id = :e AND id != :id',
                ['a' => $fan['account_id'], 'e' => $externalId, 'id' => $fan['id']]
            );
            if ($taken !== null) {
                $v->addError('display_name', 'Fanoušek s tímto jménem už u účtu existuje.');
            }
        }
        if ($v->fails()) {
            return $this->backWithErrors($request, $v, '/fans/' . $fan['id']);
        }

        $this->app->db->update('fans', [
            'display_name' => $displayName,
            'handle' => $handle,
            'external_id' => $externalId,
            'is_top_spender' => $request->checkbox('is_top_spender') ? 1 : 0,
            'notes' => $notes,
        ], ['id' => $fan['id']]);
        $this->flash('success', 'Fanoušek uložen.');

        return $this->redirect('/fans/' . $fan['id']);
    }

    public function delete(Request $request): Response
    {
        $fan = $this->findOrFail('fans', $request->intParam('id'));
        // Platby zůstanou (fan_id → NULL díky ON DELETE SET NULL), jen bez přiřazeného fanouška.
        $this->app->db->delete('fans', ['id' => $fan['id']]);
        $this->flash('success', 'Fanoušek smazán. Jeho platby zůstaly v příjmech, jen bez jména.');

        return $this->redirect('/fans');
    }
}
