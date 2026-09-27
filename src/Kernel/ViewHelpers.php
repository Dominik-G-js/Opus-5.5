<?php

declare(strict_types=1);

namespace App\Kernel;

use App\Security\Csrf;
use App\Support\Clock;
use App\Support\Labels;
use App\Support\Money;

/**
 * Pomocné funkce dostupné v šablonách jako $v. Veškerý výstup do HTML jde přes e().
 */
final class ViewHelpers
{
    /** Zvýšit při změně CSS/JS kvůli cache prohlížeče. */
    private const ASSET_VERSION = '5';

    /** @var array<string, mixed> */
    private array $old = [];
    /** @var array<string, string> */
    private array $errors = [];
    /** @var list<array{type: string, message: string}>|null */
    private ?array $flashes = null;
    private string $currentPath = '/';
    /** @var array<string, mixed>|null */
    private ?array $user = null;

    public function __construct(
        public readonly UrlGenerator $urls,
        private readonly Csrf $csrf,
        private readonly Session $session,
        public readonly string $appName,
    ) {
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     * @param array<string, mixed>|null $user
     */
    public function setRequestState(string $path, array $old, array $errors, ?array $user): void
    {
        $this->currentPath = $path;
        $this->old = $old;
        $this->errors = $errors;
        $this->user = $user;
    }

    public function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** @param array<string, scalar> $query */
    public function url(string $path = '', array $query = []): string
    {
        return $this->e($this->urls->admin($path, $query));
    }

    public function asset(string $path): string
    {
        return $this->e($this->urls->public('/assets/' . ltrim($path, '/')) . '?v=' . self::ASSET_VERSION);
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->e($this->csrf->token()) . '">';
    }

    public function money(?int $minor, string $currency = 'CZK', bool $withCents = false): string
    {
        return $this->e($minor === null ? '—' : Money::format($minor, $currency, $withCents));
    }

    public function moneyInput(?int $minor): string
    {
        return $this->e(Money::toInput($minor));
    }

    public function dateTime(?string $utc): string
    {
        return $this->e(Clock::formatLocal($utc));
    }

    public function date(?string $date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $this->e($parsed === false ? $date : $parsed->format('j. n. Y'));
    }

    public function label(string $group, ?string $key): string
    {
        return $this->e(Labels::get($group, $key));
    }

    /** Hodnota pole: odeslaná (po chybě) → výchozí z DB. */
    public function old(string $field, mixed $default = ''): string
    {
        $value = array_key_exists($field, $this->old) ? $this->old[$field] : $default;

        return $this->e(is_scalar($value) || $value === null ? $value : '');
    }

    public function oldRaw(string $field, mixed $default = ''): mixed
    {
        return array_key_exists($field, $this->old) ? $this->old[$field] : $default;
    }

    public function hasOld(): bool
    {
        return $this->old !== [];
    }

    public function error(string $field): string
    {
        return isset($this->errors[$field])
            ? '<p class="field-error">' . $this->e($this->errors[$field]) . '</p>'
            : '';
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** Souhrn všech chyb formuláře nahoře — žádná hláška tak nezůstane skrytá (ani u polí bez vlastního výpisu). */
    public function errorSummary(): string
    {
        if ($this->errors === []) {
            return '';
        }
        $items = '';
        foreach ($this->errors as $field => $message) {
            $items .= '<li><a href="#' . $this->e($field) . '">' . $this->e($message) . '</a></li>';
        }

        return '<div class="error-summary" role="alert"><strong>Oprav prosím:</strong><ul>' . $items . '</ul></div>';
    }

    /**
     * Hlášky se ze session vyberou až při vykreslení — požadavek, který jen přesměruje, je nespotřebuje.
     *
     * @return list<array{type: string, message: string}>
     */
    public function flashes(): array
    {
        return $this->flashes ??= $this->session->takeFlashes();
    }

    public function isActive(string $prefix): bool
    {
        if ($prefix === '') {
            return $this->currentPath === $this->urls->adminPath() || $this->currentPath === $this->urls->adminPath() . '/';
        }

        return str_starts_with($this->currentPath, $this->urls->adminPath() . $prefix);
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        return $this->user;
    }

    /**
     * Vykreslí <option> prvky; $options je [hodnota => popisek].
     *
     * @param array<string|int, string> $options
     */
    public function options(array $options, mixed $selected, bool $withEmpty = false, string $emptyLabel = '—'): string
    {
        $html = $withEmpty ? '<option value="">' . $this->e($emptyLabel) . '</option>' : '';
        foreach ($options as $value => $label) {
            $isSelected = (string) $value === (string) $selected ? ' selected' : '';
            $html .= '<option value="' . $this->e($value) . '"' . $isSelected . '>' . $this->e($label) . '</option>';
        }

        return $html;
    }

    public function checked(bool $condition): string
    {
        return $condition ? ' checked' : '';
    }

    public function nl2br(?string $text): string
    {
        return nl2br($this->e($text), false);
    }
}
