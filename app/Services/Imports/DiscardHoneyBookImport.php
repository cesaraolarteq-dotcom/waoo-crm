<?php
namespace App\Services\Imports;
use App\Exceptions\RecordWriteRejected;
use App\Models\{ImportBatch,ImportFile,ImportRow,User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use Illuminate\Support\Facades\DB;
final class DiscardHoneyBookImport
{
    public function execute(User $actor,int $batchId,#[\SensitiveParameter] array $input,string $key):ImportBatch
    {
        $actor=ImportInput::actor($actor);$data=ImportInput::input($input,['expected_version'=>'required|integer|min:1']);$data['expected_version']=(int)$data['expected_version'];
        $hash=MutationKey::hash($data);$scope="owner:{$actor->id}:import:{$batchId}:discard";
        ImportPersistence::transaction(function()use($actor,$batchId,$data,$hash,$scope,$key){
            $batch=ImportBatch::whereKey($batchId)->lockForUpdate()->firstOrFail();ImportInput::actor($actor);
            if(MutationKey::find($scope,$key,$hash))return;
            RecordWriteRejected::unless($batch->version===$data['expected_version']&&in_array($batch->status,['prepared','needs_review','cleanup_pending','discarded'],true),409,'La importación cambió. Recarga antes de descartar.');
            if(in_array($batch->status,['prepared','needs_review'],true)){
                ImportRow::where('import_batch_id',$batchId)->delete();ImportFile::where('import_batch_id',$batchId)->update(['original_name'=>null,'headers'=>null]);
                $batch->forceFill(['version'=>$batch->version+1,'status'=>'cleanup_pending','active_fingerprint'=>null,'profile'=>[],
                    'reconciliation'=>['counts'=>$batch->reconciliation['counts'],'domain_committed'=>false,'capacity_confirmed'=>false]])->save();
                Audit::recordFor($actor,'import.discard_requested',$batch,[],['version'=>$batch->version]);
            }
            MutationKey::store($scope,$key,$hash,'import_batch',$batchId);
        },3);
        return ImportPersistence::transaction(function()use($actor,$batchId){
            $batch=ImportBatch::whereKey($batchId)->lockForUpdate()->firstOrFail();ImportInput::actor($actor);
            if($batch->status!=='cleanup_pending')return $batch;
            $done=true;foreach(ImportFile::where('import_batch_id',$batchId)->get() as $file)if(!app(ImportArtifacts::class)->delete($file->private_path))$done=false;
            if($done){$batch->forceFill(['status'=>'discarded','version'=>$batch->version+1])->save();Audit::recordFor($actor,'import.discarded',$batch,[],['version'=>$batch->version]);}
            return $batch;
        },3);
    }
}
