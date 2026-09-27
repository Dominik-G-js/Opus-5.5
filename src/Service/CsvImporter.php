<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Labels;
use App\Support\Money;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Import příjmů z CSV exportu libovolné platformy s ručním mapováním sloupců.
 * Opakovaný import stejného souboru nevytvoří duplicity (deterministický dedupe klíč).
 */
final class CsvImporter
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS = 20000;
    public const FIELDS = [
        'date' => 'Datum a čas *',
        'gross' => 'Hrubá částka',
        'net' => 'Čistá částka (po poplatku)',
        'type' => 'Typ platby',
        'fan' => 'Fanoušek (jméno / ID)',
        'currency' => 'Měna',
        'note' => 'Poznámka',
    ];

    private const DATE_FORMATS = [
        'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s.v\Z', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d',
        'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'j.n.Y', 'm/d/Y H:i', 'm/d/Y g:i A', 'm/d/Y', 'M j, Y g:i A', 'M j, Y', 'd M Y',
    ];

    private const TYPE_KEYWORDS = [
        'renew' => 'renewal', 'recurring' => 'renewal', 'rebill' => 'renewal',
        'subscri' => 'subscription', 'předplat' => 'subscription',
        'tip' => 'tip', 'spropit' => 'tip',
        'message' => 'message', 'ppv' => 'message', 'chat' => 'message', 'zpráv' => 'message',
        'post' => 'post', 'příspěv' => 'post',
        'referral' => 'referral', 'affiliate' => 'affiliate', 'giveaway' => 'giveaway',
        'link' => 'media_link',
    ];

    public function __construct(private readonly Ledger $ledger)
    {
    }

    /**
     * @return array{delimiter: string, headers: list<string>, rows: list<list<string>>}
     */
    public function read(string $path, int $limit = self::MAX_ROWS): array
    {
        $size = (int) filesize($path);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('CSV musí mít 1 B – 5 MB.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('CSV nelze otevřít.');
        }
        try {
            $firstLine = (string) fgets($handle);
            $firstLine = (string) preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
            $delimiter = $this->detectDelimiter($firstLine);
            $headers = array_map(static fn (?string $h): string => trim((string) $h), str_getcsv(rtrim($firstLine, "\r\n"), $delimiter, '"', ''));
            if (count($headers) < 2) {
                throw new RuntimeException('CSV musí mít hlavičku s alespoň 2 sloupci.');
            }
            $rows = [];
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }
                $rows[] = array_map(static fn (?string $cell): string => trim((string) $cell), $row);
                if (count($rows) >= $limit) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }
        foreach ($headers as $i => $header) {
            if (!mb_check_encoding($header, 'UTF-8')) {
                $headers[$i] = (string) mb_convert_encoding($header, 'UTF-8', 'Windows-1250');
            }
        }

        return ['delimiter' => $delimiter, 'headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param array<string, int|null> $mapping pole → index sloupce
     * @param array{account_id: int, currency: string, fee_percent: float, timezone: string} $options
     * @return array{rows: list<array<string, mixed>>, errors: list<string>}
     */
    public function prepare(string $path, array $mapping, array $options): array
    {
        if (!isset($mapping['date'])) {
            throw new InvalidArgumentException('Vyber sloupec s datem.');
        }
        if (!isset($mapping['net']) && !isset($mapping['gross'])) {
            throw new InvalidArgumentException('Vyber sloupec s čistou nebo hrubou částkou.');
        }
        $zone = new DateTimeZone($options['timezone']);
        $data = $this->read($path);
        $prepared = [];
        $errors = [];
        $seen = [];
        foreach ($data['rows'] as $index => $row) {
            $line = $index + 2;
            try {
                $moment = $this->parseDate($this->cell($row, $mapping['date']), $zone);
                $currency = isset($mapping['currency']) && $this->cell($row, $mapping['currency']) !== ''
                    ? Money::assertCurrency($this->cell($row, $mapping['currency']))
                    : $options['currency'];
                $gross = isset($mapping['gross']) ? Money::parseToMinor($this->cell($row, $mapping['gross'])) : null;
                $net = isset($mapping['net']) ? Money::parseToMinor($this->cell($row, $mapping['net'])) : null;
                if ($net === null) {
                    $net = (int) round((int) $gross * (1 - $options['fee_percent'] / 100));
                }
                if ($gross === null) {
                    $gross = $options['fee_percent'] < 100 ? (int) round($net / (1 - $options['fee_percent'] / 100)) : $net;
                }
                $type = isset($mapping['type']) ? $this->guessType($this->cell($row, $mapping['type'])) : 'other';
                $fan = isset($mapping['fan']) ? $this->cell($row, $mapping['fan']) : '';
                $note = isset($mapping['note']) ? mb_substr($this->cell($row, $mapping['note']), 0, 500) : null;

                $fingerprint = implode('|', [$options['account_id'], $moment->format('c'), $gross, $net, $currency, $type, $fan, (string) $note]);
                $seen[$fingerprint] = ($seen[$fingerprint] ?? 0) + 1;

                $prepared[] = [
                    'fan' => $fan,
                    'row' => $this->ledger->prepareTransaction($options['account_id'], [
                        'occurred_at' => $moment,
                        'type' => $type,
                        'gross_minor' => $gross,
                        'net_minor' => $net,
                        'currency' => $currency,
                        'source' => 'csv',
                        'dedupe_key' => 'csv:' . hash('sha256', $fingerprint . '#' . $seen[$fingerprint]),
                        'note' => $note === '' ? null : $note,
                    ]),
                ];
            } catch (ExchangeRateUnavailable $e) {
                throw $e; // bez kurzu nemá smysl pokračovat (a opakovat síťové pokusy po řádcích)
            } catch (InvalidArgumentException|RuntimeException $e) {
                if (count($errors) < 50) {
                    $errors[] = "Řádek {$line}: " . $e->getMessage();
                }
            }
        }

        return ['rows' => $prepared, 'errors' => $errors];
    }

    public function guessType(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if (in_array($value, Labels::keys('tx_type'), true)) {
            return $value;
        }
        foreach (self::TYPE_KEYWORDS as $keyword => $type) {
            if (str_contains($value, $keyword)) {
                return $type;
            }
        }

        return 'other';
    }

    public function parseDate(string $value, DateTimeZone $zone): DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Chybí datum.');
        }
        foreach (self::DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value, $zone);
            $problems = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))) {
                return $date;
            }
        }
        throw new InvalidArgumentException("Nerozpoznané datum „{$value}“.");
    }

    /** @param list<string> $row */
    private function cell(array $row, ?int $index): string
    {
        return $index === null ? '' : ($row[$index] ?? '');
    }

    private function detectDelimiter(string $line): string
    {
        $best = ',';
        $bestCount = 0;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = substr_count($line, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }
}
