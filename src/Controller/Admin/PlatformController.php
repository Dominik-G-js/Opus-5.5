<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Support\Labels;
use App\Support\Validator;

final class PlatformController extends Controller
{
    public function index(Request $request): Response
    {
        $platforms = $this->app->db->all(
            'SELECT p.*, (SELECT COUNT(*) FROM accounts a WHERE a.platform_id = p.id) AS account_count
             FROM platforms p ORDER BY p.role, p.name'
        );

        return $this->render('platforms/index', ['title' => 'Platformy', 'platforms' => $platforms]);
    }

    public function create(Request $request): Response
    {
        return $this->render('platforms/form', ['title' => 'Nová platforma', 'platform' => null]);
    }

    public function store(Request $request): Response
    {
        [$data, $v] = $this->validate($request, null);
        if ($v->fails()) {
            return $this->backWithErrors($request, $v, '/platforms/new');
        }
        $this->app->db->insert('platforms', $data);
        $this->flash('success', 'Platforma přidána.');

        return $this->redirect('/platforms');
    }

    public function edit(Request $request): Response
    {
        $platform = $this->findOrFail('platforms', $request->intParam('id'));

        return $this->render('platforms/form', [
            'title' => 'Upravit platformu',
            'platform' => $platform,
            'accountCount' => (int) $this->app->db->scalar('SELECT COUNT(*) FROM accounts WHERE platform_id = :p', ['p' => $platform['id']]),
        ]);
    }

    public function update(Request $request): Response
    {
        $platform = $this->findOrFail('platforms', $request->intParam('id'));
        [$data, $v] = $this->validate($request, (int) $platform['id']);
        if ($v->fails()) {
            return $this->backWithErrors($request, $v, '/platforms/' . $platform['id'] . '/edit');
        }
        $this->app->db->update('platforms', $data, ['id' => $platform['id']]);
        $this->flash('success', 'Uloženo.');

        return $this->redirect('/platforms');
    }

    public function delete(Request $request): Response
    {
        $platform = $this->findOrFail('platforms', $request->intParam('id'));
        $accounts = (int) $this->app->db->scalar('SELECT COUNT(*) FROM accounts WHERE platform_id = :p', ['p' => $platform['id']]);
        if ($accounts > 0) {
            $this->flash('error', "Platformu používá {$accounts} účtů. Nejdřív je smaž nebo přesuň na jinou platformu (Upravit účet).");

            return $this->redirect('/platforms/' . $platform['id'] . '/edit');
        }
        $this->app->db->delete('platforms', ['id' => $platform['id']]);
        $this->flash('success', 'Platforma smazána.');

        return $this->redirect('/platforms');
    }

    /** @return array{0: array<string, mixed>, 1: Validator} */
    private function validate(Request $request, ?int $id): array
    {
        $v = new Validator();
        $data = [
            'name' => $v->required('name', $request->input('name'), 'Název', 80),
            'role' => $v->oneOf('role', $request->input('role'), Labels::group('platform_role'), 'Role'),
            'ai_policy' => $v->oneOf('ai_policy', $request->input('ai_policy'), Labels::group('ai_policy'), 'AI pravidla'),
            'default_fee_percent' => $v->decimal('default_fee_percent', $request->input('default_fee_percent', '0'), 'Poplatek %', 0, 100),
            'default_currency' => $v->currency('default_currency', $request->input('default_currency', 'USD'), 'Měna'),
            'url' => $v->url('url', $request->input('url'), 'Web'),
            'notes' => $v->optional('notes', trim($request->rawInput('notes')), 'Poznámky', 3000),
        ];
        if ($this->app->db->scalar('SELECT 1 FROM platforms WHERE name = :n AND id != :id', ['n' => $data['name'], 'id' => $id ?? 0]) !== null) {
            $v->addError('name', 'Platforma s tímto názvem už existuje.');
        }

        return [$data, $v];
    }
}
