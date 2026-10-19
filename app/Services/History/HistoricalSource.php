<?php
namespace App\Services\History;

use App\Services\Imports\{ImportCsv, ImportInput};
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

final class HistoricalSource
{
    public const PROFILE = 'honeybook-history-v1';
    public const HEADERS = ['project_id', 'contact_id', 'client_name', 'client_email', 'client_phone',
        'event_date', 'start_time', 'end_time', 'end_date', 'postal_code', 'city', 'project_name',
        'child_name', 'lead_source', 'booked_total', 'currency', 'project_status', 'archive_reason', 'event_type'];

    public function upload(UploadedFile $file, CarbonImmutable $cutoff): array
    {
        $parsed = app(ImportCsv::class)->upload($file, 'projects', ['encoding' => 'utf-8', 'delimiter' => ',', 'columns' => []]);
        if ($parsed['headers'] !== self::HEADERS) ImportInput::fail('file', 'exact_19_history_headers_required');
        if ($parsed['rows'] === []) ImportInput::fail('file', 'history_rows_required');
        $seen = [];
        foreach ($parsed['rows'] as $row) {
            $id = $row['cells'][0];
            if ($id !== '') $seen[$id] = ($seen[$id] ?? 0) + 1;
        }
        $rows = [];
        foreach ($parsed['rows'] as $row) {
            $source = array_combine(self::HEADERS, $row['cells']);
            $rows[] = ['line' => $row['line'], 'source' => $source, ...$this->normalize($source, $cutoff, ($seen[$source['project_id']] ?? 0) > 1)];
        }
        return ['bytes' => $parsed['bytes'], 'sha256' => $parsed['sha256'], 'byte_count' => $parsed['byte_count'], 'rows' => $rows];
    }

    private function normalize(#[\SensitiveParameter] array $s, CarbonImmutable $cutoff, bool $duplicate): array
    {
        $issues = [];
        foreach (['project_id', 'event_date'] as $field) if (trim($s[$field]) === '') $issues[] = $field.'_required';
        if ($duplicate) $issues[] = 'duplicate_project_id';
        $date = $this->date($s['event_date']);
        if (!$date) $issues[] = 'event_date_invalid';
        $end = $s['end_date'] === '' ? $date : $this->date($s['end_date']);
        if (!$end) $issues[] = 'end_date_invalid';
        $hasTime = $s['start_time'] !== '' || $s['end_time'] !== '';
        $startAt = $endAt = null;
        if ($hasTime) {
            $startAt = $this->instant($date, $s['start_time'], 'start', $issues);
            $endAt = $this->instant($end, $s['end_time'], 'end', $issues);
            if ($startAt && $endAt && $endAt <= $startAt) $issues[] = 'end_not_after_start';
        } elseif ($s['end_date'] !== '' && $date && $end && $end < $date) $issues[] = 'end_not_after_start';
        $future = false;
        if ($date) {
            $today = $cutoff->setTimezone('America/Chicago')->toDateString();
            $future = $date > $today || ($end && $end > $today) || ($endAt && $endAt > $cutoff)
                || (($date === $today || $end === $today) && !$endAt);
            if ($future) $issues[] = 'not_safely_past';
        }
        if (trim($s['client_name']) === '') $issues[] = 'client_name_missing';
        $email = trim($s['client_email']);
        if ($email === '') $issues[] = 'email_missing';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $issues[] = 'email_invalid';
        // No status mapping is applied without a later explicit owner decision.
        $issues[] = 'source_status_review';
        $type = in_array($s['event_type'], ['birthday', 'baby_shower', 'gender_reveal', 'corporate', 'other', 'unknown'], true) ? $s['event_type'] : 'unknown';
        if ($type === 'unknown') $issues[] = 'event_type_review';
        $currency = $s['currency'] === '' ? null : $s['currency'];
        $amount = null;
        if ($s['booked_total'] !== '') {
            if ($currency !== 'USD') $issues[] = 'non_usd_or_unknown_currency';
            elseif (!preg_match('/^(0|[1-9][0-9]{0,8})\.[0-9]{2}$/D', $s['booked_total'])) $issues[] = 'booked_total_invalid';
            else $amount = (int)str_replace('.', '', $s['booked_total']);
        }
        return ['issue_codes' => array_values(array_unique($issues)), 'normalized' => [
            'source_date' => $date, 'end_date' => $end, 'time_precision' => $hasTime ? 'time' : 'date',
            'timezone' => 'America/Chicago', 'start_at' => $startAt?->toIso8601String(), 'end_at' => $endAt?->toIso8601String(),
            'booked_total_cents' => $amount, 'currency' => $currency, 'status' => 'archived_unknown',
            'event_type' => $type, 'not_safely_past' => $future,
        ]];
    }

    private function date(string $date): ?string
    {
        if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
        return $date;
    }

    private function instant(?string $date, string $time, string $field, array &$issues): ?CarbonImmutable
    {
        if (!$date || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) { $issues[] = $field.'_time_invalid'; return null; }
        $wall = CarbonImmutable::parse($date.' '.$time, 'UTC');
        $zone = new \DateTimeZone('America/Chicago');
        $candidates = [];
        foreach ($zone->getTransitions($wall->timestamp - 172800, $wall->timestamp + 172800) as $transition) {
            $utc = $wall->subSeconds($transition['offset']);
            if ($utc->setTimezone($zone)->format('Y-m-d H:i') === $date.' '.$time) $candidates[$utc->timestamp] = $utc;
        }
        if (count($candidates) !== 1) { $issues[] = $field.(count($candidates) === 0 ? '_time_nonexistent' : '_time_ambiguous'); return null; }
        return array_values($candidates)[0];
    }
}
