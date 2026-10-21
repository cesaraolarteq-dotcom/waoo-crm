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

    public const OPTIONAL = ['celebrant_name','paid_total','balance','sales_tax','payments','line_items','contract_signed','celebrant_age','children_count','setup_area','event_language','special_requests','contact_address','private_notes','sms_consent_honeybook','lead_source_text','created_at'];

    public function upload(UploadedFile $file, CarbonImmutable $cutoff): array
    {
        $parsed = app(ImportCsv::class)->upload($file, 'projects', ['encoding' => 'utf-8', 'delimiter' => ',', 'columns' => []]);
        if (array_slice($parsed['headers'], 0, 19) !== self::HEADERS || count(array_unique($parsed['headers'])) !== count($parsed['headers']) || array_diff(array_slice($parsed['headers'], 19), self::OPTIONAL)) ImportInput::fail('file', 'exact_19_history_headers_required');
        if ($parsed['rows'] === []) ImportInput::fail('file', 'history_rows_required');
        $seen = [];
        foreach ($parsed['rows'] as $row) {
            $id = $row['cells'][0];
            if ($id !== '') $seen[$id] = ($seen[$id] ?? 0) + 1;
        }
        $rows = [];
        foreach ($parsed['rows'] as $row) {
            $source = array_combine($parsed['headers'], $row['cells']);
            foreach (['contact_address','private_notes','sales_tax'] as $excluded) if (($source[$excluded] ?? '') !== '') ImportInput::fail('file', 'owner_excluded_field_nonempty');
            $rows[] = ['line' => $row['line'], 'source' => $source, ...$this->normalize($source, $cutoff, ($seen[$source['project_id']] ?? 0) > 1)];
        }
        return ['bytes' => $parsed['bytes'], 'sha256' => $parsed['sha256'], 'byte_count' => $parsed['byte_count'], 'rows' => $rows];
    }

    private function normalize(#[\SensitiveParameter] array $s, CarbonImmutable $cutoff, bool $duplicate): array
    {
        $issues = [];
        $prospect = $s['event_date'] === '' && in_array(strtolower(trim($s['project_status'])), ['lead','inquiry','prospect'], true);
        foreach ($prospect ? ['project_id'] : ['project_id', 'event_date'] as $field) if (trim($s[$field]) === '') $issues[] = $field.'_required';
        if ($duplicate) $issues[] = 'duplicate_project_id';
        $date = $this->date($s['event_date']);
        if (!$date && !$prospect) $issues[] = 'event_date_invalid';
        $end = $s['end_date'] === '' ? $date : $this->date($s['end_date']);
        if (!$end && !$prospect) $issues[] = 'end_date_invalid';
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
        $status = match (strtolower(trim($s['project_status']))) { 'completed' => 'completed', 'cancelled','canceled' => 'cancelled', default => 'archived_unknown' };
        if ($status === 'archived_unknown') $issues[] = 'source_status_review';
        $type = in_array($s['event_type'], ['birthday', 'baby_shower', 'gender_reveal', 'corporate', 'other', 'unknown'], true) ? $s['event_type'] : 'unknown';
        if ($type === 'unknown') $issues[] = 'event_type_review';
        $currency = $s['currency'] === '' ? null : $s['currency'];
        $amount = null;
        if ($s['booked_total'] !== '') {
            if ($currency !== 'USD') $issues[] = 'non_usd_or_unknown_currency';
            elseif (!preg_match('/^(0|[1-9][0-9]{0,8})\.[0-9]{2}$/D', $s['booked_total'])) $issues[] = 'booked_total_invalid';
            else $amount = (int)str_replace('.', '', $s['booked_total']);
        }
        $extra = $this->extra($s, $issues);
        return ['issue_codes' => array_values(array_unique($issues)), 'normalized' => [
            'source_date' => $date, 'end_date' => $end, 'time_precision' => $hasTime ? 'time' : 'date',
            'timezone' => 'America/Chicago', 'start_at' => $startAt?->toIso8601String(), 'end_at' => $endAt?->toIso8601String(),
            'booked_total_cents' => $amount, 'currency' => $currency, 'status' => $status, 'record_kind' => $prospect ? 'prospect' : 'event',
            ...$extra,
            'event_type' => $type, 'not_safely_past' => $future,
        ]];
    }

    private function extra(array $source, array &$issues): array
    {
        $facts = [];
        foreach (['paid_total','balance','sales_tax'] as $field) {
            $raw = $source[$field] ?? ''; $value = null;
            if ($raw !== '' && ($source['currency'] ?? '') === 'USD' && preg_match('/^-?(0|[1-9][0-9]{0,8})\.[0-9]{2}$/D', $raw)) $value = (int) str_replace('.', '', $raw);
            elseif ($raw !== '') $issues[] = $field.'_unparsed';
            $facts[$field.'_cents'] = $value;
        }
        // Original strings are the authoritative source evidence, including unusual formats.
        foreach (['payments','line_items'] as $field) {
            $facts[$field] = [];
            foreach (($source[$field] ?? '') === '' ? [] : explode(';', $source[$field]) as $entry) {
                $parts = explode('|', $entry); $expected = $field === 'payments' ? 3 : 2;
                if (count($parts) !== $expected) { $issues[] = $field.'_unparsed'; continue; }
                $amount = $parts[$field === 'payments' ? 1 : 1];
                if (!preg_match('/^-?(0|[1-9][0-9]{0,8})\.[0-9]{2}$/D', $amount)) { $issues[] = $field.'_unparsed'; continue; }
                $facts[$field][] = $field === 'payments'
                    ? ['date' => $parts[0], 'amount_text' => $amount, 'method' => $parts[2]]
                    : ['title' => $parts[0], 'amount_text' => $amount];
            }
        }
        return $facts;
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
