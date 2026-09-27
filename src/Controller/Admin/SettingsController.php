<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Security\Passwords;
use App\Security\Totp;
use App\Service\ExchangeRateUnavailable;
use App\Support\Money;
use App\Support\Validator;

final class SettingsController extends Controller
{
    private const PENDING_TOTP = '_totp_setup';

    public function index(Request $request): Response
    {
        $user = $this->app->auth->user() ?? [];
        $pendingSecret = $this->app->session->get(self::PENDING_TOTP);
        $settings = $this->settings();

        return $this->render('settings/index', [
            'title' => 'Nastavení',
            'goal' => $settings->monthlyGoalMinor(),
            'goalBasis' => $settings->goalBasis(),
            'user' => $user,
            'pendingSecret' => is_string($pendingSecret) ? $pendingSecret : null,
            'provisioningUri' => is_string($pendingSecret)
                ? Totp::provisioningUri($pendingSecret, (string) ($user['username'] ?? 'admin'), $this->app->config->string('app_name', 'AI Model Studio'))
                : null,
            'rates' => $this->app->db->all(
                'SELECT r.currency, r.rate_date, r.czk_per_unit FROM exchange_rates r
                 WHERE r.rate_date = (SELECT MAX(rate_date) FROM exchange_rates x WHERE x.currency = r.currency) ORDER BY r.currency'
            ),
            'fanvueConfigured' => $this->fanvueClient()->isConfigured(),
            'redirectUri' => $this->app->urls->absoluteAdmin('/integrations/fanvue/callback'),
        ]);
    }

    public function updateGoal(Request $request): Response
    {
        $v = new Validator();
        $goal = $v->money('goal', $request->input('goal'), 'Měsíční cíl');
        $basis = $v->oneOf('goal_basis', $request->input('goal_basis'), ['profit', 'net'], 'Počítat cíl z');
        if ($v->fails()) {
            return $this->backWithErrors($request, $v, '/settings');
        }
        $this->settings()->set('monthly_goal_czk_minor', (string) $goal);
        $this->settings()->set('goal_basis', $basis);
        $this->flash('success', 'Cíl uložen: ' . Money::format($goal, 'CZK', false) . ' měsíčně.');

        return $this->redirect('/settings');
    }

    public function changeUsername(Request $request): Response
    {
        $user = $this->app->auth->user();
        if ($user === null || !Passwords::verify($request->rawInput('current_password'), (string) $user['password_hash'])) {
            return $this->backWithErrors($request, ['username_password' => 'Současné heslo nesouhlasí.'], '/settings');
        }
        $username = $request->input('username');
        if (preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username) !== 1) {
            return $this->backWithErrors($request, ['username' => 'Přihlašovací jméno: 3–50 znaků, jen písmena bez diakritiky, číslice, tečka, pomlčka a podtržítko.'], '/settings');
        }
        if ($this->app->db->scalar('SELECT 1 FROM users WHERE username = :u AND id != :id', ['u' => $username, 'id' => $user['id']]) !== null) {
            return $this->backWithErrors($request, ['username' => 'Toto jméno už má jiný uživatel.'], '/settings');
        }
        $this->app->db->update('users', ['username' => $username], ['id' => $user['id']]);
        $this->app->logger->info('auth.username_changed', ['from' => $user['username'], 'to' => $username]);
        $this->flash('success', 'Přihlašovací jméno změněno. Příště se přihlas jako ' . $username . '.');

        return $this->redirect('/settings');
    }

    public function changePassword(Request $request): Response
    {
        $user = $this->app->auth->user();
        if ($user === null || !Passwords::verify($request->rawInput('current_password'), (string) $user['password_hash'])) {
            return $this->backWithErrors($request, ['current_password' => 'Současné heslo nesouhlasí.'], '/settings');
        }
        $new = $request->rawInput('password');
        $problem = Passwords::validate($new);
        if ($problem === null && $new !== $request->rawInput('password_confirm')) {
            $problem = 'Hesla se neshodují.';
        }
        if ($problem !== null) {
            return $this->backWithErrors($request, ['password' => $problem], '/settings');
        }
        $this->app->db->update('users', ['password_hash' => Passwords::hash($new)], ['id' => $user['id']]);
        $this->app->session->regenerate();
        $this->app->auth->rememberPasswordFingerprint(); // ostatní přihlášená zařízení se tím odhlásí
        $this->app->logger->info('auth.password_changed', ['user' => $user['username']]);
        $this->flash('success', 'Heslo změněno. Ostatní přihlášená zařízení byla odhlášena.');

        return $this->redirect('/settings');
    }

    public function startTwoFactor(Request $request): Response
    {
        $this->app->session->set(self::PENDING_TOTP, Totp::generateSecret());

        return Response::redirect($this->app->urls->admin('/settings') . '#twofactor');
    }

    public function enableTwoFactor(Request $request): Response
    {
        $user = $this->app->auth->user();
        $secret = $this->app->session->get(self::PENDING_TOTP);
        if ($user === null || !is_string($secret)) {
            return $this->redirect('/settings');
        }
        $step = Totp::verify($secret, $request->input('code'), 0);
        if ($step === null) {
            return $this->backWithErrors($request, ['code' => 'Kód nesouhlasí. Zkontroluj, že máš v telefonu správný čas.'], '/settings');
        }
        $this->app->db->update('users', [
            'totp_secret_enc' => $this->app->crypto->encrypt($secret),
            'totp_enabled' => 1,
            'totp_last_step' => $step,
        ], ['id' => $user['id']]);
        $this->app->session->remove(self::PENDING_TOTP);
        $this->app->logger->info('auth.2fa_enabled', ['user' => $user['username']]);
        $this->flash('success', 'Dvoufázové ověření zapnuto. Při ztrátě telefonu ho vypneš příkazem php bin/console user:2fa-reset.');

        return $this->redirect('/settings');
    }

    public function disableTwoFactor(Request $request): Response
    {
        $user = $this->app->auth->user();
        if ($user === null || !Passwords::verify($request->rawInput('current_password'), (string) $user['password_hash'])) {
            return $this->backWithErrors($request, ['disable_password' => 'Pro vypnutí 2FA zadej správné heslo.'], '/settings');
        }
        $this->app->db->update('users', ['totp_secret_enc' => null, 'totp_enabled' => 0, 'totp_last_step' => 0], ['id' => $user['id']]);
        $this->app->logger->warning('auth.2fa_disabled', ['user' => $user['username']]);
        $this->flash('success', 'Dvoufázové ověření vypnuto.');

        return $this->redirect('/settings');
    }

    public function fetchRates(Request $request): Response
    {
        try {
            $count = 0;
            foreach (['USD', 'EUR', 'GBP'] as $currency) {
                $count += $this->exchangeRates()->refresh($currency);
            }
            $this->flash('success', "Kurzy ČNB aktualizovány ({$count} záznamů).");
        } catch (ExchangeRateUnavailable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/settings');
    }
}
