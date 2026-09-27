<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Service\ExchangeRateUnavailable;
use App\Service\Stats;
use App\Support\Clock;
use App\Support\CsvExport;
use App\Support\Labels;
use App\Support\Validator;

final class CostController extends Controller
{
    public function index(Request $request): Response
    {
        $month = $request->query('month');
        $modelId = $this->optionalId($request->query('model'), 'models');
        $where = [];
        $params = [];
        if (Stats::isValidMonth($month)) {
            [$params['s'], $params['e']] = Stats::monthRange($month);
            $where[] = 'c.incurred_on >= :s AND c.incurred_on < :e';
        } else {
            $month = '';
        }
        if ($modelId !== null) {
            $where[] = 'c.model_id = :m';
            $params['m'] = $modelId;
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $costs = $this->app->db->all(
            "SELECT c.*, m.name AS model_name, t.name AS tool_name FROM costs c
             LEFT JOIN models m ON m.id = c.model_id LEFT JOIN ai_tools t ON t.id = c.tool_id
             {$whereSql} ORDER BY c.incurred_on DESC, c.id DESC LIMIT 500",
            $params
        );
        $byCategory = $this->app->db->all(
            "SELECT c.category, SUM(c.amount_czk_minor) AS total FROM costs c {$whereSql} GROUP BY c.category ORDER BY total DESC",
            $params
        );

        return $this->render('costs/index', [
            'title' => 'Náklady',
            'costs' => $costs,
            'byCategory' => $byCategory,
            'total' => array_sum(array_map('intval', array_column($byCategory, 'total'))),
            'month' => $month,
            'modelId' => $modelId,
            'modelOptions' => $this->modelOptions(),
        ]);
    }

    /** Export nákladů za měsíc pro účetní. */
    public function export(Request $request): Response
    {
        $month = $request->query('month');
        $month = Stats::isValidMonth($month) ? $month : substr(Clock::todayLocal(), 0, 7);
        [$from, $to] = Stats::monthRange($month);
        $rows = $this->app->db->all(
            'SELECT c.*, m.name AS model_name, t.name AS tool_name FROM costs c
             LEFT JOIN models m ON m.id = c.model_id LEFT JOIN ai_tools t ON t.id = c.tool_id
             WHERE c.incurred_on >= :s AND c.incurred_on < :e ORDER BY c.incurred_on, c.id',
            ['s' => $from, 'e' => $to]
        );

        return CsvExport::response("naklady-{$month}.csv", [
            'Datum', 'Kategorie', 'Modelka', 'Nástroj', 'Kusů', 'Měna', 'Částka', 'Kurz ČNB', 'Částka CZK', 'Poznámka',
        ], array_map(static fn (array $r): array => [
            $r['incurred_on'],
            Labels::get('cost_category', $r['category']),
            $r['model_name'] ?? 'společné',
            $r['tool_name'],
            $r['quantity'] !== null ? (int) $r['quantity'] : null,
            $r['currency'],
            CsvExport::amount((int) $r['amount_minor']),
            (float) $r['fx_rate'],
            CsvExport::amount((int) $r['amount_czk_minor']),
            $r['note'],
        ], $rows));
    }

    public function create(Request $request): Response
    {
        return $this->render('costs/form', [
            'title' => 'Nový náklad',
            'cost' => null,
            'presetModel' => $this->optionalId($request->query('model'), 'models'),
            'modelOptions' => $this->modelOptions(),
            'toolOptions' => $this->toolOptions(),
        ]);
    }

    public function store(Request $request): Response
    {
        [$input, $v] = $this->validate($request);
        if ($v->fails()) {
            return $this->backWithErrors($request, $v, '/costs/new');
        }
        try {
            $row = $this->ledger()->prepareCost($input);
        } catch (ExchangeRateUnavailable $e) {
            $v->addError('fx_rate', $e->getMessage());

            return $this->backWithErrors($request, $v, '/costs/new');
        }
        $this->app->db->insert('costs', $row + ['created_at' => Clock::nowUtc()]);
        $this->flash('success', 'Náklad uložen.');

        return $input['model_id'] !== null ? $this->redirect('/models/' . $input['model_id']) : $this->redirect('/costs');
    }

    public function edit(Request $request): Response
    {
        $cost = $this->findOrFail('costs', $request->intParam('id'));

        return $this->render('costs/form', [
            'title' => 'Upravit náklad',
            'cost' => $cost,
            'presetModel' => null,
            'modelOptions' => $this->modelOptions(),
            'toolOptions' => $this->toolOptions(),
        ]);
    }

    public function update(Request $request): Response
    {
        $cost = $this->findOrFail('costs', $request->intParam('id'));
        [$input, $v] = $this->validate($request);
        if ($v->fails()) {
            return $this->backWithErrors($request, $v, '/costs/' . $cost['id'] . '/edit');
        }
        try {
            $row = $this->ledger()->prepareCost($input);
        } catch (ExchangeRateUnavailable $e) {
            $v->addError('fx_rate', $e->getMessage());

            return $this->backWithErrors($request, $v, '/costs/' . $cost['id'] . '/edit');
        }
        $this->app->db->update('costs', $row, ['id' => $cost['id']]);
        $this->flash('success', 'Uloženo.');

        return $this->redirect('/costs');
    }

    public function delete(Request $request): Response
    {
        $cost = $this->findOrFail('costs', $request->intParam('id'));
        $this->app->db->delete('costs', ['id' => $cost['id']]);
        $this->flash('success', 'Náklad smazán.');

        return $this->redirect('/costs');
    }

    /** @return array{0: array{model_id: int|null, tool_id: int|null, category: string, incurred_on: string, amount_minor: int, currency: string, quantity: int|null, note: string|null, fx_rate: float|null}, 1: Validator} */
    private function validate(Request $request): array
    {
        $v = new Validator();
        $fx = $request->input('fx_rate');
        $input = [
            'model_id' => $this->optionalId($request->input('model_id'), 'models'),
            'tool_id' => $this->optionalId($request->input('tool_id'), 'ai_tools'),
            'category' => $v->oneOf('category', $request->input('category'), Labels::group('cost_category'), 'Kategorie'),
            'incurred_on' => $v->date('incurred_on', $request->input('incurred_on'), 'Datum'),
            'amount_minor' => $v->money('amount', $request->input('amount'), 'Částka'),
            'currency' => $v->currency('currency', $request->input('currency'), 'Měna'),
            'quantity' => $v->int('quantity', $request->input('quantity'), 'Počet kusů', 1, 10_000_000, false),
            'note' => $v->optional('note', $request->input('note'), 'Poznámka', 500),
            'fx_rate' => $fx === '' ? null : $v->decimal('fx_rate', $fx, 'Kurz', 0.0001, 10000),
        ];

        return [$input, $v];
    }
}
