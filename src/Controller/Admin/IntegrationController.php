<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Integration\Fanvue\FanvueClient;
use App\Integration\Fanvue\FanvueException;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Service\ExchangeRateUnavailable;

/**
 * Připojení Fanvue účtu přes OAuth 2.0 (PKCE) a ruční synchronizace.
 */
final class IntegrationController extends Controller
{
    private const OAUTH_SESSION = 'fanvue_oauth';
    private const OAUTH_TTL = 600;

    public function fanvueConnect(Request $request): Response
    {
        $account = $this->findOrFail('accounts', $request->intParam('id'));
        $client = $this->fanvueClient();
        if (!$client->isConfigured()) {
            $this->flash('error', 'Nejdřív vyplň fanvue.client_id a client_secret v config/config.php (viz README).');

            return $this->redirect('/accounts/' . $account['id']);
        }
        $pkce = FanvueClient::createPkce();
        $state = bin2hex(random_bytes(24));
        $this->app->session->set(self::OAUTH_SESSION, [
            'state' => $state,
            'verifier' => $pkce['verifier'],
            'account_id' => (int) $account['id'],
            'created' => time(),
        ]);

        return Response::redirect($client->authorizationUrl($this->redirectUri(), $state, $pkce['challenge']));
    }

    public function fanvueCallback(Request $request): Response
    {
        $pending = $this->app->session->pull(self::OAUTH_SESSION);
        if (!is_array($pending) || time() - (int) ($pending['created'] ?? 0) > self::OAUTH_TTL) {
            $this->flash('error', 'Přihlášení k Fanvue vypršelo, zkus to znovu.');

            return $this->redirect('/models');
        }
        $accountId = (int) $pending['account_id'];
        $state = $request->query('state');
        if ($state === '' || !hash_equals((string) $pending['state'], $state)) {
            $this->app->logger->warning('fanvue.state_mismatch', ['account' => $accountId]);
            $this->flash('error', 'Neplatný stav OAuth (možný pokus o podvržení). Zkus připojení znovu.');

            return $this->redirect('/accounts/' . $accountId);
        }
        $error = $request->query('error');
        if ($error !== '') {
            $this->flash('error', 'Fanvue připojení zamítnuto: ' . mb_substr($error . ' ' . $request->query('error_description'), 0, 200));

            return $this->redirect('/accounts/' . $accountId);
        }
        $code = $request->query('code');
        if ($code === '') {
            $this->flash('error', 'Fanvue nevrátil autorizační kód.');

            return $this->redirect('/accounts/' . $accountId);
        }

        try {
            $tokens = $this->fanvueClient()->exchangeCode($code, (string) $pending['verifier'], $this->redirectUri());
            $this->fanvueSync()->connect($accountId, $tokens);
            $this->app->logger->info('fanvue.connected', ['account' => $accountId]);
            $this->flash('success', 'Fanvue účet připojen. Spusť první synchronizaci.');
        } catch (FanvueException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/accounts/' . $accountId);
    }

    public function sync(Request $request): Response
    {
        $account = $this->findOrFail('accounts', $request->intParam('id'));
        set_time_limit(300);
        try {
            $result = $this->fanvueSync()->sync((int) $account['id']);
            $this->flash('success', "Synchronizováno: {$result['imported']} plateb za posledních {$result['days']} dní.");
        } catch (FanvueException $e) {
            $this->flash('error', $e->getMessage() . ($e->reconnectRequired ? ' Klikni na „Připojit Fanvue“.' : ''));
        } catch (ExchangeRateUnavailable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/accounts/' . $account['id']);
    }

    public function disconnect(Request $request): Response
    {
        $account = $this->findOrFail('accounts', $request->intParam('id'));
        $this->app->db->update('accounts', [
            'integration' => 'none',
            'credentials_enc' => null,
            'last_sync_error' => null,
        ], ['id' => $account['id']]);
        $this->app->logger->info('fanvue.disconnected', ['account' => $account['id']]);
        $this->flash('success', 'Fanvue odpojen. Stažená data zůstala. Přístup aplikace zruš i ve Fanvue nastavení.');

        return $this->redirect('/accounts/' . $account['id']);
    }

    private function redirectUri(): string
    {
        return $this->app->urls->absoluteAdmin('/integrations/fanvue/callback');
    }
}
