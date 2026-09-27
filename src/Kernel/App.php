<?php

declare(strict_types=1);

namespace App\Kernel;

use App\Controller\Admin;
use App\Controller\PublicSite\PublicController;
use App\Database\Database;
use App\Integration\Fanvue\FanvueClient;
use App\Security\Auth;
use App\Security\Crypto;
use App\Security\Csrf;
use App\Security\LoginThrottle;
use App\Support\Clock;
use App\Support\Logger;
use Throwable;

/**
 * Sestavení služeb a zpracování požadavku: host → router → middleware (auth, CSRF) → hlavičky.
 */
final class App
{
    public readonly Database $db;
    public readonly Logger $logger;
    public readonly UrlGenerator $urls;
    public readonly Session $session;
    public readonly Csrf $csrf;
    public readonly Auth $auth;
    public readonly LoginThrottle $throttle;
    public readonly Crypto $crypto;
    public readonly ViewHelpers $helpers;
    public readonly View $view;
    public readonly string $rootDir;
    private ?string $cspNonce = null;

    public function __construct(public readonly Config $config, string $rootDir)
    {
        $this->rootDir = rtrim($rootDir, '/');
        Clock::setLocalZone($config->string('timezone', 'Europe/Prague'));
        $this->logger = new Logger($this->storagePath('logs'));
        $this->db = new Database($config->string('db_path', $this->storagePath('database.sqlite')));
        $this->urls = new UrlGenerator($config->string('base_url'), '/' . trim($config->string('admin_path', '/admin'), '/'));
        $this->session = new Session(
            $config->string('session.name', 'ams_sid'),
            $config->int('session.idle_timeout', 7200),
            $config->int('session.absolute_timeout', 43200),
            ($this->urls->basePath() . $this->urls->adminPath()) ?: '/',
        );
        $this->csrf = new Csrf($this->session);
        $this->auth = new Auth($this->db, $this->session, $this->csrf);
        $this->throttle = new LoginThrottle($this->db);
        $this->crypto = new Crypto($config->string('app_key'));
        $this->helpers = new ViewHelpers($this->urls, $this->csrf, $this->session, $config->string('app_name', 'AI Model Studio'));
        $this->view = new View($this->rootDir . '/templates', $this->helpers);
    }

    public function storagePath(string $sub = ''): string
    {
        return $this->rootDir . '/storage' . ($sub !== '' ? '/' . ltrim($sub, '/') : '');
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (HttpException $e) {
            $response = $this->errorResponse($e->status, $e->getMessage(), $request);
        } catch (Throwable $e) {
            $this->logger->exception($e);
            $message = $this->config->bool('debug') ? $e->getMessage() : 'Něco se pokazilo. Podrobnosti jsou v logu.';
            $response = $this->errorResponse(500, $message, $request);
        }

        return $this->withSecurityHeaders($response, $request);
    }

    /** Nonce pro inline CSS veřejných stránek (CSP). */
    public function cspNonce(): string
    {
        return $this->cspNonce ??= base64_encode(random_bytes(16));
    }

    private function dispatch(Request $request): Response
    {
        $adminHost = $this->urls->host();
        if ($request->host !== $adminHost) {
            return (new PublicController($this))->handleCustomDomain($request);
        }

        $adminPrefix = $this->urls->adminPath();
        $isAdmin = $request->path === $adminPrefix || str_starts_with($request->path, $adminPrefix . '/');
        if (!$isAdmin) {
            return (new PublicController($this))->handleMainHost($request);
        }

        if ($this->config->bool('force_https', true) && !$request->secure) {
            if ($request->method !== 'GET') {
                throw new HttpException(403, 'Administrace vyžaduje HTTPS.');
            }

            return Response::redirect('https://' . $adminHost . $this->urls->admin(substr($request->path, strlen($adminPrefix))), 301);
        }

        $this->session->start($request->secure);
        $router = new Router();
        $this->registerAdminRoutes($router, $adminPrefix);
        [$handler, $params] = $router->match($request->method, $request->path);
        $request = $request->withRouteParams($params);

        if ($request->isPost()) {
            $this->csrf->verify($request, $this->urls->origin());
        }

        $publicAdminPaths = [$adminPrefix . '/login', $adminPrefix . '/login/2fa'];
        $user = $this->auth->user();
        if ($user === null && !in_array($request->path, $publicAdminPaths, true)) {
            if ($this->session->pull('_expired') === true) {
                $this->session->flash('info', 'Byl(a) jsi odhlášen(a) kvůli nečinnosti.');
            }

            return Response::redirect($this->urls->admin('/login'));
        }

        $errors = $this->session->pull('_errors', []);
        $this->helpers->setRequestState(
            $request->path,
            $this->session->takeOldInput(),
            is_array($errors) ? $errors : [],
            $user
        );

        $response = $handler($request);

        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function registerAdminRoutes(Router $r, string $p): void
    {
        $auth = fn () => new Admin\AuthController($this);
        $r->get($p . '/login', fn (Request $q) => $auth()->showLogin($q));
        $r->post($p . '/login', fn (Request $q) => $auth()->login($q));
        $r->get($p . '/login/2fa', fn (Request $q) => $auth()->showTwoFactor($q));
        $r->post($p . '/login/2fa', fn (Request $q) => $auth()->verifyTwoFactor($q));
        $r->post($p . '/logout', fn (Request $q) => $auth()->logout($q));

        $dash = fn () => new Admin\DashboardController($this);
        $r->get($p, fn (Request $q) => $dash()->index($q));

        $models = fn () => new Admin\ModelController($this);
        $r->get($p . '/models', fn (Request $q) => $models()->index($q));
        $r->get($p . '/models/new', fn (Request $q) => $models()->create($q));
        $r->post($p . '/models', fn (Request $q) => $models()->store($q));
        $r->get($p . '/models/{id}', fn (Request $q) => $models()->show($q));
        $r->get($p . '/models/{id}/edit', fn (Request $q) => $models()->edit($q));
        $r->post($p . '/models/{id}', fn (Request $q) => $models()->update($q));
        $r->post($p . '/models/{id}/delete', fn (Request $q) => $models()->delete($q));
        $r->post($p . '/models/{id}/tools', fn (Request $q) => $models()->attachTool($q));
        $r->post($p . '/models/{id}/tools/{toolId}/detach', fn (Request $q) => $models()->detachTool($q));
        $r->get($p . '/models/{id}/bible', fn (Request $q) => $models()->bible($q));

        $prompts = fn () => new Admin\PromptController($this);
        $r->get($p . '/models/{id}/prompts/new', fn (Request $q) => $prompts()->create($q));
        $r->post($p . '/models/{id}/prompts', fn (Request $q) => $prompts()->store($q));
        $r->get($p . '/prompts/{id}/edit', fn (Request $q) => $prompts()->edit($q));
        $r->post($p . '/prompts/{id}', fn (Request $q) => $prompts()->update($q));
        $r->post($p . '/prompts/{id}/delete', fn (Request $q) => $prompts()->delete($q));
        $r->post($p . '/prompts/{id}/duplicate', fn (Request $q) => $prompts()->duplicate($q));
        $r->post($p . '/prompts/{id}/restore/{versionId}', fn (Request $q) => $prompts()->restore($q));

        $images = fn () => new Admin\ImageController($this);
        $r->post($p . '/models/{id}/images', fn (Request $q) => $images()->upload($q));
        $r->get($p . '/images/{id}', fn (Request $q) => $images()->serve($q));
        $r->post($p . '/images/{id}', fn (Request $q) => $images()->update($q));
        $r->post($p . '/images/{id}/delete', fn (Request $q) => $images()->delete($q));

        $tools = fn () => new Admin\ToolController($this);
        $r->get($p . '/tools', fn (Request $q) => $tools()->index($q));
        $r->get($p . '/tools/new', fn (Request $q) => $tools()->create($q));
        $r->post($p . '/tools', fn (Request $q) => $tools()->store($q));
        $r->get($p . '/tools/{id}/edit', fn (Request $q) => $tools()->edit($q));
        $r->post($p . '/tools/{id}', fn (Request $q) => $tools()->update($q));
        $r->post($p . '/tools/{id}/delete', fn (Request $q) => $tools()->delete($q));

        $costs = fn () => new Admin\CostController($this);
        $r->get($p . '/costs', fn (Request $q) => $costs()->index($q));
        $r->get($p . '/costs/new', fn (Request $q) => $costs()->create($q));
        $r->get($p . '/costs/export', fn (Request $q) => $costs()->export($q));
        $r->post($p . '/costs', fn (Request $q) => $costs()->store($q));
        $r->get($p . '/costs/{id}/edit', fn (Request $q) => $costs()->edit($q));
        $r->post($p . '/costs/{id}', fn (Request $q) => $costs()->update($q));
        $r->post($p . '/costs/{id}/delete', fn (Request $q) => $costs()->delete($q));

        $platforms = fn () => new Admin\PlatformController($this);
        $r->get($p . '/platforms', fn (Request $q) => $platforms()->index($q));
        $r->get($p . '/platforms/new', fn (Request $q) => $platforms()->create($q));
        $r->post($p . '/platforms', fn (Request $q) => $platforms()->store($q));
        $r->get($p . '/platforms/{id}/edit', fn (Request $q) => $platforms()->edit($q));
        $r->post($p . '/platforms/{id}', fn (Request $q) => $platforms()->update($q));

        $accounts = fn () => new Admin\AccountController($this);
        $r->get($p . '/models/{id}/accounts/new', fn (Request $q) => $accounts()->create($q));
        $r->post($p . '/models/{id}/accounts', fn (Request $q) => $accounts()->store($q));
        $r->get($p . '/accounts/{id}', fn (Request $q) => $accounts()->show($q));
        $r->get($p . '/accounts/{id}/edit', fn (Request $q) => $accounts()->edit($q));
        $r->post($p . '/accounts/{id}', fn (Request $q) => $accounts()->update($q));
        $r->post($p . '/accounts/{id}/delete', fn (Request $q) => $accounts()->delete($q));

        $fans = fn () => new Admin\FanController($this);
        $r->get($p . '/fans', fn (Request $q) => $fans()->index($q));
        $r->get($p . '/fans/{id}', fn (Request $q) => $fans()->show($q));
        $r->post($p . '/fans/{id}', fn (Request $q) => $fans()->update($q));

        $tx = fn () => new Admin\TransactionController($this);
        $r->get($p . '/earnings', fn (Request $q) => $tx()->index($q));
        $r->get($p . '/earnings/new', fn (Request $q) => $tx()->create($q));
        $r->get($p . '/earnings/export', fn (Request $q) => $tx()->export($q));
        $r->post($p . '/earnings', fn (Request $q) => $tx()->store($q));
        $r->post($p . '/earnings/{id}/delete', fn (Request $q) => $tx()->delete($q));
        $r->get($p . '/earnings/import', fn (Request $q) => $tx()->importForm($q));
        $r->post($p . '/earnings/import', fn (Request $q) => $tx()->importUpload($q));
        $r->post($p . '/earnings/import/confirm', fn (Request $q) => $tx()->importConfirm($q));

        $links = fn () => new Admin\LinkController($this);
        $r->get($p . '/links', fn (Request $q) => $links()->index($q));
        $r->get($p . '/links/new', fn (Request $q) => $links()->create($q));
        $r->post($p . '/links', fn (Request $q) => $links()->store($q));
        $r->get($p . '/links/{id}/edit', fn (Request $q) => $links()->edit($q));
        $r->post($p . '/links/{id}', fn (Request $q) => $links()->update($q));
        $r->post($p . '/links/{id}/delete', fn (Request $q) => $links()->delete($q));

        $integrations = fn () => new Admin\IntegrationController($this);
        $r->post($p . '/accounts/{id}/fanvue/connect', fn (Request $q) => $integrations()->fanvueConnect($q));
        $r->get($p . '/integrations/fanvue/callback', fn (Request $q) => $integrations()->fanvueCallback($q));
        $r->post($p . '/accounts/{id}/sync', fn (Request $q) => $integrations()->sync($q));
        $r->post($p . '/accounts/{id}/disconnect', fn (Request $q) => $integrations()->disconnect($q));

        $settings = fn () => new Admin\SettingsController($this);
        $r->get($p . '/settings', fn (Request $q) => $settings()->index($q));
        $r->post($p . '/settings/goal', fn (Request $q) => $settings()->updateGoal($q));
        $r->post($p . '/settings/password', fn (Request $q) => $settings()->changePassword($q));
        $r->post($p . '/settings/2fa/start', fn (Request $q) => $settings()->startTwoFactor($q));
        $r->post($p . '/settings/2fa/enable', fn (Request $q) => $settings()->enableTwoFactor($q));
        $r->post($p . '/settings/2fa/disable', fn (Request $q) => $settings()->disableTwoFactor($q));
        $r->post($p . '/settings/rates', fn (Request $q) => $settings()->fetchRates($q));
    }

    private function errorResponse(int $status, string $message, Request $request): Response
    {
        $isAdmin = str_starts_with($request->path, $this->urls->adminPath()) && $request->host === $this->urls->host();
        $html = $this->view->render('error', [
            'status' => $status,
            'message' => $message,
            'backUrl' => $isAdmin ? $this->urls->admin() : null,
        ], null);
        $response = Response::html($html, $status);
        if ($status === 429) {
            $response->withHeader('Retry-After', '900');
        }

        return $response->withHeader('Cache-Control', 'no-store');
    }

    private function fanvueAuthOrigin(): string
    {
        $parts = parse_url($this->config->string('fanvue.auth_base', FanvueClient::AUTH_BASE));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return FanvueClient::AUTH_BASE;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function withSecurityHeaders(Response $response, Request $request): Response
    {
        if (str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            // Samostatně otevřený obrázek: prohlížeč si kolem něj staví vlastní stránku s inline styly; žádné skripty.
            $response->withHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        }
        $styleSrc = $this->cspNonce !== null ? "'self' 'nonce-{$this->cspNonce}'" : "'self'";
        // Připojení Fanvue = POST s přesměrováním na jejich autorizační server (Chrome to hlídá přes form-action).
        $formAction = "'self' " . $this->fanvueAuthOrigin();
        $csp = "default-src 'self'; img-src 'self' data:; style-src {$styleSrc}; script-src 'self'; "
            . "object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action {$formAction}";

        if (!$response->hasHeader('Content-Security-Policy')) {
            $response->withHeader('Content-Security-Policy', $csp);
        }
        $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin');
        if ($request->secure && $this->config->bool('hsts', true)) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
