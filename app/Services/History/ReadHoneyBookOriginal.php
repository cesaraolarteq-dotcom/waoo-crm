<?php
namespace App\Services\History;
use App\Exceptions\RecordWriteRejected;
use App\Models\{HoneyBookOriginal,User};
use App\Services\Finance\MutationKey;
final class ReadHoneyBookOriginal
{
    public function execute(User $actor,int $id):array {HistoryAccess::actor($actor);$r=HoneyBookOriginal::findOrFail($id);return ['metadata'=>self::metadata($r),'bytes'=>self::payload($r)['bytes']];}
    public static function metadata(HoneyBookOriginal $r):array {$p=self::payload($r);return ['id'=>$r->id,'kind'=>$p['kind'],'file_sha256'=>$p['file_sha256'],'byte_count'=>$p['byte_count'],'source'=>'honeybook','original'=>true,'native_signature_verified'=>false];}
    public static function listing(string $projectKey):array {
        return HoneyBookOriginal::where('project_key',$projectKey)->orderBy('id')->get()->map(fn($r)=>self::metadata($r))->all();
    }
    private static function payload(HoneyBookOriginal $r):array {
        try{$p=$r->payload;$bytes=base64_decode($p['bytes_base64'],true);
            $identity=MutationKey::hash(['profile'=>'honeybook-original-v1','project_key'=>$p['project_key'],'kind'=>$p['kind'],'file_sha256'=>$p['file_sha256']]);
            RecordWriteRejected::unless($p['profile']==='honeybook-original-v1'&&$p['created_by']===$r->created_by&&$p['project_key']===hash('sha256',$p['project_id'])
                &&$p['project_key']===$r->project_key&&$identity===$r->identity_sha256&&$p['identity_sha256']===$identity&&is_string($bytes)&&strlen($bytes)===$p['byte_count']
                &&hash_equals($p['file_sha256'],hash('sha256',$bytes))&&hash_equals($r->sha256,MutationKey::hash($p)),409,'El archivo original requiere revisión.');
            return [...$p,'bytes'=>$bytes];
        }catch(RecordWriteRejected $e){throw $e;}catch(\Throwable){throw new RecordWriteRejected(409,'El archivo original requiere revisión.');}
    }
}
