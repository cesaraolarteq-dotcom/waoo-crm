<?php
namespace App\Services\History;

use App\Exceptions\RecordWriteRejected;
use App\Models\{HistoryImportBatch, HistoryImportRow, User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Imports\{ImportInput, ImportPersistence};
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

final class StageHistoricalImport
{
    public function execute(User $actor, UploadedFile $file, string $key): array
    {
        $actor = HistoryAccess::actor($actor);
        $cutoff = CarbonImmutable::now('UTC')->startOfSecond();
        $source = app(HistoricalSource::class)->upload($file, $cutoff);
        $scope = 'owner:'.$actor->id.':history:stage';
        $hash = MutationKey::hash(['profile' => HistoricalSource::PROFILE, 'source_sha256' => $source['sha256']]);
        return ImportPersistence::transaction(function () use ($actor, $source, $cutoff, $scope, $key, $hash) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            HistoryAccess::actor($actor);
            ImportInput::lock();
            $receipt = MutationKey::find($scope, $key, $hash, true);
            $batch = $receipt ? HistoryImportBatch::whereKey($receipt->result_id)->lockForUpdate()->firstOrFail()
                : HistoryImportBatch::where('active_fingerprint', $source['sha256'])->lockForUpdate()->first();
            if ($batch) {
                HistoryEnvelope::batch($batch);
                RecordWriteRejected::unless($batch->state !== 'discarded', 410, 'Este lote de historial fue descartado.');
            } else {
                $batch = HistoryImportBatch::create(['created_by' => $actor->id, 'version' => 1, 'state' => 'reviewing',
                    'profile' => HistoricalSource::PROFILE, 'active_fingerprint' => $source['sha256'], 'source_sha256' => $source['sha256'],
                    'byte_count' => $source['byte_count'], 'coverage_sha256' => str_repeat('0', 64), 'manifest' => [], 'cutoff' => $cutoff]);
                $batch->artifact = ['batch_id' => $batch->id, 'bytes' => $source['bytes']];
                $counts = array_fill_keys(['rows', 'new', 'unchanged', 'changed', 'ambiguous', 'invalid', 'future', 'operating_linked', 'excluded', 'imported', 'missing_email', 'cancelled'], 0);
                $bindings = [];
                foreach ($source['rows'] as $s) {
                    $row = HistoryImportRow::create(['history_import_batch_id' => $batch->id, 'source_line' => $s['line'],
                        'version' => 1, 'preview_sha256' => str_repeat('0', 64), 'payload' => []]);
                    $p = ['id' => $row->id, 'batch_id' => $batch->id, 'version' => 1, ...$s];
                    $row->payload = $p;
                    $row->preview_sha256 = MutationKey::hash($p);
                    $row->save();
                    $bindings[(string)$row->id] = $row->preview_sha256;
                    $counts['rows']++;
                    if ($s['normalized']['not_safely_past']) $counts['future']++;
                    if (in_array('email_missing', $s['issue_codes'], true)) $counts['missing_email']++;
                    if ($s['source']['project_status'] === 'cancelled') $counts['cancelled']++;
                    if (array_filter($s['issue_codes'], fn ($c) => str_ends_with($c, '_invalid') || str_ends_with($c, '_required')
                        || in_array($c, ['duplicate_project_id', 'end_not_after_start', 'start_time_nonexistent', 'end_time_nonexistent'], true))) $counts['invalid']++;
                }
                $manifest = ['batch_id' => $batch->id, 'created_by' => $actor->id, 'version' => 1, 'state' => $batch->state,
                    'profile' => $batch->profile, 'source_sha256' => $source['sha256'], 'byte_count' => $source['byte_count'],
                    'cutoff' => $cutoff->toIso8601String(), 'counts' => $counts, 'rows' => $bindings];
                $batch->manifest = $manifest;
                $batch->coverage_sha256 = MutationKey::hash($manifest);
                $batch->save();
                Audit::recordFor($actor, 'history.staged', $batch, [], ['version' => 1, 'counts' => $counts]);
            }
            if (!$receipt) MutationKey::store($scope, $key, $hash, 'history_import_batch', $batch->id);
            return HistoryEnvelope::metadata($batch);
        });
    }
}
