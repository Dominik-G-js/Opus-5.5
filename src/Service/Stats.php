<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\Database;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Agregace pro dashboard a detail modelky. Vše v CZK (haléře), měsíce podle lokálního data.
 */
final class Stats
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{0: string, 1: string} [první den měsíce, první den dalšího měsíce] */
    public static function monthRange(string $yearMonth): array
    {
        $start = new DateTimeImmutable($yearMonth . '-01');

        return [$start->format('Y-m-d'), $start->modify('first day of next month')->format('Y-m-d')];
    }

    public static function isValidMonth(string $value): bool
    {
        return preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/', $value) === 1;
    }

    /** @return array<string, mixed> */
    public function monthSummary(string $yearMonth, ?int $modelId = null): array
    {
        [$from, $to] = self::monthRange($yearMonth);
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
            'profit' => $net - $costs,
            'new_subs' => (int) $subs['new_subs'],
            'cancelled' => (int) $subs['cancelled'],
            'projected_net' => $this->project($yearMonth, $net),
            'projected_profit' => $this->project($yearMonth, $net) - $costs,
        ];
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

    /** @return list<array<string, mixed>> */
    public function byModel(string $yearMonth): array
    {
        [$from, $to] = self::monthRange($yearMonth);
        $rows = $this->db->all(
            "SELECT m.id, m.name, m.status,
                COALESCE((SELECT SUM(t.net_czk_minor) FROM transactions t JOIN accounts a ON a.id = t.account_id
                          WHERE a.model_id = m.id AND t.occurred_on >= :s AND t.occurred_on < :e), 0) AS net,
                COALESCE((SELECT SUM(c.amount_czk_minor) FROM costs c
                          WHERE c.model_id = m.id AND c.incurred_on >= :s AND c.incurred_on < :e), 0) AS costs,
                COALESCE((SELECT SUM(s.new_subscribers) FROM subscriber_stats_daily s JOIN accounts a ON a.id = s.account_id
                          WHERE a.model_id = m.id AND s.day >= :s AND s.day < :e), 0) AS new_subs
             FROM models m
             ORDER BY net DESC, m.name",
            ['s' => $from, 'e' => $to]
        );

        return array_map(static function (array $row): array {
            $row['net'] = (int) $row['net'];
            $row['costs'] = (int) $row['costs'];
            $row['profit'] = $row['net'] - $row['costs'];
            $row['roi'] = $row['costs'] > 0 ? ($row['profit'] / $row['costs']) * 100 : null;

            return $row;
        }, $rows);
    }

    /** Náklady bez přiřazené modelky (společná režie). */
    public function sharedCosts(string $yearMonth): int
    {
        [$from, $to] = self::monthRange($yearMonth);

        return (int) $this->db->scalar(
            'SELECT COALESCE(SUM(amount_czk_minor), 0) FROM costs WHERE model_id IS NULL AND incurred_on >= :s AND incurred_on < :e',
            ['s' => $from, 'e' => $to]
        );
    }

    /** @return list<array<string, mixed>> */
    public function byAccount(string $yearMonth, ?int $modelId = null): array
    {
        [$from, $to] = self::monthRange($yearMonth);
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

    /** @return list<array<string, mixed>> */
    public function byType(string $yearMonth, ?int $modelId = null): array
    {
        [$from, $to] = self::monthRange($yearMonth);
        $modelFilter = $modelId !== null ? ' AND a.model_id = :m' : '';

        return $this->db->all(
            "SELECT t.type, COALESCE(SUM(t.net_czk_minor), 0) AS net, COUNT(*) AS count
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
        $where = [];
        $params = [];
        if ($yearMonth !== null) {
            [$params['s'], $params['e']] = self::monthRange($yearMonth);
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
            'SELECT category, SUM(amount_czk_minor) AS total, SUM(quantity) AS quantity
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
                $generationSpend += (int) $row['total'];
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
