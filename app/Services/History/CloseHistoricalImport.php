<?php
namespace App\Services\History;
use App\Exceptions\RecordWriteRejected;
use App\Models\{HistoryImportBatch,HistoryImportRow,User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Imports\{ImportInput,ImportPersistence};
final class CloseHistoricalImport
{
    public function execute(User $actor,int $batchId,array $input,string $key):array
    {
        HistoryAccess::actor($actor);
        $d=ImportInput::input($input,['expected_batch_version'=>'required|integer|min:1','expected_coverage_sha256'=>'required|string|regex:/^[a-f0-9]{64}$/D']);
        $d['expected_batch_version']=(int)$d['expected_batch_version'];$hash=MutationKey::hash($d);$scope='owner:'.$actor->id.':history:'.$batchId.':close';
        return ImportPersistence::transaction(function()use($actor,$batchId,$d,$key,$hash,$scope){
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();ImportInput::lock();HistoryAccess::actor($actor);
            $batch=HistoryImportBatch::whereKey($batchId)->lockForUpdate()->firstOrFail();$m=HistoryEnvelope::batch($batch);
            if(MutationKey::find($scope,$key,$hash,true))return $m['close_result'];
            RecordWriteRejected::unless($batch->state==='reviewing'&&$batch->version===$d['expected_batch_version']&&hash_equals($batch->coverage_sha256,$d['expected_coverage_sha256']),409,'El lote cambió. Revisa la cobertura antes de cerrarlo.');
            foreach(HistoryImportRow::where('history_import_batch_id',$batchId)->orderBy('id')->get() as $row){
                $p=HistoryEnvelope::row($row,$m);
                RecordWriteRejected::unless(in_array($p['decision']['state']??null,['imported','unchanged','excluded'],true),409,'Quedan filas pendientes de importar o excluir con motivo.');
            }
            $batch->state='closed';$batch->version++;$m['state']='closed';$m['version']=$batch->version;
            $result=['batch_id'=>$batchId,'version'=>$batch->version,'state'=>'closed','source_retained'=>true];$m['close_result']=$result;
            $batch->manifest=$m;$batch->coverage_sha256=MutationKey::hash($m);$batch->save();
            Audit::recordFor($actor,'history.closed',$batch,[],['version'=>$batch->version,'counts'=>$m['counts']]);
            MutationKey::store($scope,$key,$hash,'history_import_batch',$batchId);return $result;
        });
    }
}
