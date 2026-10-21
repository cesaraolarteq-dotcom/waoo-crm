<?php
namespace App\Services\History;

use App\Exceptions\RecordWriteRejected;
use App\Models\{HistoryImportBatch, HistoryImportDecision, HistoryImportRow, User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Imports\{ImportInput, ImportPersistence};

final class DiscardHistoricalImport
{
    public function execute(User $actor, int $batchId, #[\SensitiveParameter] array $input, string $key): array
    {
        $actor = HistoryAccess::actor($actor);
        $d = ImportInput::input($input, ['expected_batch_version' => 'required|integer|min:1',
            'expected_coverage_sha256' => 'required|string|size:64|regex:/^[a-f0-9]+$/', 'owner_reason' => 'required|string|min:5|max:1000']);
        $hash = MutationKey::hash($d);
        $scope = 'owner:'.$actor->id.':history:discard:'.$batchId;
        return ImportPersistence::transaction(function () use ($actor, $batchId, $d, $key, $hash, $scope) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            ImportInput::lock();
            HistoryAccess::actor($actor);
            $receipt = MutationKey::find($scope, $key, $hash, true);
            $batch = HistoryImportBatch::whereKey($batchId)->lockForUpdate()->firstOrFail();
            $m = HistoryEnvelope::batch($batch);
            if (!$receipt) {
                RecordWriteRejected::unless(!HistoryImportDecision::where('history_import_batch_id', $batchId)->lockForUpdate()->first(),
                    409, 'Este lote ya tiene historial guardado. Requiere el cierre revisado, no descarte.');
                RecordWriteRejected::unless($batch->state === 'reviewing' && $batch->version === (int)$d['expected_batch_version']
                    && hash_equals($batch->coverage_sha256, $d['expected_coverage_sha256']), 409, 'El lote cambió. Revisa antes de descartarlo.');
                HistoryImportRow::where('history_import_batch_id', $batchId)->delete();
                $batch->artifact = null;
                $batch->active_fingerprint = null;
                $batch->state = 'discarded';
                $batch->version++;
                $m['state'] = $batch->state;
                $m['version'] = $batch->version;
                $m['owner_reason'] = $d['owner_reason'];
                $m['rows'] = [];
                $batch->manifest = $m;
                $batch->coverage_sha256 = MutationKey::hash($m);
                $batch->save();
                HistoryEnvelope::batch($batch->fresh());
                Audit::recordFor($actor, 'history.discarded', $batch, [], ['version' => $batch->version, 'state' => $batch->state]);
                MutationKey::store($scope, $key, $hash, 'history_import_batch', $batchId);
            }
            return ['batch_id' => $batch->id, 'version' => $batch->version, 'state' => $batch->state];
        });
    }
}
