<?php
namespace App\Services\Imports;

use App\Exceptions\RecordWriteRejected;
use App\Models\{CutoverOpeningAgreement, CutoverOpeningSet, CutoverReconciliation};
use App\Services\Finance\MutationKey;

final class OpeningSetIntegrity
{
    public static function load(CutoverOpeningSet $set): array
    {
        try {
            $p = $set->payload;
            self::check(is_array($p) && hash_equals($set->payload_sha256, MutationKey::hash($p))
                && $p['reconciliation_id'] === $set->cutover_reconciliation_id && $p['cohort_sha256'] === $set->cohort_sha256
                && $p['source_observed_at'] === $set->source_observed_at->toIso8601String() && $p['expires_at'] === $set->expires_at->toIso8601String()
                && $p['created_by'] === $set->created_by && $p['authority'] === 'preparation_only' && $p['effective_at'] === null
                && count($p['members']) > 0 && count($p['members']) <= 2000);
            $reconciliation = CutoverReconciliation::whereKey($set->cutover_reconciliation_id)->sharedLock()->firstOrFail();
            self::check(CutoverIntegrity::reconciliation($reconciliation) && $set->cohort_sha256 === $reconciliation->cohort_sha256
                && $set->source_observed_at->equalTo($reconciliation->source_observed_at) && $set->expires_at->equalTo($reconciliation->expires_at));
            $rows = CutoverOpeningAgreement::where('cutover_opening_set_id', $set->id)->orderBy('event_id')->sharedLock()->get();
            self::check(count($p['members']) === $rows->count());
            $facts = [];
            foreach ($rows as $row) {
                $body = $row->payload; $f = $body['facts'];
                self::check($body['set_id'] === $set->id && $row->cutover_opening_set_id === $set->id
                    && $body['reconciliation_id'] === $set->cutover_reconciliation_id && $row->event_id === $f['event_id']
                    && $row->imported_project_link_id === $f['imported_project_link_id'] && $row->imported_project_revision_id === $f['source_revision_id']
                    && $f['authority'] === 'preparation_only' && $f['effective_at'] === null && $f['origin'] === 'honeybook_opening'
                    && hash_equals($row->payload_sha256, MutationKey::hash($body)) && ($p['members'][(string)$row->event_id] ?? null) === MutationKey::hash($f));
                $facts[(string)$row->event_id] = $f;
            }
            ksort($facts); return ['manifest' => $p, 'rows' => $rows, 'facts' => $facts];
        } catch (RecordWriteRejected $e) { throw $e; }
        catch (\Throwable) { throw new RecordWriteRejected(409, 'La evidencia de apertura requiere revisión.'); }
    }
    private static function check(bool $valid): void { RecordWriteRejected::unless($valid, 409, 'La evidencia de apertura requiere revisión.'); }
    public static function metadata(CutoverOpeningSet $s, array $manifest): array
    {
        return ['opening_set_id' => $s->id, 'reconciliation_id' => $s->cutover_reconciliation_id, 'cohort_sha256' => $s->cohort_sha256,
            'event_count' => count($manifest['members']), 'source_observed_at' => $s->source_observed_at->toIso8601String(),
            'expires_at' => $s->expires_at->toIso8601String(), 'authority' => 'preparation_only', 'effective_at' => null,
            'transfer_available' => false, 'sending_enabled' => false];
    }
}
