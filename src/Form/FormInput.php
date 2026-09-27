<?php

declare(strict_types=1);

namespace App\Form;

/**
 * Vstup formuláře (POST z administrace nebo část importovaného souboru) se stejnou sémantikou
 * pro všechny zdroje: input() ořízne mezery, rawInput() zachová formátování, checkbox() = zaškrtnuto.
 */
final class FormInput
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    /**
     * Z hodnot typu JSON: true → zaškrtnuto, false/null → nevyplněno, čísla → text.
     *
     * @param array<string, bool|int|float|string|null> $values
     */
    public static function fromScalars(array $values): self
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            $normalized[$key] = match (true) {
                $value === true => '1',
                is_float($value) => rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.'),
                default => (string) $value,
            };
        }

        return new self($normalized);
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? $default;

        return is_string($value) ? trim($value) : $default;
    }

    /** Surová hodnota bez trim (pro prompty, kde záleží na formátování). */
    public function rawInput(string $key): string
    {
        $value = $this->values[$key] ?? '';

        return is_string($value) ? str_replace("\r\n", "\n", $value) : '';
    }

    public function checkbox(string $key): bool
    {
        return isset($this->values[$key]) && $this->values[$key] !== '0';
    }
}
