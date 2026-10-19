<?php
namespace App\Services\History;

use App\Models\{HistoryImportRow, User};
use App\Services\Imports\{ImportInput, ImportPersistence};

final class ReadHistoricalRow
{
    public function execute(User $actor, int $batchId, int $rowId): array
    {
        HistoryAccess::actor($actor);
        return ImportPersistence::transaction(function () use ($actor, $batchId, $rowId) {
            ImportInput::lock();
            HistoryAccess::actor($actor);
            $batch = ReadHistoricalImport::batch($batchId);
            $m = HistoryEnvelope::batch($batch);
            $row = HistoryImportRow::where('history_import_batch_id', $batchId)->whereKey($rowId)->firstOrFail();
            $p = HistoryEnvelope::row($row, $m);
            return [...ReadHistoricalImport::summary($row, $p), 'source' => $p['source'], 'normalized' => $p['normalized'], 'commit_available' => false];
        });
    }
}
