<?php
namespace App\Services\History;
use App\Exceptions\RecordWriteRejected;
use App\Models\{HistoricalEvent,HoneyBookOriginal,ImportedProjectLink,User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Imports\{ImportInput,ImportPersistence};
use Illuminate\Http\UploadedFile;
final class PreserveHoneyBookOriginal
{
    public function execute(User $actor,UploadedFile $file,#[\SensitiveParameter] array $input,string $key):array {
        HistoryAccess::actor($actor);$d=ImportInput::input($input,['project_id'=>'required|string|max:200','kind'=>'required|in:contract,invoice','source_reference'=>'required|string|max:1000']);
        if(!$file->isValid()||strtolower($file->getClientOriginalExtension())!=='pdf')ImportInput::fail('file','pdf_required');
        $stream=@fopen($file->getRealPath(),'rb');if(!$stream)ImportInput::fail('file','unreadable_file');
        try{$bytes=stream_get_contents($stream,5242881);}finally{fclose($stream);}
        if(!is_string($bytes)||strlen($bytes)>5242880||!str_starts_with($bytes,'%PDF-')||!str_contains(substr($bytes,-2048),'%%EOF'))ImportInput::fail('file','invalid_or_oversized_pdf');
        $fileHash=hash('sha256',$bytes);$projectKey=hash('sha256',$d['project_id']);
        $identity=MutationKey::hash(['profile'=>'honeybook-original-v1','project_key'=>$projectKey,'kind'=>$d['kind'],'file_sha256'=>$fileHash]);
        $hash=MutationKey::hash([...$d,'file_sha256'=>$fileHash]);$scope='owner:'.$actor->id.':history:original';
        return ImportPersistence::transaction(function()use($actor,$d,$bytes,$fileHash,$projectKey,$identity,$hash,$scope,$key){
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();ImportInput::lock();HistoryAccess::actor($actor);
            if($r=MutationKey::find($scope,$key,$hash,true))return ReadHoneyBookOriginal::metadata(HoneyBookOriginal::findOrFail($r->result_id));
            RecordWriteRejected::unless(HistoricalEvent::where('project_key',$projectKey)->exists()||ImportedProjectLink::where('project_key',$projectKey)->exists(),409,'Primero importa el proyecto al historial o a las fiestas operativas.');
            $record=HoneyBookOriginal::where('identity_sha256',$identity)->first();
            if(!$record){
                $p=['profile'=>'honeybook-original-v1','identity_sha256'=>$identity,'project_id'=>$d['project_id'],'project_key'=>$projectKey,'kind'=>$d['kind'],
                    'source_reference'=>$d['source_reference'],'file_sha256'=>$fileHash,'byte_count'=>strlen($bytes),'bytes_base64'=>base64_encode($bytes),'created_by'=>$actor->id];
                $record=HoneyBookOriginal::create(['project_key'=>$projectKey,'identity_sha256'=>$identity,'payload'=>$p,'sha256'=>MutationKey::hash($p),'created_by'=>$actor->id]);
                Audit::recordFor($actor,'history.original_preserved',$record,[],['kind'=>$d['kind'],'byte_count'=>strlen($bytes)]);
            }
            MutationKey::store($scope,$key,$hash,'honeybook_original',$record->id);return ReadHoneyBookOriginal::metadata($record);
        });
    }
}
