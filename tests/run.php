<?php

declare(strict_types=1);

/**
 * Minimalistický test runner bez závislostí: php tests/run.php
 * Testy jsou v tests/*Test.php a registrují se funkcí test().
 */

require dirname(__DIR__) . '/autoload.php';

/** @var array<string, callable> $GLOBALS['__tests'] */
$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][$name] = $fn;
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionError(($message !== '' ? $message . ': ' : '') . 'očekáváno ' . var_export($expected, true) . ', dostáno ' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $message = 'podmínka neplatí'): void
{
    if (!$condition) {
        throw new AssertionError($message);
    }
}

/** @param class-string<Throwable> $class */
function assertThrows(string $class, callable $fn, string $message = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new AssertionError("Očekávána výjimka {$class}, vyhozena " . $e::class . ': ' . $e->getMessage());
    }
    throw new AssertionError($message !== '' ? $message : "Očekávána výjimka {$class}, nic nevyhozeno.");
}

function testDatabase(): App\Database\Database
{
    $db = new App\Database\Database(':memory:');
    (new App\Database\Migrator($db, dirname(__DIR__) . '/migrations'))->migrate();

    return $db;
}

require __DIR__ . '/FakeHttpClient.php';

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
$passed = 0;
$failed = 0;
foreach ($GLOBALS['__tests'] as $name => $fn) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    try {
        $fn();
        $passed++;
        echo "  ✔ {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✘ {$name}\n      " . $e::class . ': ' . $e->getMessage() . "\n      " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}
echo "\n{$passed} OK, {$failed} selhalo\n";
exit($failed > 0 ? 1 : 0);
