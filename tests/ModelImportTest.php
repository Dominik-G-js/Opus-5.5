<?php

declare(strict_types=1);

use App\Form\FormInput;
use App\Kernel\UrlGenerator;
use App\Service\ModelImporter;
use App\Service\ModelImportException;

function importer(App\Database\Database $db): ModelImporter
{
    return new ModelImporter($db, new UrlGenerator('https://studio.example.com', '/admin'));
}

/** @param array<string, mixed> $extra */
function modelFile(array $extra = []): string
{
    return json_encode($extra + [
        'format' => ModelImporter::FORMAT,
        'version' => ModelImporter::VERSION,
        'model' => ['name' => 'Test Persona', 'slug' => 'test-persona', 'status' => 'concept', 'persona_age' => 24, 'page_lang' => 'en'],
    ], JSON_THROW_ON_ERROR);
}

/** @return list<string> */
function importErrors(callable $fn): array
{
    try {
        $fn();
    } catch (ModelImportException $e) {
        return $e->errors;
    }
    throw new AssertionError('očekávána ModelImportException');
}

test('FormInput: hodnoty z JSON mají stejnou sémantiku jako formulář', function (): void {
    $in = FormInput::fromScalars(['a' => ' x ', 'yes' => true, 'no' => false, 'n' => null, 'age' => 25, 'fee' => 15.5, 'zero' => '0']);
    assertSame('x', $in->input('a'));
    assertSame(" x ", $in->rawInput('a'));
    assertTrue($in->checkbox('yes'));
    assertTrue(!$in->checkbox('no') && !$in->checkbox('n') && !$in->checkbox('zero'));
    assertSame('25', $in->input('age'));
    assertSame('15.5', $in->input('fee'));
});

test('ModelImporter: soubor Tia Tempest z repozitáře se naimportuje celý', function (): void {
    $db = testDatabase();
    $result = importer($db)->importJson((string) file_get_contents(dirname(__DIR__) . '/models/tia-tempest.json'));
    assertSame(['prompts' => 24, 'accounts' => 5, 'links' => 5, 'tools' => 6], $result['counts']);
    assertSame([], $result['warnings']);
    $model = $db->one('SELECT * FROM models WHERE id = :id', ['id' => $result['model_id']]);
    assertSame('tia-tempest', $model['slug']);
    assertSame(25, $model['persona_age']);
    assertSame(0, $model['page_published']);
    assertTrue(str_contains((string) $model['public_bio'], "\n"), 'víceřádkový text z pole řádků');
    assertSame(1, $db->scalar("SELECT COUNT(*) FROM prompts WHERE kind = 'character_base' AND is_master = 1"));
    assertSame(
        'https://studio.example.com/m/tia-tempest',
        $db->scalar("SELECT target_url FROM links WHERE code = 'tia-tiktok'"),
        'zástupný {landing_url} nahrazen adresou landing page'
    );
    assertSame(0, $db->scalar('SELECT COUNT(*) FROM links WHERE is_active = 1'), 'odkazy vypnuté, dokud nejsou účty založené');
});

test('ModelImporter: chyby se sesbírají všechny a nic se neuloží', function (): void {
    $db = testDatabase();
    $errors = importErrors(fn () => importer($db)->importJson(modelFile([
        'model' => ['name' => '', 'slug' => 'Bad Slug', 'status' => 'hacked', 'persona_age' => 17, 'page_lang' => 'en'],
        'prompts' => [['kind' => 'image', 'title' => '', 'prompt' => 'x'], ['kind' => 'nope', 'title' => 'T', 'prompt' => 'y']],
        'accounts' => [['platform' => 'Fanvue', 'handle' => '', 'status' => 'planned', 'profile_url' => 'javascript:alert(1)']],
    ])));
    $text = implode("\n", $errors);
    foreach (['Jméno: povinné pole', 'URL slug', 'Stav: neplatná hodnota', 'Věk postavy', 'Prompt 1: Název', 'Prompt 2 („T“): Typ', 'Účet 1 („Fanvue“): Uživatelské jméno', 'Odkaz na profil'] as $expected) {
        assertTrue(str_contains($text, $expected), "chybí chyba „{$expected}“ v:\n{$text}");
    }
    assertSame(0, $db->scalar('SELECT COUNT(*) FROM models'));
    assertSame(0, $db->scalar('SELECT COUNT(*) FROM prompts'));
});

test('ModelImporter: chyba v odkazu vrátí zpět i už vloženou modelku', function (): void {
    $db = testDatabase();
    $errors = importErrors(fn () => importer($db)->importJson(modelFile([
        'prompts' => [['kind' => 'image', 'title' => 'OK', 'prompt' => 'x']],
        'links' => [
            ['label' => 'A', 'source' => 'x', 'target_url' => 'https://x.com/a', 'code' => 'same-code'],
            ['label' => 'B', 'source' => 'x', 'target_url' => 'https://x.com/b', 'code' => 'same-code'],
        ],
    ])));
    assertTrue(str_contains(implode(' ', $errors), 'Odkaz 2 („B“): Kód už používá'), implode(' ', $errors));
    assertSame(0, $db->scalar('SELECT COUNT(*) FROM models'), 'transakce vrácena');
    assertSame(0, $db->scalar('SELECT COUNT(*) FROM links'));
});

test('ModelImporter: neplatný soubor, cizí formát, dva mastery, neznámé položky', function (): void {
    $db = testDatabase();
    assertTrue(str_contains(importErrors(fn () => importer($db)->importJson('{nope'))[0], 'není platný JSON'));
    assertTrue(str_contains(importErrors(fn () => importer($db)->importJson('{"format":"jiny","version":1}'))[0], 'nemá formát'));
    assertTrue(str_contains(importErrors(fn () => importer($db)->importJson(modelFile(['version' => 2])))[0], 'Nepodporovaná verze'));
    assertTrue(str_contains(importErrors(fn () => importer($db)->importJson(str_repeat(' ', ModelImporter::MAX_BYTES + 1)))[0], '1 MB'));
    $errors = importErrors(fn () => importer($db)->importJson(modelFile(['prompts' => [
        ['kind' => 'negative', 'title' => 'N1', 'prompt' => 'a', 'is_master' => true],
        ['kind' => 'negative', 'title' => 'N2', 'prompt' => 'b', 'is_master' => true],
        ['kind' => 'image', 'title' => 'Nested', 'prompt' => ['ok'], 'settings' => ['bad' => 1]],
    ]])));
    assertTrue(str_contains(implode(' ', $errors), 'master může být jen jeden'));
    assertTrue(str_contains(implode(' ', $errors), 'musí být text'));

    $result = importer($db)->importJson(modelFile([
        '_komentar' => 'ignorováno bez varování',
        'extra' => 1,
        'tools' => [['name' => 'Neexistující nástroj']],
        'accounts' => [['platform' => 'MySpace', 'handle' => 'x', 'status' => 'planned']],
        'prompts' => [['kind' => 'image', 'title' => 'P', 'prompt' => 'x', 'tool' => 'Neexistující nástroj', 'titel' => 'překlep']],
    ]));
    $warnings = implode("\n", $result['warnings']);
    foreach (['Neznámá sekce „extra“', 'nástroj „Neexistující nástroj“', 'platforma „MySpace“', 'neznámé pole „titel“'] as $expected) {
        assertTrue(str_contains($warnings, $expected), "chybí varování „{$expected}“ v:\n{$warnings}");
    }
    assertTrue(!str_contains($warnings, '_komentar'));
    assertSame(['prompts' => 1, 'accounts' => 0, 'links' => 0, 'tools' => 0], $result['counts']);
});
