<?php
namespace App\Services\History;
use App\Exceptions\RecordWriteRejected;
use App\Models\{HistoryImportDecision,HistoryImportRow,User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Imports\{ImportInput,ImportPersistence};
final class ExcludeHistoricalRow
{
    public function execute(User $actor,int $batchId,int $rowId,#[\SensitiveParameter] array $input,string $key):array
    {
        HistoryAccess::actor($actor);
        $d=ImportInput::input($input,['expected_batch_version'=>'required|integer|min:1','expected_row_version'=>'required|integer|min:1',
            'expected_preview_sha256'=>'required|string|regex:/^[a-f0-9]{64}$/D','owner_reason'=>'required|string|min:5|max:1000']);
        foreach(['expected_batch_version','expected_row_version'] as $f)$d[$f]=(int)$d[$f];
        $hash=MutationKey::hash($d);$scope='owner:'.$actor->id.':history:'.$batchId.':row:'.$rowId.':exclude';
        return ImportPersistence::transaction(function()use($actor,$batchId,$rowId,$d,$key,$hash,$scope){
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();ImportInput::lock();HistoryAccess::actor($actor);
            if($receipt=MutationKey::find($scope,$key,$hash,true))return HistoricalIntegrity::decision(HistoryImportDecision::findOrFail($receipt->result_id))['result'];
            $batch=ReadHistoricalImport::batch($batchId);$m=HistoryEnvelope::batch($batch);
            $row=HistoryImportRow::where('history_import_batch_id',$batchId)->whereKey($rowId)->lockForUpdate()->firstOrFail();$p=HistoryEnvelope::row($row,$m);
            RecordWriteRejected::unless($batch->state==='reviewing'&&!isset($p['decision'])&&$batch->version===$d['expected_batch_version']&&$row->version===$d['expected_row_version']
                &&hash_equals($row->preview_sha256,$d['expected_preview_sha256']),409,'La fila cambió o ya tiene una decisión.');
            $batch->version++;$row->version++;
            $result=['state'=>'excluded','batch_id'=>$batchId,'row_id'=>$rowId,'batch_version'=>$batch->version,'row_version'=>$row->version];
            $payload=['batch_id'=>$batchId,'row_id'=>$rowId,'line'=>$row->source_line,'created_by'=>$actor->id,'input'=>$d,'result'=>$result];
            $decision=HistoryImportDecision::create(['history_import_batch_id'=>$batchId,'staged_row_id'=>$rowId,'source_line'=>$row->source_line,
                'request_sha256'=>$hash,'payload'=>$payload,'payload_sha256'=>MutationKey::hash($payload),'created_by'=>$actor->id]);
            $p['version']=$row->version;$p['decision']=['id'=>$decision->id,...$result];$row->payload=$p;$row->preview_sha256=MutationKey::hash($p);$row->save();
            $m['version']=$batch->version;$m['rows'][(string)$rowId]=$row->preview_sha256;$m['counts']['excluded']++;
            $batch->manifest=$m;$batch->coverage_sha256=MutationKey::hash($m);$batch->save();
            Audit::recordFor($actor,'history.row_excluded',$batch,[],['row_id'=>$rowId,'decision_id'=>$decision->id]);
            MutationKey::store($scope,$key,$hash,'history_import_decision',$decision->id);return $result;
        });
    }
}
