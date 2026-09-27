<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Kernel\HttpException;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Security\Totp;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        if ($this->app->auth->user() !== null) {
            return $this->redirect('');
        }

        return Response::html($this->app->view->render('auth/login', [], 'layout_auth'));
    }

    public function login(Request $request): Response
    {
        $username = mb_substr($request->input('username'), 0, 100);
        $password = $request->rawInput('password');
        $ip = $request->clientIp;

        $wait = $this->app->throttle->retryAfter($ip, $username);
        if ($wait > 0) {
            $this->app->logger->warning('auth.throttled', ['ip' => $ip, 'user' => $username]);
            throw new HttpException(429, 'Příliš mnoho neúspěšných pokusů. Zkus to znovu za ' . (int) ceil($wait / 60) . ' min.');
        }

        $user = $username !== '' && $password !== '' ? $this->app->auth->verifyCredentials($username, $password) : null;
        $this->app->throttle->record($ip, $username, $user !== null);
        if ($user === null) {
            $this->app->logger->warning('auth.failed', ['ip' => $ip, 'user' => $username]);
            $this->flash('error', 'Nesprávné jméno nebo heslo.');

            return $this->redirect('/login');
        }

        if ((int) $user['totp_enabled'] === 1) {
            $this->app->auth->beginTwoFactor((int) $user['id']);

            return $this->redirect('/login/2fa');
        }

        $this->app->auth->completeLogin((int) $user['id']);
        $this->app->logger->info('auth.login', ['ip' => $ip, 'user' => $user['username']]);

        return $this->redirect('');
    }

    public function showTwoFactor(Request $request): Response
    {
        if ($this->app->auth->pendingTwoFactorUserId() === null) {
            return $this->redirect('/login');
        }

        return Response::html($this->app->view->render('auth/two_factor', [], 'layout_auth'));
    }

    public function verifyTwoFactor(Request $request): Response
    {
        $userId = $this->app->auth->pendingTwoFactorUserId();
        if ($userId === null) {
            $this->flash('error', 'Ověření vypršelo, přihlas se znovu.');

            return $this->redirect('/login');
        }
        $user = $this->findOrFail('users', $userId);
        $ip = $request->clientIp;
        $throttleKey = '2fa:' . $user['username'];

        $wait = $this->app->throttle->retryAfter($ip, $throttleKey);
        if ($wait > 0) {
            throw new HttpException(429);
        }

        $secret = $this->app->crypto->decrypt((string) $user['totp_secret_enc']);
        $step = Totp::verify($secret, $request->input('code'), (int) $user['totp_last_step']);
        $this->app->throttle->record($ip, $throttleKey, $step !== null);
        if ($step === null) {
            $this->app->logger->warning('auth.2fa_failed', ['ip' => $ip, 'user' => $user['username']]);
            $this->flash('error', 'Neplatný kód. Zkontroluj čas v telefonu a zkus to znovu.');

            return $this->redirect('/login/2fa');
        }

        $this->app->db->update('users', ['totp_last_step' => $step], ['id' => $userId]);
        $this->app->auth->completeLogin($userId);
        $this->app->logger->info('auth.login', ['ip' => $ip, 'user' => $user['username'], '2fa' => true]);

        return $this->redirect('');
    }

    public function logout(Request $request): Response
    {
        $this->app->auth->logout();

        return $this->redirect('/login');
    }
}
