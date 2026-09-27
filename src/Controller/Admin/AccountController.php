<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Form\AccountForm;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Service\Ledger;
use App\Service\Stats;
use App\Support\Clock;

final class AccountController extends Controller
{
    public function create(Request $request): Response
    {
        $model = $this->findOrFail('models', $request->intParam('id'));

        return $this->render('accounts/form', [
            'title' => 'Nový účet — ' . $model['name'],
            'model' => $model,
            'account' => null,
            'platforms' => $this->platforms(),
        ]);
    }

    public function store(Request $request): Response
    {
        $model = $this->findOrFail('models', $request->intParam('id'));
        [$data, $v] = $this->form()->validate($request->form());
        if ($v->fails()) {
            return $this->backWithErrors($request, $v, '/models/' . $model['id'] . '/accounts/new');
        }
        $id = $this->app->db->insert('accounts', $data + ['model_id' => $model['id'], 'created_at' => Clock::nowUtc()]);
        $platform = $this->findOrFail('platforms', (int) $data['platform_id']);
        if ($platform['ai_policy'] === 'banned') {
            $this->flash('error', "Pozor: {$platform['name']} podle svých pravidel nepovoluje čistě AI persony. Hrozí ban a ztráta výdělku.");
        }
        $this->flash('success', 'Účet přidán.');

        return $this->redirect('/accounts/' . $id);
    }

    public function show(Request $request): Response
    {
        $account = $this->loadAccount($request->intParam('id'));
        $id = (int) $account['id'];
        $month = substr(Clock::todayLocal(), 0, 7);
        [$from, $to] = Stats::monthRange($month);

        return $this->render('accounts/show', [
            'title' => $account['platform'] . ' — @' . $account['handle'],
            'account' => $account,
            'monthNet' => (int) $this->app->db->scalar(
                'SELECT COALESCE(SUM(net_czk_minor), 0) FROM transactions WHERE account_id = :a AND occurred_on >= :s AND occurred_on < :e',
                ['a' => $id, 's' => $from, 'e' => $to]
            ),
            'lifetimeNet' => (int) $this->app->db->scalar('SELECT COALESCE(SUM(net_czk_minor), 0) FROM transactions WHERE account_id = :a', ['a' => $id]),
            'transactions' => $this->app->db->all(
                'SELECT t.*, f.handle AS fan_handle, f.display_name AS fan_name, ' . Ledger::SYNC_LOCKED_SQL . ' AS locked
                 FROM transactions t JOIN accounts a ON a.id = t.account_id
                 LEFT JOIN fans f ON f.id = t.fan_id WHERE t.account_id = :a ORDER BY t.occurred_at DESC LIMIT 50',
                ['a' => $id]
            ),
            'fans' => $this->app->db->all(
                'SELECT f.*, COALESCE(SUM(t.net_czk_minor), 0) AS net, COUNT(t.id) AS payments
                 FROM fans f LEFT JOIN transactions t ON t.fan_id = f.id
                 WHERE f.account_id = :a GROUP BY f.id ORDER BY net DESC LIMIT 20',
                ['a' => $id]
            ),
            'syncRuns' => $this->app->db->all('SELECT * FROM sync_runs WHERE account_id = :a ORDER BY id DESC LIMIT 10', ['a' => $id]),
            'fanvueConfigured' => $this->fanvueClient()->isConfigured(),
            'redirectUri' => $this->app->urls->absoluteAdmin('/integrations/fanvue/callback'),
        ]);
    }

    public function edit(Request $request): Response
    {
        $account = $this->loadAccount($request->intParam('id'));
        $model = $this->findOrFail('models', (int) $account['model_id']);

        return $this->render('accounts/form', [
            'title' => 'Upravit účet',
            'model' => $model,
            'account' => $account,
            'platforms' => $this->platforms(),
        ]);
    }

    public function update(Request $request): Response
    {
        $account = $this->findOrFail('accounts', $request->intParam('id'));
        [$data, $v] = $this->form()->validate($request->form());
        if ($v->fails()) {
            return $this->backWithErrors($request, $v, '/accounts/' . $account['id'] . '/edit');
        }
        $this->app->db->update('accounts', $data, ['id' => $account['id']]);
        $this->flash('success', 'Uloženo.');

        return $this->redirect('/accounts/' . $account['id']);
    }

    public function delete(Request $request): Response
    {
        $account = $this->findOrFail('accounts', $request->intParam('id'));
        if (!$request->checkbox('confirm')) {
            $this->flash('error', 'Potvrď smazání zaškrtnutím — smažou se i všechny příjmy a fanoušci účtu.');

            return $this->redirect('/accounts/' . $account['id'] . '/edit');
        }
        $this->app->db->delete('accounts', ['id' => $account['id']]);
        $this->flash('success', 'Účet smazán.');

        return $this->redirect('/models/' . $account['model_id']);
    }

    /** @return array<string, mixed> */
    private function loadAccount(int $id): array
    {
        $account = $this->app->db->one(
            'SELECT a.*, p.name AS platform, p.role, p.ai_policy, p.notes AS platform_notes, m.name AS model_name
             FROM accounts a JOIN platforms p ON p.id = a.platform_id JOIN models m ON m.id = a.model_id WHERE a.id = :id',
            ['id' => $id]
        );
        if ($account === null) {
            throw \App\Kernel\HttpException::notFound();
        }

        return $account;
    }

    /** @return list<array<string, mixed>> */
    private function platforms(): array
    {
        return $this->app->db->all('SELECT * FROM platforms ORDER BY role, name');
    }

    private function form(): AccountForm
    {
        return new AccountForm($this->app->db);
    }
}
