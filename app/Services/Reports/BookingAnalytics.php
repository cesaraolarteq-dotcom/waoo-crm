<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/** Pure projection. Raw source strings never become output labels. */
final class BookingAnalytics
{
    public function summarize(iterable $rows, int $year): array
    {
        $out = ['schema_version' => 1, 'year' => $year, 'timezone' => 'America/Chicago',
            'booking_count' => 0, 'source_counts' => [], 'exclusions' => [], 'months' => [],
            'weekdays' => [], 'packages' => [], 'postal_codes' => [], 'lead_sources' => []];
        $money = $days = $moneyCount = $dayCount = 0;
        foreach ($rows as $row) {
            $date = $row['date'];
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                || !($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) || $parsed->format('Y-m-d') !== $date) {
                $out['exclusions']['date_unknown_all_years'] = ($out['exclusions']['date_unknown_all_years'] ?? 0) + 1;
                continue;
            }
            if ((int)substr($date, 0, 4) !== $year) continue;
            if (!in_array($row['status'], ['completed', 'capacity_reserved'], true)) {
                $out['exclusions']['not_confirmed_or_completed'] = ($out['exclusions']['not_confirmed_or_completed'] ?? 0) + 1;
                continue;
            }
            $out['booking_count']++;
            $source = $row['source'] === 'crm' ? 'crm' : 'honeybook';
            $out['source_counts'][$source] = ($out['source_counts'][$source] ?? 0) + 1;
            $zip = preg_match('/^[0-9]{5}$/D', $row['postal_code'] ?? '') ? $row['postal_code'] : 'unknown';
            $lead = match (strtolower(trim($row['lead_source'] ?? ''))) {
                'whatsapp' => 'whatsapp', 'instagram' => 'instagram', 'facebook' => 'facebook',
                'google', 'google ads' => 'google', 'website', 'web' => 'website',
                'referral', 'referido', 'recomendación' => 'referral', '' => 'unknown', default => 'other',
            };
            foreach (['months' => substr($date, 0, 7), 'weekdays' => $parsed->format('N'),
                'postal_codes' => $zip, 'lead_sources' => $lead] as $dimension => $key) {
                $out[$dimension][$key] = ($out[$dimension][$key] ?? 0) + 1;
            }
            $packages = array_values(array_unique(array_filter($row['package_ids'] ?? [], fn ($id) => is_int($id) && $id > 0)));
            foreach ($packages ?: ['unknown'] as $key) $out['packages'][$key] = ($out['packages'][$key] ?? 0) + 1;
            if (is_int($row['total_cents'] ?? null) && $row['total_cents'] >= 0 && ($row['currency'] ?? null) === 'USD') {
                $money += $row['total_cents']; $moneyCount++;
            }
            $created = $row['created_at'] ?? null;
            // Only strict ISO dates / offset-bearing ISO instants qualify, never an import timestamp.
            if (is_string($created) && preg_match('/^\d{4}-\d{2}-\d{2}(?:T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d))?$/D', $created)) {
                try {
                    $literal = substr($created, 0, 10);
                    $valid = \DateTimeImmutable::createFromFormat('!Y-m-d', $literal);
                    if (!$valid || $valid->format('Y-m-d') !== $literal) continue;
                    $creation = CarbonImmutable::parse($created, 'America/Chicago')->setTimezone('America/Chicago')->startOfDay();
                    $party = CarbonImmutable::parse($date, 'America/Chicago');
                    if ($creation <= $party) {
                        $days += (int)$creation->diffInDays($party); $dayCount++;
                    }
                } catch (\Throwable) { /* Explicit unknown, never guessed. */ }
            }
        }
        foreach (['source_counts', 'exclusions', 'months', 'weekdays', 'packages', 'postal_codes', 'lead_sources'] as $dimension) ksort($out[$dimension]);
        foreach (['packages', 'postal_codes', 'lead_sources'] as $dimension) arsort($out[$dimension], SORT_NUMERIC);
        $out['ticket'] = ['currency' => 'USD', 'known_count' => $moneyCount, 'unknown_count' => $out['booking_count'] - $moneyCount,
            'total_cents' => $money, 'average_cents' => $moneyCount ? (int)round($money / $moneyCount) : null];
        $out['lead_time'] = ['known_count' => $dayCount, 'unknown_count' => $out['booking_count'] - $dayCount,
            'average_days' => $dayCount ? round($days / $dayCount, 1) : null];
        return $out;
    }

    public function csv(array $report): string
    {
        $file = fopen('php://temp', 'w+');
        fputcsv($file, ['dimension', 'key', 'count_or_value'], ',', '"', '');
        foreach (['months', 'weekdays', 'packages', 'postal_codes', 'lead_sources', 'source_counts', 'exclusions'] as $dimension) {
            foreach ($report[$dimension] as $key => $value) fputcsv($file, [$dimension, (string)$key, $value], ',', '"', '');
        }
        foreach (['ticket', 'lead_time'] as $dimension) foreach ($report[$dimension] as $key => $value) {
            fputcsv($file, [$dimension, $key, $value], ',', '"', '');
        }
        rewind($file); $csv = stream_get_contents($file); fclose($file);
        return $csv;
    }
}
