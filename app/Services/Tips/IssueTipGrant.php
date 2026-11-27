<?php
namespace App\Services\Tips;
use App\Exceptions\RecordWriteRejected;
use App\Models\{TipGrant,User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Records\RecordActor;
use Illuminate\Support\Facades\{Crypt,DB};
final class IssueTipGrant {
    public function execute(User $actor,int $eventId,array $input,string $key):array {
        $actor=TipGuard::owner($actor);$d=TipGuard::input($input,['expected_event_version'=>'required|integer|min:1','financial_revision_id'=>'required|integer|min:1','locale'=>'required|in:es,en']);
        foreach(['expected_event_version','financial_revision_id'] as $v)$d[$v]=(int)$d[$v];
        return DB::transaction(function()use($actor,$eventId,$d,$key){
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();TipGuard::owner($actor,true);[$e,$f,$sha]=TipGuard::source($eventId);$scope='owner:'.$actor->id.':event:'.$eventId.':tip-grant';$hash=MutationKey::hash($d);
            if($r=TipGuard::receipt($scope,$key,$hash)){[$g]=TipGuard::grant((int)$r->result_id);return $this->envelope($g);}
            RecordWriteRejected::unless($e->version===$d['expected_event_version']&&$f->id===$d['financial_revision_id'],409,'La fiesta o sus importes cambiaron.');
            $g=$this->make($actor,$e,$f,$sha,$d['locale']);MutationKey::store($scope,$key,$hash,'tip_grant',$g->id);
            return $this->envelope($g);
        },3);
    }
    /**
     * Owner 2026-11-23: the thank-you email (day after the party, 10 a.m.) gets its tip link automatically. Runs from the
     * scheduler (no browser request), so it checks the owner account but not the HTTPS request. Reuses the event's active
     * link for the same amounts; otherwise issues one with the same rules as the owner button (after the party, 14 days).
     */
    public function automatic(User $owner,int $eventId,string $locale):TipGrant {
        TipGuard::enabled();RecordActor::authorize($owner);
        return DB::transaction(function()use($owner,$eventId,$locale){
            [$e,$f,$sha]=TipGuard::source($eventId);
            $existing=TipGrant::where('event_id',$eventId)->where('financial_revision_id',$f->id)->whereNull('revoked_at')->where('expires_at','>',now())->orderByDesc('id')->lockForUpdate()->first();
            if($existing&&hash_equals($existing->snapshot_sha256,$sha)&&$existing->total_cents===$f->total_cents)return $existing;
            return $this->make($owner,$e,$f,$sha,$locale==='es'?'es':'en');
        },3);
    }
    private function make(User $actor,$e,$f,string $sha,string $locale):TipGrant {
        $token=TipGuard::token();$g=TipGrant::create(['event_id'=>$e->id,'customer_id'=>$e->customer_id,'financial_revision_id'=>$f->id,'snapshot_sha256'=>$sha,'total_cents'=>$f->total_cents,
            'locale'=>$locale,'token_hash'=>hash('sha256',$token),'expires_at'=>$e->ends_at->utc()->addDays(14),'created_by'=>$actor->id]);
        $g->encrypted_token=Crypt::encryptString(json_encode(['id'=>$g->id,'event_id'=>$e->id,'customer_id'=>$e->customer_id,'token'=>$token],JSON_THROW_ON_ERROR));$g->save();
        Audit::recordFor($actor,'tip.grant_issued',$g,[],['event_id'=>$e->id,'financial_revision_id'=>$f->id]);
        return $g;
    }
    private function envelope(TipGrant $g):array{return ['id'=>$g->id,'version'=>$g->version,'expires_at'=>$g->expires_at->toIso8601String(),'link'=>TipGuard::link($g)];}
}
