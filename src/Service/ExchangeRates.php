<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\Database;
use App\Http\HttpClient;
use App\Http\HttpClientException;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Kurzy ČNB (oficiální API api.cnb.cz). Kurz se vyhlašuje v pracovní dny cca ve 14:30;
 * pro víkend a svátek platí poslední předchozí vyhlášený kurz.
 */
final class ExchangeRates
{
    private const API = 'https://api.cnb.cz/cnbapi/exrates/daily-currency-month';
    private const CURRENT_MONTH_TTL = 3600;

    public function __construct(
        private readonly Database $db,
        private readonly HttpClient $http,
    ) {
    }

    /**
     * Kolik CZK stojí 1 jednotka měny k danému dni (Y-m-d, lokální datum).
     *
     * @throws ExchangeRateUnavailable
     */
    public function czkPerUnit(string $currency, string $date): float
    {
        $currency = strtoupper($currency);
        if ($currency === 'CZK') {
            return 1.0;
        }
        $today = Clock::todayLocal();
        if ($date > $today) {
            $date = $today;
        }
        $day = new DateTimeImmutable($date);
        // Pokrytí i začátku měsíce (1. den může být svátek → kurz z předchozího měsíce).
        foreach (array_unique([$day->format('Y-m'), $day->modify('-7 days')->format('Y-m')]) as $yearMonth) {
            $this->ensureMonth($currency, $yearMonth, $today);
        }

        $rate = $this->db->scalar(
            'SELECT czk_per_unit FROM exchange_rates WHERE currency = :c AND rate_date <= :d ORDER BY rate_date DESC LIMIT 1',
            ['c' => $currency, 'd' => $date]
        );
        if ($rate === null) {
            throw new ExchangeRateUnavailable("Kurz ČNB pro {$currency} k {$date} není k dispozici. Zadej kurz ručně.");
        }

        return (float) $rate;
    }

    /** Vynucené stažení kurzů aktuálního a předchozího měsíce (např. z CLI nebo nastavení). */
    public function refresh(string $currency): int
    {
        $currency = strtoupper($currency);
        $now = new DateTimeImmutable(Clock::todayLocal());
        $count = $this->fetchMonth($currency, $now->modify('first day of previous month')->format('Y-m'));

        return $count + $this->fetchMonth($currency, $now->format('Y-m'));
    }

    private function ensureMonth(string $currency, string $yearMonth, string $today): void
    {
        $fetchedAt = $this->db->scalar(
            'SELECT fetched_at FROM exchange_rate_months WHERE currency = :c AND year_month = :m',
            ['c' => $currency, 'm' => $yearMonth]
        );
        $isCurrentMonth = $yearMonth === substr($today, 0, 7);
        if ($fetchedAt !== null) {
            if (!$isCurrentMonth) {
                return; // uzavřený měsíc se už nezmění
            }
            $age = time() - (new DateTimeImmutable($fetchedAt . ' UTC'))->getTimestamp();
            if ($age < self::CURRENT_MONTH_TTL) {
                return;
            }
        }
        if ($yearMonth > substr($today, 0, 7)) {
            return;
        }
        $this->fetchMonth($currency, $yearMonth);
    }

    /** @throws ExchangeRateUnavailable */
    private function fetchMonth(string $currency, string $yearMonth): int
    {
        $url = self::API . '?' . http_build_query(['currency' => $currency, 'yearMonth' => $yearMonth]);
        try {
            $response = $this->http->request('GET', $url, ['Accept' => 'application/json']);
            if (!$response->ok()) {
                throw new ExchangeRateUnavailable("ČNB API vrátilo HTTP {$response->status} pro {$currency} {$yearMonth}.");
            }
            $data = $response->json();
        } catch (HttpClientException $e) {
            throw new ExchangeRateUnavailable('Kurzy ČNB nejsou dostupné: ' . $e->getMessage(), 0, $e);
        }

        $rates = $data['rates'] ?? null;
        if (!is_array($rates)) {
            throw new ExchangeRateUnavailable('Neočekávaný formát odpovědi ČNB (chybí rates).');
        }

        return $this->db->transaction(function () use ($rates, $currency, $yearMonth): int {
            $stored = 0;
            foreach ($rates as $row) {
                if (!is_array($row) || ($row['currencyCode'] ?? null) !== $currency) {
                    continue;
                }
                $amount = (int) ($row['amount'] ?? 0);
                $rate = $row['rate'] ?? null;
                $validFor = substr((string) ($row['validFor'] ?? ''), 0, 10);
                if ($amount <= 0 || !is_numeric($rate) || !Clock::isValidDate($validFor)) {
                    continue;
                }
                $this->db->run(
                    'INSERT INTO exchange_rates (currency, rate_date, czk_per_unit) VALUES (:c, :d, :r)
                     ON CONFLICT (currency, rate_date) DO UPDATE SET czk_per_unit = excluded.czk_per_unit',
                    ['c' => $currency, 'd' => $validFor, 'r' => (float) $rate / $amount]
                );
                $stored++;
            }
            $this->db->run(
                'INSERT INTO exchange_rate_months (currency, year_month, fetched_at) VALUES (:c, :m, :t)
                 ON CONFLICT (currency, year_month) DO UPDATE SET fetched_at = excluded.fetched_at',
                ['c' => $currency, 'm' => $yearMonth, 't' => Clock::nowUtc()]
            );

            return $stored;
        });
    }
}
