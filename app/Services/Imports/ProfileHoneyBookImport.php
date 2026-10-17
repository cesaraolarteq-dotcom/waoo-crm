<?php
namespace App\Services\Imports;
use App\Exceptions\RecordWriteRejected;
use App\Models\{ImportBatch,ImportFile,User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use Illuminate\Support\Facades\DB;
final class ProfileHoneyBookImport
{
    public function execute(User $actor,int $batchId,#[\SensitiveParameter] array $input,string $key):ImportBatch
    {
        $actor=ImportInput::actor($actor);$data=ImportInput::input($input,['expected_version'=>'required|integer|min:1','expected_preview_sha256'=>'required|string|regex:/^[a-f0-9]{64}$/D','profile'=>'required|array']);
        $data['expected_version']=(int)$data['expected_version'];$profile=ImportInput::profile($data['profile']);$data['profile']=$profile;$hash=MutationKey::hash($data);$scope="owner:{$actor->id}:import:{$batchId}:profile";
        return ImportPersistence::transaction(function()use($actor,$batchId,$data,$profile,$hash,$scope,$key){
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();ImportInput::actor($actor);ImportInput::lock();$batch=ImportBatch::whereKey($batchId)->lockForUpdate()->firstOrFail();
            if($receipt=MutationKey::find($scope,$key,$hash))return ImportReceipt::replay($receipt);
            RecordWriteRejected::unless(in_array($batch->status,['prepared','needs_review'],true)&&$batch->version===$data['expected_version']&&hash_equals($batch->preview_sha256,$data['expected_preview_sha256']),409,'La revisión de la importación cambió o fue descartada.');
            $files=[];
            foreach(ImportFile::where('import_batch_id',$batchId)->orderBy('role')->get() as $file){$bytes=app(ImportArtifacts::class)->read($file);$files[$file->role]=['sha256'=>$file->sha256,...app(ImportCsv::class)->parse($bytes,$file->role,$profile)];$file->headers=$files[$file->role]['headers'];$file->save();}
            $fingerprint=PersistImportPreview::fingerprint($files,$profile);
            RecordWriteRejected::unless(!ImportBatch::where('active_fingerprint',$fingerprint)->where('id','!=',$batchId)->lockForUpdate()->first(),409,'Este mismo archivo y perfil ya tienen una revisión activa.');
            $batch->version++;PersistImportPreview::execute($batch,$actor,$files,$profile);
            Audit::recordFor($actor,'import.profiled',$batch,[],['version'=>$batch->version,'profile_sha256'=>$batch->profile_sha256,'preview_sha256'=>$batch->preview_sha256,'counts'=>$batch->reconciliation['counts']]);
            return ImportReceipt::store($batch,$scope,$key,$hash);
        },3);
    }
}
