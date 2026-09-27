<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Support\Clock;
use App\Support\Labels;
use App\Support\Validator;

final class ToolController extends Controller
{
    public function index(Request $request): Response
    {
        $tools = $this->app->db->all(
            'SELECT t.*,
                (SELECT COALESCE(SUM(c.amount_czk_minor), 0) FROM costs c WHERE c.tool_id = t.id) AS spent,
                (SELECT COALESCE(SUM(c.quantity), 0) FROM costs c WHERE c.tool_id = t.id AND c.quantity > 0) AS quantity,
                (SELECT COALESCE(SUM(c.amount_czk_minor), 0) FROM costs c WHERE c.tool_id = t.id AND c.quantity > 0) AS spent_with_quantity,
                (SELECT GROUP_CONCAT(m.name, \', \') FROM model_tools mt JOIN models m ON m.id = mt.model_id WHERE mt.tool_id = t.id) AS models
             FROM ai_tools t ORDER BY t.category, t.name'
        );

        return $this->render('tools/index', ['title' => 'AI nástroje', 'tools' => $tools]);
    }

    public function create(Request $request): Response
    {
        return $this->render('tools/form', ['title' => 'Nový AI nástroj', 'tool' => null]);
    }

    public function store(Request $request): Response
    {
        [$data, $v] = $this->validate($request, null);
        if ($v->fails()) {
            return $this->backWithErrors($request, $v, '/tools/new');
        }
        $this->app->db->insert('ai_tools', $data + ['created_at' => Clock::nowUtc()]);
        $this->flash('success', 'Nástroj přidán.');

        return $this->redirect('/tools');
    }

    public function edit(Request $request): Response
    {
        $tool = $this->findOrFail('ai_tools', $request->intParam('id'));

        return $this->render('tools/form', ['title' => 'Upravit nástroj', 'tool' => $tool]);
    }

    public function update(Request $request): Response
    {
        $tool = $this->findOrFail('ai_tools', $request->intParam('id'));
        [$data, $v] = $this->validate($request, (int) $tool['id']);
        if ($v->fails()) {
            return $this->backWithErrors($request, $v, '/tools/' . $tool['id'] . '/edit');
        }
        $this->app->db->update('ai_tools', $data, ['id' => $tool['id']]);
        $this->flash('success', 'Uloženo.');

        return $this->redirect('/tools');
    }

    public function delete(Request $request): Response
    {
        $tool = $this->findOrFail('ai_tools', $request->intParam('id'));
        $this->app->db->delete('ai_tools', ['id' => $tool['id']]);
        $this->flash('success', 'Nástroj smazán (náklady zůstaly, jen bez vazby na nástroj).');

        return $this->redirect('/tools');
    }

    /** @return array{0: array<string, mixed>, 1: Validator} */
    private function validate(Request $request, ?int $id): array
    {
        $v = new Validator();
        $price = $request->input('monthly_price');
        $data = [
            'name' => $v->required('name', $request->input('name'), 'Název', 100),
            'category' => $v->oneOf('category', $request->input('category'), Labels::group('tool_category'), 'Kategorie'),
            'pricing_model' => $v->oneOf('pricing_model', $request->input('pricing_model'), Labels::group('pricing_model'), 'Cenový model'),
            'url' => $v->url('url', $request->input('url'), 'Web'),
            'monthly_price_minor' => $price === '' ? null : $v->money('monthly_price', $price, 'Měsíční cena'),
            'currency' => $v->currency('currency', $request->input('currency', 'USD'), 'Měna'),
            'notes' => $v->optional('notes', trim($request->rawInput('notes')), 'Poznámky', 3000),
        ];
        if ($this->app->db->scalar('SELECT 1 FROM ai_tools WHERE name = :n AND id != :id', ['n' => $data['name'], 'id' => $id ?? 0]) !== null) {
            $v->addError('name', 'Nástroj s tímto názvem už existuje.');
        }

        return [$data, $v];
    }
}
