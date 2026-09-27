<?php

declare(strict_types=1);

namespace App\Security;

use App\Database\Database;
use DateTimeImmutable;

/**
 * Omezení hádání hesel. Limity jsou nastavené tak, aby útočník neměl šanci,
 * ale aby nešlo jednoduše zablokovat přihlášení majiteli z jiné IP.
 */
final class LoginThrottle
{
    private const LIMITS = [
        // [scope, max. neúspěchů, okno v sekundách]
        ['ip_user', 5, 900],
        ['ip', 20, 3600],
        ['user', 50, 3600],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** Vrací počet sekund do dalšího povoleného pokusu, 0 = lze zkusit. */
    public function retryAfter(string $ip, string $username): int
    {
        $username = mb_strtolower($username);
        $wait = 0;
        foreach (self::LIMITS as [$scope, $max, $window]) {
            $since = $this->since($window);
            [$where, $params] = match ($scope) {
                'ip_user' => ['ip = :ip AND username = :u', ['ip' => $ip, 'u' => $username]],
                'ip' => ['ip = :ip', ['ip' => $ip]],
                default => ['username = :u', ['u' => $username]],
            };
            $rows = $this->db->all(
                "SELECT created_at FROM login_attempts WHERE {$where} AND success = 0 AND created_at >= :since
                 ORDER BY created_at DESC LIMIT {$max}",
                $params + ['since' => $since]
            );
            if (count($rows) >= $max) {
                $oldest = new DateTimeImmutable($rows[$max - 1]['created_at'] . ' UTC');
                $wait = max($wait, $oldest->getTimestamp() + $window - time());
            }
        }

        return max(0, $wait);
    }

    public function record(string $ip, string $username, bool $success): void
    {
        $this->db->insert('login_attempts', [
            'ip' => $ip,
            'username' => mb_strtolower(mb_substr($username, 0, 100)),
            'success' => $success ? 1 : 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function purgeOlderThanDays(int $days): int
    {
        return $this->db->run(
            'DELETE FROM login_attempts WHERE created_at < :before',
            ['before' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]
        )->rowCount();
    }

    private function since(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }
}
