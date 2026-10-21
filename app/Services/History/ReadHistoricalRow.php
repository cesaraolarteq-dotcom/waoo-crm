<?php
namespace App\Services\History;

use App\Models\{HistoryImportRow, User};
use App\Services\Imports\{ImportInput, ImportPersistence};

final class ReadHistoricalRow
{
    public static function diffHash(\App\Models\HistoricalEventRevision $revision, array $source, array $facts): string {
        return \App\Services\Finance\MutationKey::hash(['version'=>1,'previous_revision_id'=>$revision->id,'previous_payload_sha256'=>$revision->payload_sha256,
            'source'=>$source,'facts'=>$facts]);
    }
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
            $previous=null;
            $history=\App\Models\HistoricalEvent::where('project_key',hash('sha256',$p['source']['project_id']))->lockForUpdate()->first();
            if($history){
                $revision=\App\Models\HistoricalEventRevision::findOrFail($history->current_revision_id);
                $old=HistoricalIntegrity::revision($history,$revision);
                $fields=[];foreach(array_unique([...array_keys($old['source']),...array_keys($p['source'])]) as $field)if(($old['source'][$field]??null)!==($p['source'][$field]??null))$fields[]=$field;
                $previous=['history_id'=>$history->id,'version'=>$history->version,'revision_id'=>$revision->id,'diff_sha256'=>self::diffHash($revision,$p['source'],$p['normalized']),'changed_fields'=>$fields];
            }
            $enabled = (bool)config('waoo.sandbox_history');
            return ['batch_version' => $batch->version, ...ReadHistoricalImport::summary($row, $p), 'previous' => $previous, 'source' => $p['source'], 'normalized' => $p['normalized'],
                ...($enabled ? ['customer_match' => HistoricalCustomerMatch::view(HistoricalCustomerMatch::read($p['source']))] : []),
                'commit_available' => $enabled && $batch->state === 'reviewing' && !isset($p['decision'])];
        });
    }
}
