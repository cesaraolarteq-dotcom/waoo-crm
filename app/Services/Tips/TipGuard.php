<?php
namespace App\Services\Tips;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Event,FinancialRevision,TipGrant,User};
use App\Services\Finance\{MutationKey,Sandbox};
use App\Services\Records\{RecordActor,RecordInput};
use Illuminate\Support\Facades\{Crypt,DB};
use Illuminate\Validation\ValidationException;
final class TipGuard {
    public static function enabled():void {Sandbox::require('sandbox_tips');Sandbox::require();}
    public static function transport(\Illuminate\Http\Request $r):void {
        self::enabled();abort_unless(!app()->environment('staging')||($r->isSecure()&&config('session.secure')===true),403);
        if(!$r->isMethodSafe()&&$r->headers->has('Origin')){
            $p=parse_url(Sandbox::origin());$origin=$p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');abort_unless($r->header('Origin')===$origin,403);
        }
    }
    public static function owner(User $actor,bool $current=false):User {self::transport(request());return RecordActor::authorize($actor,$current);}
    public static function input(array $input,array $rules):array {
        if(array_diff(array_keys($input),array_keys($rules)))throw ValidationException::withMessages(['tip'=>'Campos no permitidos.']);
        return RecordInput::validate($input,$rules);
    }
    // Event -> immutable finance -> grant -> intent/session. Source reads are authoritative under the event lock.
    public static function source(int $eventId):array {
        $a=\App\Services\Agreements\ConfirmedAgreement::lock($eventId);$e=$a['event'];$f=$a['finance'];
        RecordWriteRejected::unless(!$e->ends_at->isFuture()&&$e->ends_at->utc()->addDays(14)->isFuture(),409,'La fiesta todavía no permite propinas o el plazo terminó.');
        if($a['origin']==='honeybook_opening'){
            $money=\App\Services\Finance\OpeningFinanceSummary::forEvent($e);
            RecordWriteRejected::unless($money&&!$money['collection_blocked']&&$money['net_received_cents']!==null,409,'Revisa la conciliación del acuerdo importado antes de abrir propinas.');
        }
        return [$e,$f,$a['snapshot_sha256']];
    }
    public static function fingerprint(Event $e,FinancialRevision $f):string {
        return \App\Services\Agreements\ConfirmedAgreement::nativeFingerprint($e,$f);
    }

    public static function grant(int $id):array {
        $hint=TipGrant::findOrFail($id);[$e,$f,$sha]=self::source($hint->event_id);$g=TipGrant::whereKey($id)->lockForUpdate()->firstOrFail();
        self::active($g);RecordWriteRejected::unless($g->event_id===$e->id&&$g->customer_id===$e->customer_id&&$g->financial_revision_id===$f->id&&$g->total_cents===$f->total_cents&&hash_equals($g->snapshot_sha256,$sha),409,'La fiesta o sus importes cambiaron. Pide un enlace nuevo.');
        return [$g,$e,$f];
    }
    public static function active(TipGrant $g):void {RecordWriteRejected::unless($g->snapshot_schema_version===\App\Services\Integrity\EvidenceV1::VERSION,409,'La versión de este enlace requiere revisión.');RecordWriteRejected::unless(!$g->revoked_at&&$g->expires_at->isFuture(),409,'Este enlace venció o fue anulado.');}
    // Current read: a pre-lock grant hint may already have established a REPEATABLE READ snapshot.
    public static function receipt(string $scope,string $key,string $hash):?object {
        RecordInput::validate(['key'=>$key],['key'=>'required|uuid']);
        $r=DB::table('mutation_receipts')->where(['scope'=>$scope,'request_key'=>$key])->lockForUpdate()->first();
        if($r)RecordWriteRejected::unless(hash_equals($r->request_hash,$hash),409,'Esta clave ya se usó con otros datos.');return $r;
    }
    public static function token():string{return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');}
    public static function link(TipGrant $g):string {
        self::active($g);
        try{$p=json_decode(Crypt::decryptString($g->encrypted_token),true,flags:JSON_THROW_ON_ERROR);$token=$p['token']??null;
            if(($p['id']??null)!==$g->id||($p['event_id']??null)!==$g->event_id||($p['customer_id']??null)!==$g->customer_id||!is_string($token)||!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token)||!hash_equals($g->token_hash,hash('sha256',$token)))throw new \RuntimeException();
        }catch(\Throwable){throw new RecordWriteRejected(409,'No se puede recuperar este enlace.');}
        return Sandbox::origin().'/portal/tip#'.$token;
    }
    public static function reconcile(?string $intentId,?string $receiptId,string $reason):void {
        DB::table('tip_reconciliations')->insertOrIgnore(['tip_checkout_intent_id'=>$intentId,'webhook_receipt_id'=>$receiptId,'reason'=>$reason,
            'dedupe_sha256'=>hash('sha256',($intentId??'').':'.($receiptId??'').':'.$reason),'status'=>'open','created_at'=>now(),'updated_at'=>now()]);
    }
}
