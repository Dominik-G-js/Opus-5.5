<?php

declare(strict_types=1);

namespace App\Integration\Fanvue;

use App\Database\Database;
use App\Security\Crypto;
use App\Service\Ledger;
use App\Support\Clock;
use App\Support\Logger;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Stahuje platby a statistiky předplatitelů z Fanvue.
 *
 * Fanvue u plateb nevrací ID transakce, proto se okno [od, do) vždy celé nahradí:
 * smažou se fanvue transakce účtu v okně a vloží se znovu. Synchronizace je tak idempotentní
 * a zachytí i dodatečné opravy (refundace) v posledních 35 dnech.
 */
final class FanvueSync
{
    private const LOOKBACK_DAYS = 35;
    private const FIRST_SYNC_DEFAULT_DAYS = 90;
    private const CHUNK_DAYS = 31;

    private const SOURCE_MAP = [
        'subscription' => 'subscription',
        'renewal' => 'renewal',
        'tip' => 'tip',
        'message' => 'message',
        'post' => 'post',
        'mediaLink' => 'media_link',
        'referral' => 'referral',
        'affiliate' => 'affiliate',
        'giveaway' => 'giveaway',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Crypto $crypto,
        private readonly FanvueClient $client,
        private readonly Ledger $ledger,
        private readonly Logger $logger,
    ) {
    }

    /** Uloží tokeny po připojení účtu a doplní identitu tvůrce. */
    public function connect(int $accountId, TokenSet $tokens): void
    {
        $me = $this->client->currentUser($tokens->accessToken);
        if (($me['isCreator'] ?? null) === false) {
            throw new FanvueException('Připojený Fanvue účet není tvůrce (creator).');
        }
        $this->db->update('accounts', [
            'integration' => 'fanvue',
            'credentials_enc' => $this->crypto->encryptJson($tokens->toArray()),
            'external_uuid' => is_string($me['uuid'] ?? null) ? $me['uuid'] : null,
            'last_sync_error' => null,
        ], ['id' => $accountId]);
        if (is_string($me['handle'] ?? null) && $me['handle'] !== '') {
            $this->db->run(
                "UPDATE accounts SET handle = :h WHERE id = :id AND (handle = '' OR handle IS NULL)",
                ['h' => $me['handle'], 'id' => $accountId]
            );
        }
    }

    /** @return array{imported: int, days: int} */
    public function sync(int $accountId, ?DateTimeImmutable $now = null): array
    {
        $account = $this->db->one('SELECT * FROM accounts WHERE id = :id', ['id' => $accountId]);
        if ($account === null || $account['integration'] !== 'fanvue' || empty($account['credentials_enc'])) {
            throw new FanvueException('Účet není připojený k Fanvue.');
        }
        $utc = new DateTimeZone('UTC');
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($utc);
        $runId = $this->db->insert('sync_runs', [
            'account_id' => $accountId,
            'started_at' => Clock::toUtcString($now),
            'status' => 'running',
        ]);

        try {
            $tokens = $this->freshTokens($accountId, TokenSet::fromArray($this->crypto->decryptJson((string) $account['credentials_enc'])));
            [$start, $end] = $this->window($account, $now);

            $imported = 0;
            for ($chunkStart = $start; $chunkStart < $end; $chunkStart = $chunkEnd) {
                $chunkEnd = min($end, $chunkStart->add(new DateInterval('P' . self::CHUNK_DAYS . 'D')));
                $imported += $this->syncEarningsChunk($accountId, (string) $account['currency'], $tokens, $chunkStart, $chunkEnd);
            }
            $this->syncSubscriberStats($accountId, $tokens, $start, $end);

            $this->db->update('accounts', ['last_synced_at' => Clock::toUtcString($now), 'last_sync_error' => null], ['id' => $accountId]);
            $this->finishRun($runId, 'ok', $imported, null);
            $days = (int) $start->diff($end)->days;
            $this->logger->info('fanvue.sync', ['account' => $accountId, 'imported' => $imported, 'days' => $days]);

            return ['imported' => $imported, 'days' => $days];
        } catch (Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 500);
            $this->db->update('accounts', ['last_sync_error' => $message], ['id' => $accountId]);
            $this->finishRun($runId, 'error', 0, $message);
            $this->logger->error('fanvue.sync_failed', ['account' => $accountId, 'error' => $message]);
            throw $e;
        }
    }

    private function freshTokens(int $accountId, TokenSet $tokens): TokenSet
    {
        if (!$tokens->isExpired()) {
            return $tokens;
        }
        $fresh = $this->client->refresh($tokens);
        // Rotovaný refresh token uložit okamžitě — starý už nemusí platit.
        $this->db->update('accounts', ['credentials_enc' => $this->crypto->encryptJson($fresh->toArray())], ['id' => $accountId]);

        return $fresh;
    }

    /**
     * @param array<string, mixed> $account
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function window(array $account, DateTimeImmutable $now): array
    {
        $utc = new DateTimeZone('UTC');
        $end = $now->setTime(0, 0)->modify('+1 day');
        if (!empty($account['last_synced_at'])) {
            $start = (new DateTimeImmutable((string) $account['last_synced_at'], $utc))->setTime(0, 0)->modify('-' . self::LOOKBACK_DAYS . ' days');
        } elseif (!empty($account['sync_since']) && Clock::isValidDate((string) $account['sync_since'])) {
            $start = new DateTimeImmutable((string) $account['sync_since'] . ' 00:00:00', $utc);
        } else {
            $start = $end->modify('-' . self::FIRST_SYNC_DEFAULT_DAYS . ' days');
        }

        return [min($start, $end), $end];
    }

    private function syncEarningsChunk(int $accountId, string $currency, TokenSet $tokens, DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        $items = $this->client->earnings($tokens->accessToken, $start, $end);

        // 1) Příprava mimo DB transakci (kurzy ČNB se mohou stahovat ze sítě).
        $prepared = [];
        foreach ($items as $item) {
            $moment = $this->parseDate($item['date'] ?? null);
            if ($moment === null || $moment < $start || $moment >= $end) {
                continue;
            }
            $prepared[] = [
                'fan' => is_array($item['user'] ?? null) ? $item['user'] : null,
                'row' => $this->ledger->prepareTransaction($accountId, [
                    'occurred_at' => $moment,
                    'type' => self::SOURCE_MAP[(string) ($item['source'] ?? '')] ?? 'other',
                    'gross_minor' => (int) round((float) ($item['gross'] ?? 0)),
                    'net_minor' => (int) round((float) ($item['net'] ?? 0)),
                    'currency' => $currency,
                    'source' => 'fanvue',
                ]),
            ];
        }

        // 2) Atomická výměna okna.
        return $this->db->transaction(function () use ($accountId, $start, $end, $prepared): int {
            $this->db->run(
                "DELETE FROM transactions WHERE account_id = :a AND source = 'fanvue' AND occurred_at >= :s AND occurred_at < :e",
                ['a' => $accountId, 's' => Clock::toUtcString($start), 'e' => Clock::toUtcString($end)]
            );
            foreach ($prepared as $entry) {
                $row = $entry['row'];
                $fan = $entry['fan'];
                if ($fan !== null && is_string($fan['uuid'] ?? null) && $fan['uuid'] !== '') {
                    $row['fan_id'] = $this->ledger->upsertFan(
                        $accountId,
                        $fan['uuid'],
                        is_string($fan['handle'] ?? null) ? $fan['handle'] : null,
                        is_string($fan['displayName'] ?? null) ? $fan['displayName'] : null,
                        ($fan['isTopSpender'] ?? false) === true,
                    );
                }
                $this->ledger->insertTransaction($row);
            }

            return count($prepared);
        });
    }

    private function syncSubscriberStats(int $accountId, TokenSet $tokens, DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        $rows = $this->client->subscriberStats($tokens->accessToken, $start, $end);
        $this->db->transaction(function () use ($rows, $accountId): void {
            foreach ($rows as $row) {
                $moment = $this->parseDate($row['date'] ?? null);
                if ($moment === null) {
                    continue;
                }
                $this->db->run(
                    'INSERT INTO subscriber_stats_daily (account_id, day, new_subscribers, cancelled)
                     VALUES (:a, :d, :n, :c)
                     ON CONFLICT (account_id, day) DO UPDATE SET new_subscribers = excluded.new_subscribers, cancelled = excluded.cancelled',
                    [
                        'a' => $accountId,
                        'd' => $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'),
                        'n' => (int) ($row['newSubscribersCount'] ?? 0),
                        'c' => (int) ($row['cancelledSubscribersCount'] ?? 0),
                    ]
                );
            }
        });
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function finishRun(int $runId, string $status, int $imported, ?string $message): void
    {
        $this->db->update('sync_runs', [
            'finished_at' => Clock::nowUtc(),
            'status' => $status,
            'imported' => $imported,
            'message' => $message,
        ], ['id' => $runId]);
    }
}
