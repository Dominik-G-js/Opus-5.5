<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Kernel\HttpException;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Service\Stats;
use App\Support\Str;

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
        $transactions = $this->app->db->all('SELECT * FROM transactions WHERE fan_id = :f ORDER BY occurred_at DESC LIMIT 200', ['f' => $fan['id']]);
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
        $this->app->db->update('fans', ['notes' => Str::nullIfEmpty(mb_substr(trim($request->rawInput('notes')), 0, 3000))], ['id' => $fan['id']]);
        $this->flash('success', 'Poznámka uložena.');

        return $this->redirect('/fans/' . $fan['id']);
    }
}
