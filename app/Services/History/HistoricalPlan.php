<?php
namespace App\Services\History;

use App\Filament\Support\ContactLinks;
use App\Models\{HistoricalEvent, HistoricalEventRevision, HistoryImportBatch, HistoryImportRow, ImportedProjectLink};
use App\Services\Finance\MutationKey;

/** Caller holds the shared import lock. Pure planning: never creates customers or decisions. */
final class HistoricalPlan
{
    public static function build(HistoryImportBatch $batch, array $manifest): array
    {
        $customers = HistoricalCustomerMatch::customers();
        $rows = HistoryImportRow::where('history_import_batch_id', $batch->id)->orderBy('id')->get();
        $payloads = []; $contacts = [];
        foreach ($rows as $row) {
            $p = HistoryEnvelope::row($row, $manifest); $payloads[$row->id] = $p;
            $s = $p['source'];
            if ($s['contact_id'] !== '') {
                foreach (['client_email','client_phone'] as $field) {
                    $value = $field === 'client_email' ? self::email($s[$field]) : ContactLinks::digits($s[$field]);
                    if ($value !== null) $contacts[$s['contact_id']][$field][$value] = true;
                }
            }
        }
        $items = []; $eligible = $review = $imported = 0;
        foreach ($rows as $row) {
            $p = $payloads[$row->id]; $s = $p['source']; $n = $p['normalized'];
            $match = HistoricalCustomerMatch::read($s, $customers); $issues = [];
            if (isset($p['decision'])) {
                $items[] = ['row_id' => $row->id, 'state' => $p['decision']['state'], 'reasons' => [], 'preview_sha256' => $row->preview_sha256];
                if (in_array($p['decision']['state'], ['imported','unchanged'], true)) $imported++;
                continue;
            }
            foreach ($p['issue_codes'] as $code) if (in_array($code, self::BLOCKING, true)) $issues[] = $code;
            if (ImportedProjectLink::where('project_key', hash('sha256', $s['project_id']))->lockForUpdate()->exists()) $issues[] = 'operating_project_exists';
            if (in_array($match['state'], ['ambiguous','conflicting'], true)) $issues[] = 'customer_'.$match['state'];
            foreach ($contacts[$s['contact_id']] ?? [] as $values) if (count($values) > 1) $issues[] = 'source_contact_conflict';
            $history = HistoricalEvent::where('project_key', hash('sha256', $s['project_id']))->lockForUpdate()->first();
            if ($history) {
                $revision = HistoricalEventRevision::whereKey($history->current_revision_id)->lockForUpdate()->firstOrFail();
                HistoricalIntegrity::revision($history, $revision);
                if ($revision->source_sha256 !== MutationKey::hash($s) || $revision->facts_sha256 !== MutationKey::hash($n)
                    || $match['candidate_count'] !== 1 || $match['candidates'][0]['id'] !== $history->customer_id) $issues[] = 'source_changed';
            }
            $customer = null;
            if ($match['candidate_count'] === 1 && !in_array($match['state'], ['ambiguous','conflicting'], true)) {
                $candidate = $match['candidates'][0];
                $customer = ['mode' => 'link', 'id' => $candidate['id'], 'expected_version' => $candidate['version']];
            } elseif ($match['candidate_count'] === 0) {
                if (trim($s['client_name']) === '' || mb_strlen($s['client_name']) > 255) $issues[] = 'customer_identity_required';
                $customer = ['mode' => 'create', 'name' => trim($s['client_name']),
                    'email' => self::email($s['client_email']) !== null && mb_strlen($s['client_email']) <= 255 ? trim($s['client_email']) : null,
                    'phone' => ContactLinks::digits($s['client_phone']) !== null ? $s['client_phone'] : null];
            }
            $issues = array_values(array_unique($issues));
            $state = $issues ? 'needs_review' : 'eligible';
            $state === 'eligible' ? $eligible++ : $review++;
            $items[] = ['row_id' => $row->id, 'version' => $row->version, 'preview_sha256' => $row->preview_sha256,
                'state' => $state, 'reasons' => $issues, 'match_sha256' => $match['expected_match_sha256'], 'customer' => $customer,
                'status' => $n['status'], 'event_type' => $n['event_type']];
        }
        $hash = MutationKey::hash(['version' => 1, 'batch_id' => $batch->id, 'items' => $items]);
        return ['items' => $items, 'summary' => ['plan_sha256' => $hash, 'eligible' => $eligible, 'review_required' => $review, 'imported' => $imported],
            'rows' => array_map(fn ($i) => ['id' => $i['row_id'], 'state' => $i['state'], 'reason_codes' => $i['reasons']], $items)];
    }
    public const BLOCKING = ['project_id_required','event_date_required','event_date_invalid','end_date_invalid','duplicate_project_id',
        'start_time_invalid','end_time_invalid','start_time_nonexistent','end_time_nonexistent','start_time_ambiguous','end_time_ambiguous','end_not_after_start','not_safely_past'];
    private static function email(string $value): ?string {
        $value = trim($value); return filter_var($value, FILTER_VALIDATE_EMAIL) ? mb_strtolower($value) : null;
    }
}
