<?php
namespace App\Services\Imports;
use App\Models\{ImportBatch,ImportFile,User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use Illuminate\Support\Facades\DB;

final class StageHoneyBookImport
{
    public function execute(User $actor,#[\SensitiveParameter] array $input,string $key):ImportBatch
    {
        $actor=ImportInput::actor($actor);
        $data=ImportInput::input($input,['files'=>'required|array|min:1|max:3','profile'=>'required|array']);$profile=ImportInput::profile($data['profile']);
        $files=[];
        foreach($data['files'] as $item){
            if(!is_array($item))ImportInput::fail('files','invalid_file');
            $item=ImportInput::input($item,['role'=>'required|in:projects,customers,payments','file'=>'required|file']);$role=$item['role'];
            if(isset($files[$role])||!$item['file'] instanceof \Illuminate\Http\UploadedFile)ImportInput::fail('files','invalid_or_duplicate_role');
            $files[$role]=app(ImportCsv::class)->upload($item['file'],$role,$profile);
        }
        if(!isset($files['projects']))ImportInput::fail('files','projects_required');ksort($files);
        $fingerprint=PersistImportPreview::fingerprint($files,$profile);$hash=MutationKey::hash(['fingerprint'=>$fingerprint,'names'=>array_map(fn($f)=>$f['name'],$files)]);$scope="owner:{$actor->id}:import:stage";
        return ImportPersistence::transaction(function()use($actor,$files,$profile,$fingerprint,$hash,$scope,$key){
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();ImportInput::actor($actor);ImportInput::lock();
            if($receipt=MutationKey::find($scope,$key,$hash))return ImportReceipt::replay($receipt);
            $batch=ImportBatch::where('active_fingerprint',$fingerprint)->lockForUpdate()->first();
            if(!$batch){
                $batch=ImportBatch::create(['created_by'=>$actor->id,'version'=>1,'status'=>'needs_review','profile'=>$profile,'profile_sha256'=>MutationKey::hash($profile),
                    'preview_sha256'=>str_repeat('0',64),'reconciliation'=>[],'received_at'=>now()]);$directory='honeybook/'.bin2hex(random_bytes(16));
                foreach($files as $role=>$file){$path=$directory.'/'.$role.'.csv';app(ImportArtifacts::class)->put($path,$file['bytes']);
                    ImportFile::create(['import_batch_id'=>$batch->id,'role'=>$role,'original_name'=>$file['name'],'private_path'=>$path,'sha256'=>$file['sha256'],
                        'byte_count'=>$file['byte_count'],'headers'=>$file['headers']]);}
                PersistImportPreview::execute($batch,$actor,$files,$profile);
                Audit::recordFor($actor,'import.staged',$batch,[],['profile_sha256'=>$batch->profile_sha256,'preview_sha256'=>$batch->preview_sha256,'counts'=>$batch->reconciliation['counts']]);
            }
            return ImportReceipt::store($batch,$scope,$key,$hash);
        },3);
    }
}
