<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\Database;
use App\Form\AccountForm;
use App\Form\FormInput;
use App\Form\LinkForm;
use App\Form\ModelForm;
use App\Form\PromptForm;
use App\Kernel\UrlGenerator;
use App\Support\Clock;
use App\Support\Validator;
use JsonException;

/**
 * Import kompletní modelky (profil, AI nástroje, prompty, účty, sledovací odkazy) ze souboru JSON.
 *
 * Každá položka prochází stejnými formuláři jako v administraci. Import je atomický:
 * při jakékoli chybě se neuloží nic a vrátí se seznam všech chyb najednou.
 * Formát souboru popisuje models/README.md.
 */
final class ModelImporter
{
    public const FORMAT = 'ai-model-studio/model';
    public const VERSION = 1;
    public const MAX_BYTES = 1_000_000;
    private const MAX_ITEMS = 200;
    private const LANDING_PLACEHOLDER = '{landing_url}';

    private const PROMPT_KEYS = ['kind', 'title', 'prompt', 'negative_prompt', 'seed', 'settings', 'tool', 'is_master', 'rating', 'notes'];
    private const ACCOUNT_KEYS = ['platform', 'handle', 'profile_url', 'status', 'currency', 'fee_percent', 'show_on_page', 'sync_since', 'notes'];
    private const LINK_KEYS = ['label', 'source', 'target_url', 'code', 'is_active', 'is_premium', 'show_on_page', 'sort_order'];
    private const TOOL_KEYS = ['name', 'purpose'];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly Database $db,
        private readonly UrlGenerator $urls,
    ) {
    }

    /**
     * @return array{model_id: int, name: string, counts: array{prompts: int, accounts: int, links: int, tools: int}, warnings: list<string>}
     * @throws ModelImportException se seznamem všech chyb; do databáze se v tom případě nic nezapíše
     */
    public function importJson(string $json): array
    {
        $this->errors = [];
        $this->warnings = [];
        $data = $this->decode($json);

        $modelInput = $this->scalars($data['model'] ?? null, 'Profil modelky', $this->modelKeys());
        $model = null;
        if ($modelInput !== null) {
            [$modelData, $v] = (new ModelForm($this->db, $this->urls->host()))->validate($modelInput, null);
            $this->collect($v, 'Profil modelky');
            $model = $modelData;
        }
        $tools = $this->prepareTools($this->list($data, 'tools'));
        $prompts = $this->preparePrompts($this->list($data, 'prompts'));
        $accounts = $this->prepareAccounts($this->list($data, 'accounts'));
        $links = $this->list($data, 'links');

        if ($model === null || $this->errors !== []) {
            throw new ModelImportException($this->errors);
        }

        $counts = $this->db->transaction(function () use ($model, $tools, $prompts, $accounts, $links): array {
            $now = Clock::nowUtc();
            $modelId = $this->db->insert('models', $model + ['created_at' => $now, 'updated_at' => $now]);
            foreach ($tools as $toolId => $purpose) {
                $this->db->insert('model_tools', ['model_id' => $modelId, 'tool_id' => $toolId, 'purpose' => $purpose]);
            }
            foreach ($prompts as $prompt) {
                $this->db->insert('prompts', $prompt + ['model_id' => $modelId, 'created_at' => $now, 'updated_at' => $now]);
            }
            foreach ($accounts as $account) {
                $this->db->insert('accounts', $account + ['model_id' => $modelId, 'created_at' => $now]);
            }
            // Odkazy se ověřují až s ID nové modelky (formulář kontroluje její existenci a unikátnost kódu).
            $landingUrl = $this->urls->modelPage((string) $model['slug'], $model['page_domain']);
            $linkCount = 0;
            foreach ($links as $index => $item) {
                $label = $this->itemLabel('Odkaz', $index, $item, 'label');
                $input = $this->scalars($item, $label, self::LINK_KEYS);
                if ($input === null) {
                    continue;
                }
                $values = $this->rawValues($item, self::LINK_KEYS);
                $values['target_url'] = str_replace(self::LANDING_PLACEHOLDER, $landingUrl, (string) ($values['target_url'] ?? ''));
                $values['model_id'] = $modelId;
                [$linkData, $v] = (new LinkForm($this->db))->validate(FormInput::fromScalars($values), null);
                if ($this->collect($v, $label)) {
                    $this->db->insert('links', $linkData + ['created_at' => $now]);
                    $linkCount++;
                }
            }
            if ($this->errors !== []) {
                throw new ModelImportException($this->errors);
            }

            return ['model_id' => $modelId, 'links' => $linkCount];
        });

        return [
            'model_id' => $counts['model_id'],
            'name' => (string) $model['name'],
            'counts' => ['prompts' => count($prompts), 'accounts' => count($accounts), 'links' => $counts['links'], 'tools' => count($tools)],
            'warnings' => $this->warnings,
        ];
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new ModelImportException(['Soubor je větší než 1 MB.']);
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ModelImportException(['Soubor není platný JSON: ' . $e->getMessage() . '.']);
        }
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new ModelImportException(['Soubor nemá formát „' . self::FORMAT . '“ — vzor najdeš v models/README.md.']);
        }
        if (($data['version'] ?? null) !== self::VERSION) {
            throw new ModelImportException(['Nepodporovaná verze souboru (podporovaná je ' . self::VERSION . ').']);
        }
        foreach (array_keys($data) as $key) {
            if (!in_array($key, ['format', 'version', 'model', 'tools', 'prompts', 'accounts', 'links'], true) && !str_starts_with((string) $key, '_')) {
                $this->warnings[] = "Neznámá sekce „{$key}“ — ignorována.";
            }
        }

        return $data;
    }

    /** @return array<int, int|string|null> tool_id => účel */
    private function prepareTools(array $items): array
    {
        $tools = [];
        foreach ($items as $index => $item) {
            $label = $this->itemLabel('AI nástroj', $index, $item, 'name');
            if ($this->scalars($item, $label, self::TOOL_KEYS) === null) {
                continue;
            }
            $values = $this->rawValues($item, self::TOOL_KEYS);
            $toolId = $this->toolId(trim((string) ($values['name'] ?? '')), $label);
            if ($toolId === null) {
                continue;
            }
            $purpose = trim((string) ($values['purpose'] ?? ''));
            if (mb_strlen($purpose) > 255) {
                $this->errors[] = "{$label}: účel max. 255 znaků.";
            }
            $tools[$toolId] = $purpose === '' ? null : $purpose;
        }

        return $tools;
    }

    /** @return list<array<string, mixed>> */
    private function preparePrompts(array $items): array
    {
        $prompts = [];
        $masters = [];
        foreach ($items as $index => $item) {
            $label = $this->itemLabel('Prompt', $index, $item, 'title');
            if ($this->scalars($item, $label, self::PROMPT_KEYS) === null) {
                continue;
            }
            $values = $this->rawValues($item, self::PROMPT_KEYS);
            $toolName = trim((string) ($values['tool'] ?? ''));
            unset($values['tool']);
            $values['tool_id'] = $toolName === '' ? null : $this->toolId($toolName, $label);
            [$data, $v] = (new PromptForm($this->db))->validate(FormInput::fromScalars($values));
            if (!$this->collect($v, $label)) {
                continue;
            }
            if ($data['is_master'] === 1) {
                if (isset($masters[$data['kind']])) {
                    $this->errors[] = "{$label}: master tohoto typu už je „{$masters[$data['kind']]}“ — master může být jen jeden.";
                }
                $masters[$data['kind']] = $data['title'];
            }
            $prompts[] = $data;
        }

        return $prompts;
    }

    /** @return list<array<string, mixed>> */
    private function prepareAccounts(array $items): array
    {
        $accounts = [];
        foreach ($items as $index => $item) {
            $label = $this->itemLabel('Účet', $index, $item, 'platform');
            if ($this->scalars($item, $label, self::ACCOUNT_KEYS) === null) {
                continue;
            }
            $values = $this->rawValues($item, self::ACCOUNT_KEYS);
            $platformName = trim((string) ($values['platform'] ?? ''));
            unset($values['platform']);
            $platformId = $this->db->scalar('SELECT id FROM platforms WHERE name = :n', ['n' => $platformName]);
            if ($platformId === null) {
                $this->warnings[] = "{$label}: platforma „{$platformName}“ v systému není — účet přeskočen.";
                continue;
            }
            $values['platform_id'] = (int) $platformId;
            [$data, $v] = (new AccountForm($this->db))->validate(FormInput::fromScalars($values));
            if ($this->collect($v, $label)) {
                $accounts[] = $data;
            }
        }

        return $accounts;
    }

    private function toolId(string $name, string $label): ?int
    {
        $id = $this->db->scalar('SELECT id FROM ai_tools WHERE name = :n', ['n' => $name]);
        if ($id === null) {
            $this->warnings[] = "{$label}: AI nástroj „{$name}“ v systému není — vazba vynechána.";

            return null;
        }

        return (int) $id;
    }

    /**
     * Ověří, že položka je objekt s jednoduchými hodnotami (text lze zapsat i jako pole řádků),
     * a upozorní na neznámá pole (překlepy).
     *
     * @param list<string> $allowed
     */
    private function scalars(mixed $item, string $label, array $allowed): ?FormInput
    {
        if (!is_array($item) || array_is_list($item) && $item !== []) {
            $this->errors[] = "{$label}: očekávám objekt { … }.";

            return null;
        }
        foreach ($item as $key => $value) {
            if (str_starts_with((string) $key, '_')) {
                continue; // „_poznámka“ a podobná pole slouží jako komentáře v souboru
            }
            if (!in_array($key, $allowed, true)) {
                $this->warnings[] = "{$label}: neznámé pole „{$key}“ — ignorováno.";
                continue;
            }
            if (is_array($value) && !$this->isLineList($value)) {
                $this->errors[] = "{$label}: pole „{$key}“ musí být text, číslo, true/false nebo seznam řádků.";

                return null;
            }
        }

        return FormInput::fromScalars($this->rawValues($item, $allowed));
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $allowed
     * @return array<string, bool|int|float|string|null>
     */
    private function rawValues(array $item, array $allowed): array
    {
        $values = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $item)) {
                continue;
            }
            $value = $item[$key];
            $values[$key] = is_array($value) ? implode("\n", $value) : $value;
        }

        return $values;
    }

    private function isLineList(array $value): bool
    {
        return array_is_list($value) && array_filter($value, static fn (mixed $line): bool => !is_string($line)) === [];
    }

    /** @return list<mixed> */
    private function list(array $data, string $key): array
    {
        $items = $data[$key] ?? [];
        if (!is_array($items) || !array_is_list($items)) {
            $this->errors[] = "„{$key}“ musí být seznam [ … ].";

            return [];
        }
        if (count($items) > self::MAX_ITEMS) {
            $this->errors[] = "„{$key}“: max. " . self::MAX_ITEMS . ' položek.';

            return [];
        }

        return $items;
    }

    /** @return list<string> */
    private function modelKeys(): array
    {
        return ['name', 'slug', 'status', 'persona_age', 'page_lang', 'page_published', 'page_domain', ...array_keys(ModelForm::TEXT_FIELDS)];
    }

    private function itemLabel(string $type, int $index, mixed $item, string $nameKey): string
    {
        $name = is_array($item) && is_string($item[$nameKey] ?? null) ? mb_substr(trim($item[$nameKey]), 0, 60) : '';

        return $type . ' ' . ($index + 1) . ($name !== '' ? " („{$name}“)" : '');
    }

    /** Přenese chyby formuláře do seznamu; vrací true, pokud je položka v pořádku. */
    private function collect(Validator $v, string $label): bool
    {
        foreach ($v->errors() as $message) {
            $this->errors[] = "{$label}: {$message}";
        }

        return !$v->fails();
    }
}
