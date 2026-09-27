<?php

declare(strict_types=1);

namespace App\Database;

use App\Support\Clock;
use RuntimeException;

final class Migrator
{
    public function __construct(private readonly Database $db, private readonly string $directory)
    {
    }

    /** @return list<string> Názvy nově aplikovaných migrací. */
    public function migrate(): array
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)'
        );
        $applied = array_column($this->db->all('SELECT version FROM schema_migrations'), 'version');
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $new = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Nelze načíst migraci {$file}");
            }
            $this->db->transaction(function () use ($sql, $version): void {
                $this->db->pdo()->exec($sql);
                $this->db->insert('schema_migrations', ['version' => $version, 'applied_at' => Clock::nowUtc()]);
            });
            $new[] = $version;
        }

        return $new;
    }
}
