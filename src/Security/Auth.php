<?php

declare(strict_types=1);

namespace App\Security;

use App\Database\Database;
use App\Kernel\Session;
use App\Support\Clock;

final class Auth
{
    private const SESSION_USER = '_uid';
    private const SESSION_PENDING = '_2fa_pending';
    /** Otisk hashe hesla v session: po změně hesla přestanou platit všechna ostatní přihlášení. */
    private const SESSION_PASSWORD_FINGERPRINT = '_pwv';
    private const PENDING_TTL = 300;

    /** @var array<string, mixed>|null */
    private ?array $user = null;

    public function __construct(
        private readonly Database $db,
        private readonly Session $session,
        private readonly Csrf $csrf,
    ) {
    }

    /**
     * Ověří jméno a heslo. Při neexistujícím uživateli se stejně ověřuje proti fiktivnímu hashi,
     * aby odezva neprozrazovala existenci účtu.
     *
     * @return array<string, mixed>|null
     */
    public function verifyCredentials(string $username, string $password): ?array
    {
        $user = $this->db->one('SELECT * FROM users WHERE username = :u', ['u' => $username]);
        if ($user === null) {
            Passwords::verify($password, Passwords::dummyHash());

            return null;
        }
        if (!Passwords::verify($password, (string) $user['password_hash'])) {
            return null;
        }
        if (Passwords::needsRehash((string) $user['password_hash'])) {
            $this->db->update('users', ['password_hash' => Passwords::hash($password)], ['id' => $user['id']]);
        }

        return $user;
    }

    public function beginTwoFactor(int $userId): void
    {
        $this->session->regenerate();
        $this->session->set(self::SESSION_PENDING, ['uid' => $userId, 'at' => time()]);
    }

    public function pendingTwoFactorUserId(): ?int
    {
        $pending = $this->session->get(self::SESSION_PENDING);
        if (!is_array($pending) || time() - (int) ($pending['at'] ?? 0) > self::PENDING_TTL) {
            $this->session->remove(self::SESSION_PENDING);

            return null;
        }

        return (int) $pending['uid'];
    }

    public function completeLogin(int $userId): void
    {
        $this->session->remove(self::SESSION_PENDING);
        $this->session->regenerate();
        $this->session->set(self::SESSION_USER, $userId);
        $this->csrf->rotate();
        $this->db->update('users', ['last_login_at' => Clock::nowUtc()], ['id' => $userId]);
        $this->user = null;
        $this->rememberPasswordFingerprint();
    }

    /** Po změně hesla: aktuální session zůstane platná, ostatní se odhlásí. */
    public function rememberPasswordFingerprint(): void
    {
        $this->user = null;
        $id = $this->session->get(self::SESSION_USER);
        $hash = is_int($id) ? $this->db->scalar('SELECT password_hash FROM users WHERE id = :id', ['id' => $id]) : null;
        if (is_string($hash)) {
            $this->session->set(self::SESSION_PASSWORD_FINGERPRINT, self::fingerprint($hash));
        }
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if ($this->user !== null) {
            return $this->user;
        }
        $id = $this->session->get(self::SESSION_USER);
        if (!is_int($id)) {
            return null;
        }
        $user = $this->db->one('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        $fingerprint = $this->session->get(self::SESSION_PASSWORD_FINGERPRINT);
        if ($user === null || !is_string($fingerprint) || !hash_equals(self::fingerprint((string) $user['password_hash']), $fingerprint)) {
            // Účet smazaný nebo heslo mezitím změněné → tahle session už neplatí.
            $this->session->remove(self::SESSION_USER);
            $this->session->remove(self::SESSION_PASSWORD_FINGERPRINT);

            return null;
        }

        return $this->user = $user;
    }

    private static function fingerprint(string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }

    public function logout(): void
    {
        $this->user = null;
        $this->session->destroy();
    }
}
