<?php
namespace App\Services\Imports;

use App\Exceptions\RecordWriteRejected;
use App\Models\{ImportBatch, ImportedProjectRevision, ImportRow};
use App\Services\Finance\MutationKey;

/** Known staged gaps cannot disappear merely because only imported links form the operating cohort. */
final class CutoverSourceScope
{
    public function inspect($links): array
    {
        $batches = ImportBatch::whereNotIn('status', ['discarded', 'cleanup_pending'])->where('profile->source_format', 'honeybook_future_v1')
            ->orderBy('id')->limit(2001)->sharedLock()->get(['id', 'version', 'status']);
        RecordWriteRejected::unless($batches->count() <= 2000, 409, 'La cobertura de origen requiere una revisión por volumen.');
        $byKey = $links->keyBy('project_key');
        $historyRows = ImportedProjectRevision::whereIn('imported_project_link_id', $links->pluck('id'))->orderBy('id')->limit(10001)->sharedLock()
            ->get(['imported_project_link_id', 'source_sha256']);
        $rows = ImportRow::whereIn('import_batch_id', $batches->pluck('id'))->orderBy('id')->limit(10001)->sharedLock()
            ->get(['id', 'import_batch_id', 'version', 'status', 'source_key', 'preview_sha256', 'normalized->source_sha256 as source_sha256']);
        // Bound evidence memory and fetch only source hashes, never normalized customer cells.
        RecordWriteRejected::unless($rows->count() <= 10000 && $historyRows->count() <= 10000, 409, 'La cobertura de origen requiere una revisión por volumen.');
        $history = $historyRows->groupBy('imported_project_link_id');
        $missing = []; $proof = []; $pending = 0;
        foreach ($rows as $row) {
            $link = $byKey->get($row->source_key);
            $covered = $link && ($history->get($link->id)?->contains('source_sha256', $row->source_sha256 ?? '') ?? false);
            if (!$link && $row->status !== 'skipped') { $missing[] = 'source_scope_incomplete'; $pending++; }
            elseif ($link && !$covered) { $missing[] = 'source_scope_unresolved'; $pending++; }
            $proof[] = ['id' => $row->id, 'batch_id' => $row->import_batch_id, 'version' => $row->version, 'status' => $row->status,
                'preview_sha256' => $row->preview_sha256, 'source_sha256' => $row->source_sha256,
                'link_id' => $link?->id, 'covered' => (bool)$covered];
        }
        $payload = ['batches' => $batches->map->only(['id', 'version', 'status'])->values()->all(), 'rows' => $proof];
        return ['hash' => MutationKey::hash($payload), 'payload' => $payload, 'missing' => array_values(array_unique($missing)), 'pending_rows' => $pending];
    }
}
