<?php

declare(strict_types=1);

namespace App\Controller;

use App\Integration\Fanvue\FanvueClient;
use App\Integration\Fanvue\FanvueSync;
use App\Kernel\App;
use App\Kernel\HttpException;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Kernel\Services;
use App\Service\ExchangeRates;
use App\Service\ImageStore;
use App\Service\Ledger;
use App\Service\Settings;
use App\Support\Validator;

abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    /** @param array<string, mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->app->view->render($template, $data), $status);
    }

    /** @param array<string, scalar> $query */
    protected function redirect(string $adminPath, array $query = []): Response
    {
        return Response::redirect($this->app->urls->admin($adminPath, $query));
    }

    protected function flash(string $type, string $message): void
    {
        $this->app->session->flash($type, $message);
    }

    /** Vrátí uživatele na formulář s chybami a vyplněnými hodnotami (Post/Redirect/Get). */
    protected function backWithErrors(Request $request, Validator|array $errors, string $adminPath): Response
    {
        $messages = $errors instanceof Validator ? $errors->errors() : $errors;
        $this->app->session->set('_errors', $messages);
        $this->app->session->flashInput($request->post);
        
        return $this->redirect($adminPath);
    }

    /** @return array<string, mixed> */
    protected function findOrFail(string $table, int $id): array
    {
        // Název tabulky pochází vždy z kódu controlleru.
        $row = $this->app->db->one("SELECT * FROM {$table} WHERE id = :id", ['id' => $id]);
        if ($row === null) {
            throw HttpException::notFound();
        }

        return $row;
    }

    protected function currentUserId(): int
    {
        return (int) ($this->app->auth->user()['id'] ?? 0);
    }

    protected function settings(): Settings
    {
        return new Settings($this->app->db);
    }

    protected function exchangeRates(): ExchangeRates
    {
        return Services::exchangeRates($this->app);
    }

    protected function ledger(): Ledger
    {
        return Services::ledger($this->app);
    }

    protected function imageStore(): ImageStore
    {
        return Services::imageStore($this->app);
    }

    protected function fanvueClient(): FanvueClient
    {
        return Services::fanvueClient($this->app);
    }

    protected function fanvueSync(): FanvueSync
    {
        return Services::fanvueSync($this->app);
    }

    /** @return array<int, string> */
    protected function modelOptions(): array
    {
        $rows = $this->app->db->all('SELECT id, name FROM models ORDER BY name');

        return array_column($rows, 'name', 'id');
    }

    /** @return array<int, string> */
    protected function toolOptions(): array
    {
        $rows = $this->app->db->all('SELECT id, name FROM ai_tools ORDER BY name');

        return array_column($rows, 'name', 'id');
    }

    /** @return array<int, string> */
    protected function accountOptions(): array
    {
        $rows = $this->app->db->all(
            'SELECT a.id, m.name AS model, p.name AS platform, a.handle
             FROM accounts a JOIN models m ON m.id = a.model_id JOIN platforms p ON p.id = a.platform_id
             ORDER BY m.name, p.name'
        );
        $options = [];
        foreach ($rows as $row) {
            $options[(int) $row['id']] = $row['model'] . ' — ' . $row['platform'] . ' (@' . $row['handle'] . ')';
        }

        return $options;
    }

    protected function optionalId(string $value, string $table): ?int
    {
        if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }
        $exists = $this->app->db->scalar("SELECT 1 FROM {$table} WHERE id = :id", ['id' => (int) $value]);

        return $exists === null ? null : (int) $value;
    }
}
