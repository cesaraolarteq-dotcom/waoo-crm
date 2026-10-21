<?php
namespace App\Services\History;

use App\Exceptions\RecordWriteRejected;
use App\Models\{HistoryImportBatch, HistoryImportRow, User};
use App\Services\Imports\{ImportInput, ImportPersistence};

final class ReadHistoricalImport
{
    public function execute(User $actor, int $batchId, array $filters = []): array
    {
        HistoryAccess::actor($actor);
        $d = ImportInput::input($filters, ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50']);
        return ImportPersistence::transaction(function () use ($actor, $batchId, $d) {
            ImportInput::lock();
            HistoryAccess::actor($actor);
            $batch = self::batch($batchId);
            $m = HistoryEnvelope::batch($batch);
            $total = $m['counts']['rows'];
            $size = (int)($d['per_page'] ?? 25);
            $last = max(1, (int)ceil($total / $size));
            $page = min((int)($d['page'] ?? 1), $last);
            $rows = HistoryImportRow::where('history_import_batch_id', $batchId)->orderBy('id')->offset(($page - 1) * $size)->limit($size)->get();
            $plan = HistoricalPlan::build($batch, $m);
            return ['batch' => ['id' => $batch->id, 'version' => $batch->version, 'state' => $batch->state,
                'profile' => $batch->profile, 'cutoff' => $m['cutoff']], 'counts' => $m['counts'],
                'coverage_sha256' => $batch->coverage_sha256, 'page' => ['number' => $page, 'last' => $last, 'total' => $total],
                'rows' => $rows->map(fn ($row) => self::summary($row, HistoryEnvelope::row($row, $m)))->all(),
                'bulk' => $plan['summary'], 'review_rows' => array_values(array_filter($plan['rows'], fn ($i) => $i['state'] === 'needs_review')),
                'commit_available' => $batch->state === 'reviewing' && (bool)config('waoo.sandbox_history')];
        });
    }

    public static function batch(int $id): HistoryImportBatch
    {
        $b = HistoryImportBatch::whereKey($id)->lockForUpdate()->firstOrFail();
        RecordWriteRejected::unless(in_array($b->state, ['reviewing','closed'], true), 410, 'Este lote de historial fue descartado.');
        return $b;
    }

    public static function summary(HistoryImportRow $row, array $p): array
    {
        return ['id' => $row->id, 'version' => $row->version, 'state' => $p['decision']['state'] ?? 'needs_review', 'issue_codes' => $p['issue_codes'],
            'preview_sha256' => $row->preview_sha256, 'source_date' => $p['normalized']['source_date'],
            'status' => $p['normalized']['status'], 'event_type' => $p['normalized']['event_type'],
            'customer_match' => ['state' => 'not_evaluated', 'candidate_count' => null]];
    }
}
