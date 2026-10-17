<?php
namespace App\Services\Imports;
use App\Exceptions\RecordWriteRejected;
use App\Models\{ImportBatch,ImportFile,User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
final class FinalizeHoneyBookImport
{
    public function execute(User $actor,int $batchId,array $input,string $key):ImportBatch
    {
        $actor=ImportInput::actor($actor);$d=ImportInput::input($input,['expected_version'=>'required|integer|min:1','source_coverage_reviewed'=>'required|accepted']);$d['expected_version']=(int)$d['expected_version'];$d['source_coverage_reviewed']=true;$hash=MutationKey::hash($d);$scope="owner:{$actor->id}:import:{$batchId}:finalize";
        ImportPersistence::transaction(function()use($actor,$batchId,$d,$hash,$scope,$key){
            ImportInput::lock();$b=ImportBatch::whereKey($batchId)->lockForUpdate()->firstOrFail();ImportInput::actor($actor);
            if(MutationKey::find($scope,$key,$hash))return;
            RecordWriteRejected::unless($b->version===$d['expected_version']&&in_array($b->status,['prepared','needs_review','partially_committed','imported_cleanup_pending','imported'],true),409,'El lote cambió o fue descartado.');
            $coverage=app(ReadImportCoverage::class)->execute($actor,$batchId);RecordWriteRejected::unless($coverage['domain_complete'],409,'Quedan filas sin importar o con diferencias.');
            if(!in_array($b->status,['imported_cleanup_pending','imported'],true)){$b->forceFill(['status'=>'imported_cleanup_pending','version'=>$b->version+1,'active_fingerprint'=>null])->save();Audit::recordFor($actor,'import.source_cleanup_requested',$b,[],['counts'=>$coverage['counts']]);}
            MutationKey::store($scope,$key,$hash,'import_batch',$batchId);
        },3);
        return ImportPersistence::transaction(function()use($actor,$batchId){$b=ImportBatch::whereKey($batchId)->lockForUpdate()->firstOrFail();ImportInput::actor($actor);if($b->status!=='imported_cleanup_pending')return $b;
            $done=true;foreach(ImportFile::where('import_batch_id',$batchId)->get() as $file)if(!app(ImportArtifacts::class)->delete($file->private_path))$done=false;
            if($done){ImportFile::where('import_batch_id',$batchId)->update(['original_name'=>null,'headers'=>null]);$b->forceFill(['status'=>'imported','version'=>$b->version+1])->save();Audit::recordFor($actor,'import.source_deleted',$b,[],['version'=>$b->version]);}return $b;},3);
    }
}
