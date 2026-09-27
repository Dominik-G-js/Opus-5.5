<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Service\CsvImporter;
use App\Service\ExchangeRateUnavailable;
use App\Service\Stats;
use App\Support\Clock;
use App\Support\CsvExport;
use App\Support\Labels;
use App\Support\Money;
use App\Support\Validator;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class TransactionController extends Controller
{
    private const IMPORT_SESSION = 'csv_import';
    private const IMPORT_TTL = 3600;

    public function index(Request $request): Response
    {
        $month = $request->query('month');
        $month = Stats::isValidMonth($month) ? $month : substr(Clock::todayLocal(), 0, 7);
        [$from, $to] = Stats::monthRange($month);
        $params = ['s' => $from, 'e' => $to];
        $where = ['t.occurred_on >= :s AND t.occurred_on < :e'];
        $modelId = $this->optionalId($request->query('model'), 'models');
        if ($modelId !== null) {
            $where[] = 'a.model_id = :m';
            $params['m'] = $modelId;
        }
        $accountId = $this->optionalId($request->query('account'), 'accounts');
        if ($accountId !== null) {
            $where[] = 't.account_id = :a';
            $params['a'] = $accountId;
        }
        $type = $request->query('type');
        if (in_array($type, Labels::keys('tx_type'), true)) {
            $where[] = 't.type = :t';
            $params['t'] = $type;
        } else {
            $type = '';
        }
        $whereSql = implode(' AND ', $where);

        $rows = $this->app->db->all(
            "SELECT t.*, a.handle AS account, p.name AS platform, m.name AS model, f.handle AS fan_handle, f.display_name AS fan_name
             FROM transactions t JOIN accounts a ON a.id = t.account_id JOIN platforms p ON p.id = a.platform_id
             JOIN models m ON m.id = a.model_id LEFT JOIN fans f ON f.id = t.fan_id
             WHERE {$whereSql} ORDER BY t.occurred_at DESC LIMIT 1000",
            $params
        );
        $totals = $this->app->db->one(
            "SELECT COALESCE(SUM(t.gross_czk_minor), 0) AS gross, COALESCE(SUM(t.net_czk_minor), 0) AS net, COUNT(*) AS count
             FROM transactions t JOIN accounts a ON a.id = t.account_id WHERE {$whereSql}",
            $params
        );

        return $this->render('earnings/index', [
            'title' => 'Příjmy',
            'rows' => $rows,
            'totals' => $totals,
            'month' => $month,
            'modelId' => $modelId,
            'accountId' => $accountId,
            'type' => $type,
            'modelOptions' => $this->modelOptions(),
            'accountOptions' => $this->accountOptions(),
        ]);
    }

    /** Export příjmů za měsíc pro účetní (CSV, částky i v CZK dle kurzu ČNB ke dni platby). */
    public function export(Request $request): Response
    {
        $month = $request->query('month');
        $month = Stats::isValidMonth($month) ? $month : substr(Clock::todayLocal(), 0, 7);
        [$from, $to] = Stats::monthRange($month);
        $rows = $this->app->db->all(
            "SELECT t.*, a.handle AS account, p.name AS platform, m.name AS model, f.handle AS fan_handle, f.display_name AS fan_name
             FROM transactions t JOIN accounts a ON a.id = t.account_id JOIN platforms p ON p.id = a.platform_id
             JOIN models m ON m.id = a.model_id LEFT JOIN fans f ON f.id = t.fan_id
             WHERE t.occurred_on >= :s AND t.occurred_on < :e ORDER BY t.occurred_at",
            ['s' => $from, 'e' => $to]
        );

        return CsvExport::response("prijmy-{$month}.csv", [
            'Datum', 'Čas (UTC)', 'Modelka', 'Platforma', 'Účet', 'Typ', 'Fanoušek', 'Měna',
            'Hrubě', 'Čistě', 'Kurz ČNB', 'Hrubě CZK', 'Čistě CZK', 'Poplatek CZK', 'Zdroj', 'Poznámka',
        ], array_map(static fn (array $r): array => [
            $r['occurred_on'],
            $r['occurred_at'],
            $r['model'],
            $r['platform'],
            $r['account'],
            Labels::get('tx_type', $r['type']),
            $r['fan_name'] ?? $r['fan_handle'],
            $r['currency'],
            CsvExport::amount((int) $r['gross_minor']),
            CsvExport::amount((int) $r['net_minor']),
            (float) $r['fx_rate'],
            CsvExport::amount((int) $r['gross_czk_minor']),
            CsvExport::amount((int) $r['net_czk_minor']),
            CsvExport::amount((int) $r['gross_czk_minor'] - (int) $r['net_czk_minor']),
            Labels::get('tx_source', $r['source']),
            $r['note'],
        ], $rows));
    }

    public function create(Request $request): Response
    {
        return $this->render('earnings/form', [
            'title' => 'Přidat příjem',
            'accountOptions' => $this->accountOptions(),
            'presetAccount' => $this->optionalId($request->query('account'), 'accounts'),
        ]);
    }

    public function store(Request $request): Response
    {
        $v = new Validator();
        $accountId = $this->optionalId($request->input('account_id'), 'accounts');
        if ($accountId === null) {
            $v->addError('account_id', 'Vyber účet.');
        }
        $account = $accountId !== null ? $this->findOrFail('accounts', $accountId) : null;
        $date = $v->date('occurred_on', $request->input('occurred_on'), 'Datum');
        $time = $request->input('occurred_time', '12:00');
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            $v->addError('occurred_time', 'Čas ve formátu HH:MM.');
        }
        $type = $v->oneOf('type', $request->input('type'), Labels::group('tx_type'), 'Typ');
        $gross = $request->input('gross') === '' ? null : $v->money('gross', $request->input('gross'), 'Hrubá částka', true);
        $net = $request->input('net') === '' ? null : $v->money('net', $request->input('net'), 'Čistá částka', true);
        if ($gross === null && $net === null) {
            $v->addError('gross', 'Zadej hrubou nebo čistou částku.');
        }
        $currencyInput = $request->input('currency');
        $currency = $currencyInput === '' ? (string) ($account['currency'] ?? 'USD') : $v->currency('currency', $currencyInput, 'Měna');
        $fxInput = $request->input('fx_rate');
        $fx = $fxInput === '' ? null : $v->decimal('fx_rate', $fxInput, 'Kurz', 0.0001, 10000);
        $fanName = mb_substr($request->input('fan'), 0, 150);
        $note = $v->optional('note', $request->input('note'), 'Poznámka', 500);
        if ($v->fails() || $account === null) {
            return $this->backWithErrors($request, $v, '/earnings/new');
        }

        [$gross, $net] = Money::completeGrossNet($gross, $net, (float) $account['fee_percent']);
        try {
            $row = $this->ledger()->prepareTransaction((int) $account['id'], [
                'occurred_at' => new DateTimeImmutable($date . ' ' . $time . ':00', Clock::localZone()),
                'type' => $type,
                'gross_minor' => $gross,
                'net_minor' => $net,
                'currency' => $currency,
                'source' => 'manual',
                'note' => $note,
                'fx_rate' => $fx,
            ]);
        } catch (ExchangeRateUnavailable $e) {
            $v->addError('fx_rate', $e->getMessage());

            return $this->backWithErrors($request, $v, '/earnings/new');
        }
        $ledger = $this->ledger();
        $this->app->db->transaction(function () use ($ledger, $row, $fanName, $account): void {
            if ($fanName !== '') {
                $row['fan_id'] = $ledger->upsertFan((int) $account['id'], 'manual:' . mb_strtolower($fanName), $fanName, $fanName);
            }
            $ledger->insertTransaction($row);
        });
        $this->flash('success', 'Příjem uložen: ' . Money::format($row['net_czk_minor'], 'CZK') . ' po poplatku.');

        return $this->redirect('/earnings', ['month' => substr($date, 0, 7)]);
    }

    public function delete(Request $request): Response
    {
        $tx = $this->findOrFail('transactions', $request->intParam('id'));
        if ($tx['source'] === 'fanvue') {
            $this->flash('error', 'Transakce z Fanvue API se při další synchronizaci vrátí — smazání nemá smysl.');

            return $this->redirect('/earnings', ['month' => substr((string) $tx['occurred_on'], 0, 7)]);
        }
        $this->app->db->delete('transactions', ['id' => $tx['id']]);
        $this->flash('success', 'Transakce smazána.');

        return $this->redirect('/earnings', ['month' => substr((string) $tx['occurred_on'], 0, 7)]);
    }

    public function importForm(Request $request): Response
    {
        $token = $request->query('token');
        $pending = $token !== '' ? $this->pendingImport($token) : null;
        $preview = null;
        if ($pending !== null) {
            try {
                $preview = $this->importer()->read($pending['path'], 8);
            } catch (RuntimeException $e) {
                $this->flash('error', $e->getMessage());

                return $this->redirect('/earnings/import');
            }
        }

        return $this->render('earnings/import', [
            'title' => 'Import příjmů z CSV',
            'accountOptions' => $this->accountOptions(),
            'token' => $pending !== null ? $token : null,
            'pending' => $pending,
            'preview' => $preview,
            'fields' => CsvImporter::FIELDS,
        ]);
    }

    public function importUpload(Request $request): Response
    {
        $accountId = $this->optionalId($request->input('account_id'), 'accounts');
        $file = $request->file('csv');
        if ($accountId === null || $file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->flash('error', 'Vyber účet a CSV soubor.');

            return $this->redirect('/earnings/import');
        }
        $tmp = (string) $file['tmp_name'];
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $isText = str_starts_with($mime, 'text/') || $mime === 'application/csv';
        if (!is_uploaded_file($tmp) || !in_array($extension, ['csv', 'txt'], true) || !$isText) {
            $this->flash('error', 'Soubor musí být CSV (text).');

            return $this->redirect('/earnings/import');
        }
        if ((int) $file['size'] > CsvImporter::MAX_BYTES) {
            $this->flash('error', 'CSV může mít max. 5 MB.');

            return $this->redirect('/earnings/import');
        }
        $dir = $this->app->storagePath('tmp');
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Nelze vytvořit storage/tmp.');
        }
        $token = bin2hex(random_bytes(16));
        $path = $dir . '/import-' . $token . '.csv';
        if (!move_uploaded_file($tmp, $path)) {
            throw new RuntimeException('CSV se nepodařilo uložit.');
        }
        $imports = $this->app->session->get(self::IMPORT_SESSION, []);
        $imports = is_array($imports) ? $imports : [];
        $imports[$token] = ['path' => $path, 'account_id' => $accountId, 'created' => time(), 'name' => mb_substr((string) $file['name'], 0, 100)];
        $this->app->session->set(self::IMPORT_SESSION, $imports);

        return $this->redirect('/earnings/import', ['token' => $token]);
    }

    public function importConfirm(Request $request): Response
    {
        $token = $request->input('token');
        $pending = $this->pendingImport($token);
        if ($pending === null) {
            $this->flash('error', 'Import vypršel, nahraj soubor znovu.');

            return $this->redirect('/earnings/import');
        }
        $account = $this->findOrFail('accounts', (int) $pending['account_id']);
        $mapping = [];
        foreach (array_keys(CsvImporter::FIELDS) as $field) {
            $value = $request->input('map_' . $field);
            $mapping[$field] = preg_match('/^\d{1,3}$/', $value) === 1 ? (int) $value : null;
        }
        $timezone = $request->input('timezone') === 'UTC' ? 'UTC' : Clock::localZone()->getName();

        try {
            $result = $this->importer()->prepare($pending['path'], $mapping, [
                'account_id' => (int) $account['id'],
                'currency' => (string) $account['currency'],
                'fee_percent' => (float) $account['fee_percent'],
                'timezone' => $timezone,
            ]);
        } catch (InvalidArgumentException|ExchangeRateUnavailable $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/earnings/import', ['token' => $token]);
        }

        $inserted = $this->app->db->transaction(function () use ($result, $account): int {
            $ledger = $this->ledger();
            $count = 0;
            foreach ($result['rows'] as $entry) {
                $row = $entry['row'];
                if ($entry['fan'] !== '') {
                    $row['fan_id'] = $ledger->upsertFan((int) $account['id'], 'csv:' . mb_strtolower($entry['fan']), $entry['fan'], $entry['fan']);
                }
                if ($ledger->insertTransaction($row) !== null) {
                    $count++;
                }
            }

            return $count;
        });

        $this->forgetImport($token);
        $duplicates = count($result['rows']) - $inserted;
        $this->flash('success', "Importováno {$inserted} plateb" . ($duplicates > 0 ? ", přeskočeno {$duplicates} duplicit" : '') . '.');
        foreach (array_slice($result['errors'], 0, 5) as $error) {
            $this->flash('error', $error);
        }
        if (count($result['errors']) > 5) {
            $this->flash('error', 'A dalších ' . (count($result['errors']) - 5) . ' chybných řádků.');
        }

        return $this->redirect('/accounts/' . $account['id']);
    }

    private function importer(): CsvImporter
    {
        return new CsvImporter($this->ledger());
    }

    /** @return array{path: string, account_id: int, created: int, name: string}|null */
    private function pendingImport(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }
        $imports = $this->app->session->get(self::IMPORT_SESSION, []);
        $pending = is_array($imports) ? ($imports[$token] ?? null) : null;
        if (!is_array($pending) || time() - (int) $pending['created'] > self::IMPORT_TTL || !is_file((string) $pending['path'])) {
            if (is_array($pending)) {
                $this->forgetImport($token);
            }

            return null;
        }

        return $pending;
    }

    private function forgetImport(string $token): void
    {
        $imports = $this->app->session->get(self::IMPORT_SESSION, []);
        if (is_array($imports) && isset($imports[$token])) {
            @unlink((string) $imports[$token]['path']);
            unset($imports[$token]);
            $this->app->session->set(self::IMPORT_SESSION, $imports);
        }
    }
}
