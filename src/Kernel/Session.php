<?php

declare(strict_types=1);

namespace App\Kernel;

use RuntimeException;

/**
 * Obal nad PHP session s bezpečným nastavením cookie a vypršením po nečinnosti i absolutně.
 */
final class Session
{
    private bool $started = false;

    public function __construct(
        private readonly string $name,
        private readonly int $idleTimeout,
        private readonly int $absoluteTimeout,
        private readonly string $cookiePath,
    ) {
    }

    public function start(bool $secure): void
    {
        if ($this->started) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session už běží mimo aplikaci.');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        session_name($this->name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->cookiePath,
            'secure' => $secure,
            'httponly' => true,
            // Lax: cookie musí přežít návrat z OAuth (top-level GET z auth.fanvue.com). CSRF chrání tokeny.
            'samesite' => 'Lax',
        ]);
        session_start();
        $this->started = true;

        $now = time();
        $created = (int) ($_SESSION['_created'] ?? 0);
        $lastSeen = (int) ($_SESSION['_last_seen'] ?? 0);
        if ($created > 0 && ($now - $lastSeen > $this->idleTimeout || $now - $created > $this->absoluteTimeout)) {
            $this->destroy();
            session_start();
            $this->started = true;
            $_SESSION['_expired'] = true;
        }
        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = $now;
        }
        $_SESSION['_last_seen'] = $now;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);

        return $value;
    }

    /** Nové ID po změně úrovně oprávnění (přihlášení) — ochrana proti session fixation. */
    public function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => $params['samesite'] ?: 'Lax',
            ]);
            session_destroy();
        }
        $this->started = false;
    }

    public function flash(string $type, string $message): void
    {
        $messages = $_SESSION['_flash'] ?? [];
        $messages[] = ['type' => $type, 'message' => $message];
        $_SESSION['_flash'] = $messages;
    }

    /** @return list<array{type: string, message: string}> */
    public function takeFlashes(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return is_array($messages) ? $messages : [];
    }

    /**
     * Uložení odeslaných hodnot formuláře pro znovuvyplnění po chybě validace.
     *
     * @param array<string, mixed> $input
     */
    public function flashInput(array $input): void
    {
        unset($input['_csrf'], $input['password'], $input['password_confirm'], $input['current_password']);
        $_SESSION['_old'] = $input;
    }

    /** @return array<string, mixed> */
    public function takeOldInput(): array
    {
        $old = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);

        return is_array($old) ? $old : [];
    }
}
