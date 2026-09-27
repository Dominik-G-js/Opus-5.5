<?php

declare(strict_types=1);

namespace App\Cli;

use App\Database\Database;
use App\Database\Migrator;
use App\Kernel\App;
use App\Kernel\Config;
use App\Kernel\Services;
use App\Security\Crypto;
use App\Security\Passwords;
use App\Service\ModelImportException;
use App\Support\Clock;
use RuntimeException;
use Throwable;

/**
 * Příkazová řádka: instalace, migrace, správa uživatelů a úlohy pro cron.
 */
final class Console
{
    private const HELP = <<<TXT
    AI Model Studio — příkazy:

      install [--base-url=URL] [--admin-path=/cesta] [--username=jmeno]
                              První instalace: config, složky, databáze, admin účet
      migrate                 Aplikuje nové migrace databáze
      user:create <jméno>     Vytvoří uživatele (heslo se zadá interaktivně)
      user:password <jméno>   Změní heslo
      user:2fa-reset <jméno>  Vypne 2FA (při ztrátě telefonu)
      sync [--account=ID]     Stáhne data z Fanvue API (pro cron, např. každou hodinu)
      rates:refresh           Stáhne aktuální kurzy ČNB (USD, EUR, GBP)
      cleanup                 Smaže staré pokusy o přihlášení a dočasné soubory
      model:import <soubor>   Vytvoří modelku ze souboru JSON (profil, prompty, účty, odkazy)

    Heslo lze předat i proměnnou prostředí AMS_PASSWORD (pro automatizaci).

    TXT;

    public function __construct(private readonly string $root)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $command = $args[0] ?? 'help';
        [$positional, $options] = $this->parseArgs(array_slice($args, 1));

        try {
            return match ($command) {
                'install' => $this->install($options),
                'migrate' => $this->migrate(),
                'user:create' => $this->userCreate($positional[0] ?? ''),
                'user:password' => $this->userPassword($positional[0] ?? ''),
                'user:2fa-reset' => $this->userTwoFactorReset($positional[0] ?? ''),
                'sync' => $this->sync($options),
                'rates:refresh' => $this->ratesRefresh(),
                'cleanup' => $this->cleanup(),
                'model:import' => $this->modelImport($positional[0] ?? ''),
                'help', '--help', '-h' => $this->help(),
                default => $this->fail("Neznámý příkaz „{$command}“.\n\n" . self::HELP),
            };
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** @param array<string, string> $options */
    private function install(array $options): int
    {
        $configFile = $this->root . '/config/config.php';
        if (!is_file($configFile)) {
            $baseUrl = rtrim($options['base-url'] ?? $this->ask('Adresa aplikace (např. https://studio.domafix.cz)'), '/');
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
            if (!in_array($scheme, ['http', 'https'], true) || parse_url($baseUrl, PHP_URL_HOST) === null) {
                throw new RuntimeException('Adresa musí začínat https:// (http:// jen pro lokální vývoj).');
            }
            $adminPath = '/' . trim($options['admin-path'] ?? $this->ask('Cesta k administraci', '/admin'), '/');
            if (preg_match('#^/[a-z0-9-]{2,40}$#', $adminPath) !== 1) {
                throw new RuntimeException('Cesta k administraci: jen malá písmena, číslice a pomlčky (např. /studio-k7x2).');
            }
            $this->writeConfig($configFile, $baseUrl, $adminPath, $scheme === 'https');
            $this->line("✔ Vytvořen config/config.php (práva 0600).");
        } else {
            $this->line('• config/config.php už existuje — ponechávám.');
        }

        foreach (['', 'logs', 'uploads', 'public', 'tmp'] as $dir) {
            $path = $this->root . '/storage' . ($dir !== '' ? '/' . $dir : '');
            if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
                throw new RuntimeException("Nelze vytvořit {$path}.");
            }
        }
        $this->line('✔ Složky storage/ připraveny.');

        $this->migrate();
        $app = $this->app();
        if ((int) $app->db->scalar('SELECT COUNT(*) FROM users') === 0) {
            $username = $options['username'] ?? $this->ask('Uživatelské jméno administrátora', 'admin');
            $this->userCreate($username);
        }
        $this->line('');
        $this->line('Hotovo. Přihlášení: ' . $app->urls->absoluteAdmin('/login'));
        $this->line('Doporučení: po přihlášení zapni v Nastavení dvoufázové ověření (2FA).');

        return 0;
    }

    private function migrate(): int
    {
        $config = Config::load($this->root . '/config/config.php');
        $db = new Database($config->string('db_path', $this->root . '/storage/database.sqlite'));
        $applied = (new Migrator($db, $this->root . '/migrations'))->migrate();
        $dbPath = $config->string('db_path', $this->root . '/storage/database.sqlite');
        @chmod($dbPath, 0600);
        $this->line($applied === [] ? '• Databáze je aktuální.' : '✔ Migrace: ' . implode(', ', $applied));

        return 0;
    }

    private function userCreate(string $username): int
    {
        $username = trim($username);
        if (preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username) !== 1) {
            throw new RuntimeException('Jméno: 3–50 znaků (písmena, číslice, . _ -).');
        }
        $app = $this->app();
        if ($app->db->scalar('SELECT 1 FROM users WHERE username = :u', ['u' => $username]) !== null) {
            throw new RuntimeException("Uživatel {$username} už existuje.");
        }
        $password = $this->askNewPassword();
        $app->db->insert('users', [
            'username' => $username,
            'password_hash' => Passwords::hash($password),
            'created_at' => Clock::nowUtc(),
        ]);
        $app->logger->info('cli.user_created', ['user' => $username]);
        $this->line("✔ Uživatel {$username} vytvořen.");

        return 0;
    }

    private function userPassword(string $username): int
    {
        $app = $this->app();
        $user = $app->db->one('SELECT id FROM users WHERE username = :u', ['u' => $username]);
        if ($user === null) {
            throw new RuntimeException("Uživatel „{$username}“ neexistuje.");
        }
        $app->db->update('users', ['password_hash' => Passwords::hash($this->askNewPassword())], ['id' => $user['id']]);
        $app->logger->info('cli.password_changed', ['user' => $username]);
        $this->line('✔ Heslo změněno.');

        return 0;
    }

    private function userTwoFactorReset(string $username): int
    {
        $app = $this->app();
        $changed = $app->db->run(
            'UPDATE users SET totp_enabled = 0, totp_secret_enc = NULL, totp_last_step = 0 WHERE username = :u',
            ['u' => $username]
        )->rowCount();
        if ($changed === 0) {
            throw new RuntimeException("Uživatel „{$username}“ neexistuje.");
        }
        $app->logger->warning('cli.2fa_reset', ['user' => $username]);
        $this->line('✔ 2FA vypnuto. Po přihlášení ho znovu zapni v Nastavení.');

        return 0;
    }

    /** @param array<string, string> $options */
    private function sync(array $options): int
    {
        $app = $this->app();
        $params = [];
        $sql = "SELECT id, handle FROM accounts WHERE integration = 'fanvue' AND credentials_enc IS NOT NULL AND status != 'banned'";
        if (isset($options['account'])) {
            $sql .= ' AND id = :id';
            $params['id'] = (int) $options['account'];
        }
        $accounts = $app->db->all($sql, $params);
        if ($accounts === []) {
            $this->line('• Žádný připojený Fanvue účet.');

            return 0;
        }
        $sync = Services::fanvueSync($app);
        $failures = 0;
        foreach ($accounts as $account) {
            try {
                $result = $sync->sync((int) $account['id']);
                $this->line("✔ @{$account['handle']}: {$result['imported']} plateb ({$result['days']} dní)");
            } catch (Throwable $e) {
                // Chyba jednoho účtu (i nečekaná) nesmí zastavit synchronizaci ostatních; FanvueSync ji už zalogoval.
                $failures++;
                $this->line("✘ @{$account['handle']}: " . $e->getMessage());
            }
        }

        return $failures > 0 ? 1 : 0;
    }

    private function ratesRefresh(): int
    {
        $rates = Services::exchangeRates($this->app());
        foreach (['USD', 'EUR', 'GBP'] as $currency) {
            $this->line("✔ {$currency}: " . $rates->refresh($currency) . ' kurzů');
        }

        return 0;
    }

    private function cleanup(): int
    {
        $app = $this->app();
        $attempts = $app->throttle->purgeOlderThanDays(30);
        $files = 0;
        foreach (glob($app->storagePath('tmp') . '/import-*.csv') ?: [] as $file) {
            if (filemtime($file) < time() - 86400 && unlink($file)) {
                $files++;
            }
        }
        $runs = $app->db->run("DELETE FROM sync_runs WHERE started_at < :d", ['d' => gmdate('Y-m-d H:i:s', time() - 90 * 86400)])->rowCount();
        $this->line("✔ Smazáno: {$attempts} pokusů o přihlášení, {$files} dočasných souborů, {$runs} starých záznamů synchronizace.");

        return 0;
    }

    private function modelImport(string $file): int
    {
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            throw new RuntimeException('Zadej cestu k souboru: php bin/console model:import models/tia-tempest.json');
        }
        $app = $this->app();
        try {
            $result = Services::modelImporter($app)->importJson((string) file_get_contents($file));
        } catch (ModelImportException $e) {
            $this->line('✘ Nic se neuložilo, chyby v souboru:');
            foreach ($e->errors as $error) {
                $this->line('  • ' . $error);
            }

            return 1;
        }
        $app->logger->info('model.imported', ['id' => $result['model_id'], 'name' => $result['name'], 'via' => 'cli']);
        $c = $result['counts'];
        $this->line("✔ Modelka {$result['name']} (ID {$result['model_id']}): {$c['prompts']} promptů, {$c['accounts']} účtů, {$c['links']} odkazů, {$c['tools']} AI nástrojů.");
        foreach ($result['warnings'] as $warning) {
            $this->line('  ! ' . $warning);
        }

        return 0;
    }

    private function help(): int
    {
        $this->line(self::HELP);

        return 0;
    }

    private function app(): App
    {
        return new App(Config::load($this->root . '/config/config.php'), $this->root);
    }

    private function writeConfig(string $file, string $baseUrl, string $adminPath, bool $https): void
    {
        $template = file_get_contents($this->root . '/config/config.example.php');
        if ($template === false) {
            throw new RuntimeException('Chybí config/config.example.php.');
        }
        $replacements = [
            "'https://studio.example.com'" => var_export($baseUrl, true),
            "'admin_path' => '/admin'" => "'admin_path' => " . var_export($adminPath, true),
            "'base64:CHANGE_ME'" => var_export(Crypto::generateKey(), true),
        ];
        if (!$https) {
            $replacements["'force_https' => true"] = "'force_https' => false";
            $replacements["'hsts' => true"] = "'hsts' => false";
        }
        foreach ($replacements as $search => $replace) {
            if (!str_contains($template, $search)) {
                throw new RuntimeException("Šablona konfigurace neobsahuje {$search}.");
            }
            $template = str_replace($search, $replace, $template);
        }
        $template = (string) preg_replace('#^// Zkopíruj.*\n// Tento soubor.*\n#m', "// Vygenerováno příkazem install. NECOMMITOVAT.\n", $template);
        if (file_put_contents($file, $template, LOCK_EX) === false) {
            throw new RuntimeException("Nelze zapsat {$file}.");
        }
        chmod($file, 0600);
    }

    private function askNewPassword(): string
    {
        $env = getenv('AMS_PASSWORD');
        if (is_string($env) && $env !== '') {
            $password = $env;
        } else {
            $password = $this->askHidden('Heslo (min. ' . Passwords::MIN_LENGTH . ' znaků)');
            if ($password !== $this->askHidden('Heslo znovu')) {
                throw new RuntimeException('Hesla se neshodují.');
            }
        }
        $problem = Passwords::validate($password);
        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        return $password;
    }

    private function ask(string $question, string $default = ''): string
    {
        $this->write($question . ($default !== '' ? " [{$default}]" : '') . ': ');
        $answer = trim((string) fgets(STDIN));

        return $answer === '' ? $default : $answer;
    }

    private function askHidden(string $question): string
    {
        $this->write($question . ': ');
        $interactive = function_exists('posix_isatty') && posix_isatty(STDIN) && function_exists('shell_exec');
        if ($interactive) {
            shell_exec('stty -echo');
        }
        try {
            $answer = rtrim((string) fgets(STDIN), "\r\n");
        } finally {
            if ($interactive) {
                shell_exec('stty echo');
                $this->write("\n");
            }
        }

        return $answer;
    }

    /**
     * @param list<string> $args
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function parseArgs(array $args): array
    {
        $positional = [];
        $options = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) === 1) {
                $options[$m[1]] = $m[2];
            } else {
                $positional[] = $arg;
            }
        }

        return [$positional, $options];
    }

    private function fail(string $message): int
    {
        fwrite(STDERR, '✘ ' . $message . "\n");

        return 1;
    }

    private function line(string $text): void
    {
        $this->write($text . "\n");
    }

    private function write(string $text): void
    {
        fwrite(STDOUT, $text);
    }
}
