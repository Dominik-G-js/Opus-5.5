<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Období přehledu: kalendářní měsíc, posledních 7 / 30 dní nebo od začátku roku.
 * Rozsah je [from, to) v lokálních datech 'Y-m-d'. Srovnávací období má stejnou délku a končí
 * ve stejném bodě (běžící měsíc se tedy srovnává se stejnými dny minulého měsíce).
 */
final class Period
{
    public const RANGES = ['7d', '30d', 'ytd'];

    private const MONTHS = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];

    private function __construct(
        public readonly string $kind,
        public readonly string $from,
        public readonly string $to,
        public readonly string $today,
        public readonly ?string $month = null,
    ) {
    }

    /** Období z parametrů URL: ?range=7d|30d|ytd má přednost, jinak ?month=YYYY-MM, jinak běžící měsíc. */
    public static function fromQuery(?string $range, ?string $month, string $today): self
    {
        return match ($range) {
            '7d' => self::lastDays(7, $today),
            '30d' => self::lastDays(30, $today),
            'ytd' => self::yearToDate($today),
            default => self::month($month !== null && Stats::isValidMonth($month) ? $month : substr($today, 0, 7), $today),
        };
    }

    public static function month(string $yearMonth, string $today): self
    {
        if (!Stats::isValidMonth($yearMonth)) {
            throw new InvalidArgumentException("Neplatný měsíc „{$yearMonth}“.");
        }
        [$from, $to] = Stats::monthRange($yearMonth);

        return new self('month', $from, $to, self::date($today)->format('Y-m-d'), $yearMonth);
    }

    public static function lastDays(int $days, string $today): self
    {
        if ($days < 1) {
            throw new InvalidArgumentException('Počet dní musí být kladný.');
        }
        $end = self::date($today)->modify('+1 day');

        return new self($days . 'd', $end->modify('-' . $days . ' days')->format('Y-m-d'), $end->format('Y-m-d'), self::date($today)->format('Y-m-d'));
    }

    public static function yearToDate(string $today): self
    {
        $date = self::date($today);

        return new self('ytd', $date->format('Y') . '-01-01', $date->modify('+1 day')->format('Y-m-d'), $date->format('Y-m-d'));
    }

    /** Srovnávací období stejné délky těsně před tímto (u YTD stejné dny loňského roku). */
    public function previous(): self
    {
        $from = self::date($this->from);
        if ($this->kind === 'ytd') {
            $lastYearDay = self::sameDayLastYear(self::date($this->today));

            return new self('compare', $from->modify('-1 year')->format('Y-m-d'), $lastYearDay->modify('+1 day')->format('Y-m-d'), $this->today);
        }
        if ($this->kind === 'month') {
            $previousStart = $from->modify('-1 month');
            if (!$this->isCurrentMonth()) {
                return new self('compare', $previousStart->format('Y-m-d'), $this->from, $this->today, $previousStart->format('Y-m'));
            }
            // Běžící měsíc: stejný počet dní od začátku minulého měsíce (ne celý minulý měsíc).
            $elapsed = (int) self::date($this->today)->format('j');
            $days = min($elapsed, (int) $previousStart->format('t'));

            return new self('compare', $previousStart->format('Y-m-d'), $previousStart->modify('+' . $days . ' days')->format('Y-m-d'), $this->today);
        }
        $length = $this->days();

        return new self('compare', $from->modify('-' . $length . ' days')->format('Y-m-d'), $this->from, $this->today);
    }

    /** Počet dní období. */
    public function days(): int
    {
        return (int) self::date($this->from)->diff(self::date($this->to))->days;
    }

    /** Konec dat k zobrazení (exkluzivně): budoucí dny běžícího období se nekreslí. */
    public function dataEnd(): string
    {
        $tomorrow = self::date($this->today)->modify('+1 day')->format('Y-m-d');

        return min($this->to, max($this->from, $tomorrow));
    }

    /** Jednotka sloupců grafu: dny, u YTD týdny (pondělí–neděle). */
    public function bucketUnit(): string
    {
        return $this->kind === 'ytd' ? 'week' : 'day';
    }

    public function isCurrentMonth(): bool
    {
        return $this->kind === 'month' && $this->month === substr($this->today, 0, 7);
    }

    public function label(): string
    {
        return match ($this->kind) {
            'month' => self::monthName((string) $this->month),
            'ytd' => 'od začátku roku ' . substr($this->from, 0, 4),
            'compare' => 'srovnávací období',
            default => 'posledních ' . $this->days() . ' dní',
        };
    }

    public function compareLabel(): string
    {
        return match ($this->kind) {
            'month' => $this->isCurrentMonth() ? 'vs. stejné dny minulého měsíce' : 'vs. předchozí měsíc',
            'ytd' => 'vs. stejné období loni',
            default => 'vs. předchozích ' . $this->days() . ' dní',
        };
    }

    /** Parametry URL, které období znovu vytvoří. @return array<string, string> */
    public function query(): array
    {
        return $this->kind === 'month' ? ['month' => (string) $this->month] : ['range' => $this->kind];
    }

    public static function monthName(string $yearMonth): string
    {
        return self::MONTHS[(int) substr($yearMonth, 5, 2) - 1] . ' ' . substr($yearMonth, 0, 4);
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("Neplatné datum „{$value}“.");
        }

        return $date;
    }

    /** 29. 2. → 28. 2. loňského roku. */
    private static function sameDayLastYear(DateTimeImmutable $date): DateTimeImmutable
    {
        $year = (int) $date->format('Y') - 1;
        $month = (int) $date->format('n');
        $lastDay = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, min((int) $date->format('j'), $lastDay)));
    }
}
