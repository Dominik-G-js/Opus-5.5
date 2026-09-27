<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\Database;

final class Settings
{
    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->db->scalar('SELECT value FROM settings WHERE key = :k', ['k' => $key]);

        return $value === null ? $default : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $this->db->run(
            'INSERT INTO settings (key, value) VALUES (:k, :v) ON CONFLICT (key) DO UPDATE SET value = excluded.value',
            ['k' => $key, 'v' => $value]
        );
    }

    public function monthlyGoalMinor(): int
    {
        return (int) $this->get('monthly_goal_czk_minor', '1000000');
    }

    /** 'profit' = čistý zisk (příjmy po poplatcích − náklady), 'net' = příjmy po poplatcích platforem. */
    public function goalBasis(): string
    {
        return $this->get('goal_basis', 'profit') === 'net' ? 'net' : 'profit';
    }
}
