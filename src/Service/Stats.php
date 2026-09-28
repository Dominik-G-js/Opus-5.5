<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\Database;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Agregace pro dashboard a detail modelky. Vše v CZK (haléře), měsíce podle lokálního data.
 * Metody s parametrem Period pracují s libovolným obdobím [from, to), měsíční metody jsou jejich zkratky.
 */
final class Stats
{
    /** Skupiny typů plateb pro grafy (klíče odpovídají Labels „tx_group“). */
    public const TYPE_GROUPS = [
        'subscription' => ['subscription', 'renewal'],
        'tip' => ['tip'],
        'ppv' => ['message', 'post', 'media_link'],
        'other' => ['referral', 'affiliate', 'giveaway', 'other'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{0: string, 1: string} [první den měsíce, první den dalšího měsíce] */
    public static function monthRange(string $yearMonth): array
    {
        $start = new DateTimeImmutable($yearMonth . '-01');

        return [$start->format('Y-m-d'), $start->modify('first day of next month')->format('Y-m-d')];
    }

    public static function isValidMonth(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/', $value) === 1;
    }

    public static function typeGroup(string $type): string
    {
        foreach (self::TYPE_GROUPS as $group => $types) {
            if (in_array($type, $types, true)) {
                return $group;
            }
        }

        return 'other';
    }

    /** @return array<string, mixed> */
    public function monthSummary(string $yearMonth, ?int $modelId = null): array
    {
        [$from, $to] = self::monthRange($yearMonth);
        $summary = $this->rangeSummary($from, $to, $modelId);
        $summary['projected_net'] = $this->project($yearMonth, $summary['net']);
        $summary['projected_profit'] = $summary['projected_net'] - $summary['costs'];

        return $summary;
    }

    /**
     * Souhrn za období: hrubé tržby, čistě po poplatcích, poplatky, náklady (z toho společné), zisk, předplatitelé.
     *
     * @return array<string, int>
     */
    public function summary(Period $period, ?int $modelId = null): array
    {
        return $this->rangeSummary($period->from, $period->to, $modelId);
    }

    /** Lineární odhad za celý měsíc (jen pro běžící měsíc). */
    private function project(string $yearMonth, int $soFar): int
    {
        $today = Clock::todayLocal();
        if (substr($today, 0, 7) !== $yearMonth) {
            return $soFar;
        }
        $day = (int) substr($today, 8, 2);
        $daysInMonth = (int) (new DateTimeImmutable($yearMonth . '-01'))->format('t');

        return (int) round($soFar / max(1, $day) * $daysInMonth);
    }

    /** @return array<string, int> */
    private function rangeSummary(string $from, string $to, ?int $modelId): array
    {
        $modelFilter = $modelId !== null ? ' AND a.model_id = :m' : '';
        $params = ['s' => $from, 'e' => $to] + ($modelId !== null ? ['m' => $modelId] : []);

        $revenue = $this->db->one(
            "SELECT COALESCE(SUM(t.gross_czk_minor), 0) AS gross, COALESCE(SUM(t.net_czk_minor), 0) AS net, COUNT(*) AS count
             FROM transactions t JOIN accounts a ON a.id = t.account_id
             WHERE t.occurred_on >= :s AND t.occurred_on < :e{$modelFilter}",
            $params
        ) ?? ['gross' => 0, 'net' => 0, 'count' => 0];

        $costFilter = $modelId !== null ? ' AND model_id = :m' : '';
        $costs = (int) $this->db->scalar(
            "SELECT COALESCE(SUM(amount_czk_minor), 0) FROM costs WHERE incurred_on >= :s AND incurred_on < :e{$costFilter}",
            $params
        );

        $subs = $this->db->one(
            "SELECT COALESCE(SUM(s.new_subscribers), 0) AS new_subs, COALESCE(SUM(s.cancelled), 0) AS cancelled
             FROM subscriber_stats_daily s JOIN accounts a ON a.id = s.account_id
             WHERE s.day >= :s AND s.day < :e{$modelFilter}",
            $params
        ) ?? ['new_subs' => 0, 'cancelled' => 0];

        $net = (int) $revenue['net'];

        return [
            'gross' => (int) $revenue['gross'],
            'net' => $net,
            'fees' => (int) $revenue['gross'] - $net,
            'count' => (int) $revenue['count'],
            'costs' => $costs,
            'shared_costs' => $modelId === null ? $this->rangeSharedCosts($from, $to) : 0,
            'profit' => $net - $costs,
            'new_subs' => (int) $subs['new_subs'],
            'cancelled' => (int) $subs['cancelled'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function byModel(string $yearMonth): array
    {
        [$from, $to] = self::monthRange($yearMonth);

        return $this->rangeByModel($from, $to);
    }

    /**
     * Modelky za období: hrubě, čistě, náklady, zisk, ROI a počet plateb.
     *
     * @return list<array<string, mixed>>
     */
    public function modelsIn(Period $period): array
    {
        return $this->rangeByModel($period->from, $period->to);
    }

    /** @return list<array<string, mixed>> */
    private function rangeByModel(string $from, string $to): array
    {
        $rows = $this->db->all(
            "SELECT m.id, m.name, m.status, m.niche, m.avatar_image_id,
                COALESCE(r.gross, 0) AS gross, COALESCE(r.net, 0) AS net, COALESCE(r.payments, 0) AS payments,
                COALESCE((SELECT SUM(c.amount_czk_minor) FROM costs c
                          WHERE c.model_id = m.id AND c.incurred_on >= :s AND c.incurred_on < :e), 0) AS costs,
                COALESCE((SELECT SUM(s.new_subscribers) FROM subscriber_stats_daily s JOIN accounts a ON a.id = s.account_id
                          WHERE a.model_id = m.id AND s.day >= :s AND s.day < :e), 0) AS new_subs
             FROM models m
             LEFT JOIN (
                SELECT a.model_id, SUM(t.gross_czk_minor) AS gross, SUM(t.net_czk_minor) AS net, COUNT(*) AS payments
                FROM transactions t JOIN accounts a ON a.id = t.account_id
                WHERE t.occurred_on >= :s AND t.occurred_on < :e
                GROUP BY a.model_id
             ) r ON r.model_id = m.id
             ORDER BY net DESC, m.name",
            ['s' => $from, 'e' => $to]
        );

        return array_map(static function (array $row): array {
            foreach (['gross', 'net', 'payments', 'costs', 'new_subs'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['profit'] = $row['net'] - $row['costs'];
            $row['roi'] = $row['costs'] > 0 ? ($row['profit'] / $row['costs']) * 100 : null;

            return $row;
        }, $rows);
    }

    /**
     * Rozpad tržeb každé modelky: hrubě podle platforem a čistě podle skupin typů plateb.
     *
     * @return array<int, array{platforms: array<int, int>, groups: array<string, int>}>
     */
    public function modelMix(Period $period): array
    {
        $rows = $this->db->all(
            "SELECT a.model_id, a.platform_id, t.type, SUM(t.gross_czk_minor) AS gross, SUM(t.net_czk_minor) AS net
             FROM transactions t JOIN accounts a ON a.id = t.account_id
             WHERE t.occurred_on >= :s AND t.occurred_on < :e
             GROUP BY a.model_id, a.platform_id, t.type",
            ['s' => $period->from, 'e' => $period->to]
        );
        $mix = [];
        foreach ($rows as $row) {
            $modelId = (int) $row['model_id'];
            $platformId = (int) $row['platform_id'];
            $group = self::typeGroup((string) $row['type']);
            $mix[$modelId] ??= ['platforms' => [], 'groups' => []];
            $mix[$modelId]['platforms'][$platformId] = ($mix[$modelId]['platforms'][$platformId] ?? 0) + (int) $row['gross'];
            $mix[$modelId]['groups'][$group] = ($mix[$modelId]['groups'][$group] ?? 0) + (int) $row['net'];
        }

        return $mix;
    }

    /** Náklady bez přiřazené modelky (společná režie). */
    public function sharedCosts(string $yearMonth): int
    {
        [$from, $to] = self::monthRange($yearMonth);

        return $this->rangeSharedCosts($from, $to);
    }

    private function rangeSharedCosts(string $from, string $to): int
    {
        return (int) $this->db->scalar(
            'SELECT COALESCE(SUM(amount_czk_minor), 0) FROM costs WHERE model_id IS NULL AND incurred_on >= :s AND incurred_on < :e',
            ['s' => $from, 'e' => $to]
        );
    }

    /** @return list<array<string, mixed>> */
    public function byAccount(string $yearMonth, ?int $modelId = null): array
    {
        [$from, $to] = self::monthRange($yearMonth);

        return $this->rangeByAccount($from, $to, $modelId);
    }

    /** @return list<array<string, mixed>> */
    public function byAccountIn(Period $period): array
    {
        return $this->rangeByAccount($period->from, $period->to, null);
    }

    /** @return list<array<string, mixed>> */
    private function rangeByAccount(string $from, string $to, ?int $modelId): array
    {
        $modelFilter = $modelId !== null ? ' AND a.model_id = :m' : '';

        return $this->db->all(
            "SELECT a.id, a.handle, p.name AS platform, m.name AS model,
                    COALESCE(SUM(t.gross_czk_minor), 0) AS gross, COALESCE(SUM(t.net_czk_minor), 0) AS net, COUNT(t.id) AS count
             FROM accounts a
             JOIN platforms p ON p.id = a.platform_id
             JOIN models m ON m.id = a.model_id
             LEFT JOIN transactions t ON t.account_id = a.id AND t.occurred_on >= :s AND t.occurred_on < :e
             WHERE p.role IN ('monetization', 'both'){$modelFilter}
             GROUP BY a.id
             ORDER BY net DESC",
            ['s' => $from, 'e' => $to] + ($modelId !== null ? ['m' => $modelId] : [])
        );
    }

    /**
     * Platformy s tržbami v období (včetně AI politiky), seřazené podle hrubých tržeb.
     *
     * @return list<array{id: int, name: string, ai_policy: string, gross: int, net: int, count: int}>
     */
    public function platformsIn(Period $period): array
    {
        $rows = $this->db->all(
            "SELECT p.id, p.name, p.ai_policy, SUM(t.gross_czk_minor) AS gross, SUM(t.net_czk_minor) AS net, COUNT(*) AS count
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             JOIN platforms p ON p.id = a.platform_id
             WHERE t.occurred_on >= :s AND t.occurred_on < :e
             GROUP BY p.id
             ORDER BY gross DESC, p.name",
            ['s' => $period->from, 'e' => $period->to]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'ai_policy' => (string) $row['ai_policy'],
            'gross' => (int) $row['gross'],
            'net' => (int) $row['net'],
            'count' => (int) $row['count'],
        ], $rows);
    }

    /**
     * Pořadí výdělečných platforem podle ID (1, 2, …) — stálé přiřazení barvy platformě.
     *
     * @return array<int, int> [platform_id => pořadí od 1]
     */
    public function platformSlots(): array
    {
        $ids = array_map('intval', array_column(
            $this->db->all("SELECT id FROM platforms WHERE role IN ('monetization', 'both') ORDER BY id"),
            'id'
        ));

        return $ids === [] ? [] : array_combine($ids, range(1, count($ids)));
    }

    /** @return list<array<string, mixed>> */
    public function byType(string $yearMonth, ?int $modelId = null): array
    {
        [$from, $to] = self::monthRange($yearMonth);

        return $this->rangeByType($from, $to, $modelId);
    }

    /** @return list<array<string, mixed>> */
    public function byTypeIn(Period $period): array
    {
        return $this->rangeByType($period->from, $period->to, null);
    }

    /** @return list<array<string, mixed>> */
    private function rangeByType(string $from, string $to, ?int $modelId): array
    {
        $modelFilter = $modelId !== null ? ' AND a.model_id = :m' : '';

        return $this->db->all(
            "SELECT t.type, COALESCE(SUM(t.net_czk_minor), 0) AS net, COALESCE(SUM(t.gross_czk_minor), 0) AS gross, COUNT(*) AS count
             FROM transactions t JOIN accounts a ON a.id = t.account_id
             WHERE t.occurred_on >= :s AND t.occurred_on < :e{$modelFilter}
             GROUP BY t.type ORDER BY net DESC",
            ['s' => $from, 'e' => $to] + ($modelId !== null ? ['m' => $modelId] : [])
        );
    }

    /**
     * Nejlepší fanoušci — za měsíc, nebo za celou dobu ($yearMonth = null).
     *
     * @return list<array<string, mixed>>
     */
    public function topFans(?string $yearMonth, int $limit = 10, ?int $modelId = null): array
    {
        $range = $yearMonth !== null ? self::monthRange($yearMonth) : null;

        return $this->rangeTopFans($range, $limit, $modelId);
    }

    /** @return list<array<string, mixed>> */
    public function topFansIn(Period $period, int $limit = 10): array
    {
        return $this->rangeTopFans([$period->from, $period->to], $limit, null);
    }

    /**
     * @param array{0: string, 1: string}|null $range
     * @return list<array<string, mixed>>
     */
    private function rangeTopFans(?array $range, int $limit, ?int $modelId): array
    {
        $where = [];
        $params = [];
        if ($range !== null) {
            [$params['s'], $params['e']] = $range;
            $where[] = 't.occurred_on >= :s AND t.occurred_on < :e';
        }
        if ($modelId !== null) {
            $where[] = 'a.model_id = :m';
            $params['m'] = $modelId;
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        return $this->db->all(
            "SELECT f.id, f.handle, f.display_name, f.is_top_spender, a.handle AS account, p.name AS platform, m.name AS model,
                    SUM(t.net_czk_minor) AS net, COUNT(t.id) AS payments, MAX(t.occurred_at) AS last_payment
             FROM transactions t
             JOIN fans f ON f.id = t.fan_id
             JOIN accounts a ON a.id = t.account_id
             JOIN platforms p ON p.id = a.platform_id
             JOIN models m ON m.id = a.model_id
             {$whereSql}
             GROUP BY f.id
             ORDER BY net DESC
             LIMIT " . max(1, min(200, $limit)),
            $params
        );
    }

    /**
     * „Koho oslovit“: fanoušci z horních $topShare všech platících fanoušků podle celkové čisté útraty
     * (za celou dobu do $today), kteří aspoň $inactiveDays dní nic nezaplatili. Seřazeno podle útraty.
     *
     * @return list<array{id: int, name: string, handle: string|null, is_top_spender: int, model_id: int, model: string, platform: string, net: int, payments: int, last_payment: string, days: int}>
     */
    public function lapsedTopFans(string $today, int $inactiveDays = 30, float $topShare = 0.2, int $limit = 8): array
    {
        if ($inactiveDays < 1 || $topShare <= 0 || $topShare > 1 || $limit < 1) {
            throw new \InvalidArgumentException('Neplatné parametry výběru fanoušků.');
        }
        // Platby s datem v budoucnu (překlep) se nepočítají — jinak by fanouška nesmyslně držely „aktivního“.
        $fans = $this->db->all(
            'SELECT f.id, f.handle, f.display_name, f.is_top_spender, m.id AS model_id, m.name AS model, p.name AS platform,
                    SUM(t.net_czk_minor) AS net, COUNT(t.id) AS payments, MAX(t.occurred_on) AS last_payment
             FROM transactions t
             JOIN fans f ON f.id = t.fan_id
             JOIN accounts a ON a.id = f.account_id
             JOIN models m ON m.id = a.model_id
             JOIN platforms p ON p.id = a.platform_id
             WHERE t.occurred_on <= :today
             GROUP BY f.id
             HAVING SUM(t.net_czk_minor) > 0
             ORDER BY net DESC, last_payment DESC, f.id',
            ['today' => $today]
        );
        $top = array_slice($fans, 0, (int) max(1, ceil(count($fans) * $topShare)));
        $todayDate = new DateTimeImmutable($today);
        $lapsed = [];
        foreach ($top as $fan) {
            $days = (int) (new DateTimeImmutable((string) $fan['last_payment']))->diff($todayDate)->days;
            if ($days < $inactiveDays) {
                continue;
            }
            $lapsed[] = [
                'id' => (int) $fan['id'],
                'name' => (string) ($fan['display_name'] ?? $fan['handle'] ?? '—'),
                'handle' => $fan['handle'],
                'is_top_spender' => (int) $fan['is_top_spender'],
                'model_id' => (int) $fan['model_id'],
                'model' => (string) $fan['model'],
                'platform' => (string) $fan['platform'],
                'net' => (int) $fan['net'],
                'payments' => (int) $fan['payments'],
                'last_payment' => (string) $fan['last_payment'],
                'days' => $days,
            ];
            if (count($lapsed) === $limit) {
                break;
            }
        }

        return $lapsed;
    }

    /**
     * Závislost na největších fanoušcích za období: kolik fanoušků platilo (čistě > 0), kolik utratili,
     * průměr na platícího fanouška a součet $top největších. Platby bez přiřazeného fanouška se nepočítají.
     *
     * @return array{paying: int, fan_net: int, average: int|null, top_count: int, top_net: int}
     */
    public function fanConcentration(Period $period, int $top = 3): array
    {
        $nets = array_map('intval', array_column($this->db->all(
            'SELECT SUM(net_czk_minor) AS net FROM transactions
             WHERE fan_id IS NOT NULL AND occurred_on >= :s AND occurred_on < :e
             GROUP BY fan_id HAVING SUM(net_czk_minor) > 0
             ORDER BY net DESC',
            ['s' => $period->from, 'e' => $period->to]
        ), 'net'));
        $paying = count($nets);
        $fanNet = array_sum($nets);
        $topNets = array_slice($nets, 0, max(1, $top));

        return [
            'paying' => $paying,
            'fan_net' => $fanNet,
            'average' => $paying > 0 ? (int) round($fanNet / $paying) : null,
            'top_count' => count($topNets),
            'top_net' => array_sum($topNets),
        ];
    }

    /**
     * Nejvyšší jednotlivé platby v období (hrubě v CZK), při shodě novější dřív.
     *
     * @return list<array<string, mixed>>
     */
    public function largestPayments(Period $period, int $limit = 8): array
    {
        return $this->db->all(
            "SELECT t.id, t.type, t.occurred_at, t.occurred_on, t.gross_czk_minor AS gross, t.net_czk_minor AS net,
                    f.id AS fan_id, f.handle AS fan_handle, f.display_name AS fan_name, f.is_top_spender,
                    m.id AS model_id, m.name AS model, p.id AS platform_id, p.name AS platform
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             JOIN models m ON m.id = a.model_id
             JOIN platforms p ON p.id = a.platform_id
             LEFT JOIN fans f ON f.id = t.fan_id
             WHERE t.occurred_on >= :s AND t.occurred_on < :e
             ORDER BY t.gross_czk_minor DESC, t.occurred_at DESC, t.id DESC
             LIMIT " . max(1, min(100, $limit)),
            ['s' => $period->from, 'e' => $period->to]
        );
    }

    /**
     * Časová řada období po dnech (u YTD po týdnech) do dneška: hrubě, čistě, poplatky, náklady
     * a čistě podle skupin typů plateb, platforem a modelek. Týden začíná pondělím; první a poslední
     * týden mohou být kratší (start/end jsou skutečné hranice v rámci období, end včetně).
     *
     * @return list<array{start: string, end: string, gross: int, net: int, fees: int, costs: int, groups: array<string, int>, platforms: array<int, int>, models: array<int, int>}>
     */
    public function series(Period $period): array
    {
        $end = $period->dataEnd();
        $unit = $period->bucketUnit();
        $buckets = [];
        $cursor = new DateTimeImmutable($period->from);
        $last = new DateTimeImmutable($end);
        while ($cursor < $last) {
            $day = $cursor->format('Y-m-d');
            $key = self::bucketKey($day, $unit);
            $buckets[$key] ??= [
                'start' => $day,
                'end' => $day,
                'gross' => 0,
                'net' => 0,
                'fees' => 0,
                'costs' => 0,
                'groups' => array_fill_keys(array_keys(self::TYPE_GROUPS), 0),
                'platforms' => [],
                'models' => [],
            ];
            $buckets[$key]['end'] = $day;
            $cursor = $cursor->modify('+1 day');
        }
        if ($buckets === []) {
            return [];
        }
        $bucketOf = static fn (string $day): string => self::bucketKey($day, $unit);
        $params = ['s' => $period->from, 'e' => $end];

        $rows = $this->db->all(
            "SELECT t.occurred_on AS day, t.type, a.platform_id, a.model_id,
                    SUM(t.gross_czk_minor) AS gross, SUM(t.net_czk_minor) AS net
             FROM transactions t JOIN accounts a ON a.id = t.account_id
             WHERE t.occurred_on >= :s AND t.occurred_on < :e
             GROUP BY t.occurred_on, t.type, a.platform_id, a.model_id",
            $params
        );
        foreach ($rows as $row) {
            $key = $bucketOf((string) $row['day']);
            if (!isset($buckets[$key])) {
                continue;
            }
            $gross = (int) $row['gross'];
            $net = (int) $row['net'];
            $platformId = (int) $row['platform_id'];
            $modelId = (int) $row['model_id'];
            $bucket = &$buckets[$key];
            $bucket['gross'] += $gross;
            $bucket['net'] += $net;
            $bucket['fees'] += $gross - $net;
            $bucket['groups'][self::typeGroup((string) $row['type'])] += $net;
            $bucket['platforms'][$platformId] = ($bucket['platforms'][$platformId] ?? 0) + $net;
            $bucket['models'][$modelId] = ($bucket['models'][$modelId] ?? 0) + $net;
            unset($bucket);
        }

        $costs = $this->db->all(
            'SELECT incurred_on AS day, SUM(amount_czk_minor) AS total FROM costs
             WHERE incurred_on >= :s AND incurred_on < :e GROUP BY incurred_on',
            $params
        );
        foreach ($costs as $row) {
            $key = $bucketOf((string) $row['day']);
            if (isset($buckets[$key])) {
                $buckets[$key]['costs'] += (int) $row['total'];
            }
        }

        return array_values($buckets);
    }

    /** Klíč sloupce: den, nebo pondělí jeho týdne. */
    private static function bucketKey(string $day, string $unit): string
    {
        if ($unit !== 'week') {
            return $day;
        }
        $date = new DateTimeImmutable($day);

        return $date->modify('-' . ((int) $date->format('N') - 1) . ' days')->format('Y-m-d');
    }

    /**
     * Posledních N měsíců: čisté příjmy a náklady.
     *
     * @return list<array{month: string, net: int, costs: int}>
     */
    public function monthlySeries(int $months = 12, ?int $modelId = null): array
    {
        $end = new DateTimeImmutable(substr(Clock::todayLocal(), 0, 7) . '-01');
        $start = $end->modify('-' . ($months - 1) . ' months');
        $modelFilter = $modelId !== null ? ' AND a.model_id = :m' : '';
        $params = ['s' => $start->format('Y-m-d')] + ($modelId !== null ? ['m' => $modelId] : []);

        $net = $this->db->all(
            "SELECT substr(t.occurred_on, 1, 7) AS month, SUM(t.net_czk_minor) AS total
             FROM transactions t JOIN accounts a ON a.id = t.account_id
             WHERE t.occurred_on >= :s{$modelFilter} GROUP BY month",
            $params
        );
        $costFilter = $modelId !== null ? ' AND model_id = :m' : '';
        $costs = $this->db->all(
            "SELECT substr(incurred_on, 1, 7) AS month, SUM(amount_czk_minor) AS total
             FROM costs WHERE incurred_on >= :s{$costFilter} GROUP BY month",
            $params
        );
        $netByMonth = array_column($net, 'total', 'month');
        $costsByMonth = array_column($costs, 'total', 'month');

        $series = [];
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 month')) {
            $key = $cursor->format('Y-m');
            $series[] = ['month' => $key, 'net' => (int) ($netByMonth[$key] ?? 0), 'costs' => (int) ($costsByMonth[$key] ?? 0)];
        }

        return $series;
    }

    /** @return list<array<string, mixed>> */
    public function clicksBySource(string $yearMonth, ?int $modelId = null): array
    {
        [$from, $to] = self::monthRange($yearMonth);

        return $this->rangeClicksBySource($from, $to, $modelId);
    }

    /** @return list<array<string, mixed>> */
    public function clicksBySourceIn(Period $period): array
    {
        return $this->rangeClicksBySource($period->from, $period->to, null);
    }

    /** @return list<array<string, mixed>> */
    private function rangeClicksBySource(string $from, string $to, ?int $modelId): array
    {
        $modelFilter = $modelId !== null ? ' AND l.model_id = :m' : '';

        return $this->db->all(
            "SELECT l.source, SUM(c.clicks) AS clicks
             FROM link_clicks_daily c JOIN links l ON l.id = c.link_id
             WHERE c.day >= :s AND c.day < :e{$modelFilter}
             GROUP BY l.source ORDER BY clicks DESC",
            ['s' => $from, 'e' => $to] + ($modelId !== null ? ['m' => $modelId] : [])
        );
    }

    /**
     * Celkové ekonomické ukazatele modelky za celou dobu (kolik stála výroba, kolik vydělala).
     *
     * @return array<string, mixed>
     */
    public function modelLifetime(int $modelId): array
    {
        $net = (int) $this->db->scalar(
            'SELECT COALESCE(SUM(t.net_czk_minor), 0) FROM transactions t JOIN accounts a ON a.id = t.account_id WHERE a.model_id = :m',
            ['m' => $modelId]
        );
        $costRows = $this->db->all(
            'SELECT category, SUM(amount_czk_minor) AS total,
                    SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) AS quantity,
                    SUM(CASE WHEN quantity > 0 THEN amount_czk_minor ELSE 0 END) AS total_with_quantity
             FROM costs WHERE model_id = :m GROUP BY category ORDER BY total DESC',
            ['m' => $modelId]
        );
        $costs = 0;
        $creation = 0;
        $generationSpend = 0;
        $generationQuantity = 0;
        foreach ($costRows as $row) {
            $costs += (int) $row['total'];
            if (in_array($row['category'], ['training', 'generation', 'video', 'voice'], true)) {
                $creation += (int) $row['total'];
            }
            if ($row['category'] === 'generation' && (int) $row['quantity'] > 0) {
                // Jen náklady se zadaným počtem kusů (dobití kreditů bez počtu by cenu za kus zkreslilo).
                $generationSpend += (int) $row['total_with_quantity'];
                $generationQuantity += (int) $row['quantity'];
            }
        }

        return [
            'net' => $net,
            'costs' => $costs,
            'profit' => $net - $costs,
            'creation_costs' => $creation,
            'cost_per_image' => $generationQuantity > 0 ? (int) round($generationSpend / $generationQuantity) : null,
            'generated_images' => $generationQuantity,
            'costs_by_category' => $costRows,
            'fans' => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM fans f JOIN accounts a ON a.id = f.account_id WHERE a.model_id = :m',
                ['m' => $modelId]
            ),
        ];
    }
}
