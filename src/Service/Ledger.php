<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\Database;
use App\Support\Clock;
use App\Support\Labels;
use App\Support\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Zápis příjmů (transakcí) a nákladů včetně přepočtu na CZK kurzem ČNB ke dni.
 * Příprava řádku (síťové volání kvůli kurzu) je oddělená od zápisu, aby zápis mohl běžet v DB transakci.
 */
final class Ledger
{
    public function __construct(
        private readonly Database $db,
        private readonly ExchangeRates $rates,
    ) {
    }

    /**
     * @param array{
     *   occurred_at: DateTimeImmutable, type: string, gross_minor: int, net_minor: int, currency: string,
     *   source: string, fan_id?: int|null, dedupe_key?: string|null, note?: string|null, fx_rate?: float|null
     * } $input
     * @return array<string, mixed> Řádek připravený pro insertTransaction().
     * @throws ExchangeRateUnavailable
     */
    public function prepareTransaction(int $accountId, array $input): array
    {
        if (!in_array($input['type'], Labels::keys('tx_type'), true)) {
            throw new InvalidArgumentException("Neznámý typ transakce {$input['type']}.");
        }
        $currency = Money::assertCurrency($input['currency']);
        $occurredOn = Clock::localDateOf($input['occurred_at']);
        $rate = $input['fx_rate'] ?? null;
        if ($rate === null || $rate <= 0) {
            $rate = $this->rates->czkPerUnit($currency, $occurredOn);
        }

        return [
            'account_id' => $accountId,
            'fan_id' => $input['fan_id'] ?? null,
            'occurred_at' => Clock::toUtcString($input['occurred_at']),
            'occurred_on' => $occurredOn,
            'type' => $input['type'],
            'gross_minor' => $input['gross_minor'],
            'net_minor' => $input['net_minor'],
            'currency' => $currency,
            'fx_rate' => $rate,
            'gross_czk_minor' => Money::convert($input['gross_minor'], $rate),
            'net_czk_minor' => Money::convert($input['net_minor'], $rate),
            'source' => $input['source'],
            'dedupe_key' => $input['dedupe_key'] ?? null,
            'note' => $input['note'] ?? null,
            'created_at' => Clock::nowUtc(),
        ];
    }

    /**
     * @param array<string, mixed> $row z prepareTransaction()
     * @return int|null ID nové transakce, nebo null pokud jde o duplikát (stejný dedupe_key).
     */
    public function insertTransaction(array $row): ?int
    {
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO transactions (%s) VALUES (%s) ON CONFLICT (dedupe_key) DO NOTHING',
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        );
        $statement = $this->db->run($sql, $row);

        return $statement->rowCount() === 1 ? (int) $this->db->pdo()->lastInsertId() : null;
    }

    /** Vloží fanouška, nebo aktualizuje jeho jméno. Vrací ID. */
    public function upsertFan(int $accountId, string $externalId, ?string $handle, ?string $displayName, bool $isTopSpender = false): int
    {
        $this->db->run(
            'INSERT INTO fans (account_id, external_id, handle, display_name, is_top_spender, first_seen_at)
             VALUES (:a, :e, :h, :d, :t, :now)
             ON CONFLICT (account_id, external_id) DO UPDATE SET
                handle = COALESCE(excluded.handle, fans.handle),
                display_name = COALESCE(excluded.display_name, fans.display_name),
                is_top_spender = MAX(fans.is_top_spender, excluded.is_top_spender)',
            [
                'a' => $accountId,
                'e' => mb_substr($externalId, 0, 190),
                'h' => $handle !== null ? mb_substr($handle, 0, 190) : null,
                'd' => $displayName !== null ? mb_substr($displayName, 0, 190) : null,
                't' => $isTopSpender ? 1 : 0,
                'now' => Clock::nowUtc(),
            ]
        );

        return (int) $this->db->scalar(
            'SELECT id FROM fans WHERE account_id = :a AND external_id = :e',
            ['a' => $accountId, 'e' => mb_substr($externalId, 0, 190)]
        );
    }

    /**
     * @param array{model_id: int|null, tool_id: int|null, category: string, incurred_on: string, amount_minor: int,
     *              currency: string, quantity: int|null, note: string|null, fx_rate?: float|null} $input
     * @return array<string, mixed>
     * @throws ExchangeRateUnavailable
     */
    public function prepareCost(array $input): array
    {
        $currency = Money::assertCurrency($input['currency']);
        $rate = $input['fx_rate'] ?? null;
        if ($rate === null || $rate <= 0) {
            $rate = $this->rates->czkPerUnit($currency, $input['incurred_on']);
        }

        return [
            'model_id' => $input['model_id'],
            'tool_id' => $input['tool_id'],
            'category' => $input['category'],
            'incurred_on' => $input['incurred_on'],
            'amount_minor' => $input['amount_minor'],
            'currency' => $currency,
            'fx_rate' => $rate,
            'amount_czk_minor' => Money::convert($input['amount_minor'], $rate),
            'quantity' => $input['quantity'],
            'note' => $input['note'],
        ];
    }
}
