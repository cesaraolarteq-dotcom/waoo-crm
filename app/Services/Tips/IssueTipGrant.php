<?php
namespace App\Services\Tips;
use App\Exceptions\RecordWriteRejected;
use App\Models\{TipGrant,User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use Illuminate\Support\Facades\{Crypt,DB};
final class IssueTipGrant {
    public function execute(User $actor,int $eventId,array $input,string $key):array {
        $actor=TipGuard::owner($actor);$d=TipGuard::input($input,['expected_event_version'=>'required|integer|min:1','financial_revision_id'=>'required|integer|min:1','locale'=>'required|in:es,en']);
        foreach(['expected_event_version','financial_revision_id'] as $v)$d[$v]=(int)$d[$v];
        return DB::transaction(function()use($actor,$eventId,$d,$key){
            TipGuard::owner($actor);[$e,$f,$sha]=TipGuard::source($eventId);$scope='owner:'.$actor->id.':event:'.$eventId.':tip-grant';$hash=MutationKey::hash($d);
            if($r=TipGuard::receipt($scope,$key,$hash)){[$g]=TipGuard::grant((int)$r->result_id);return $this->envelope($g);}
            RecordWriteRejected::unless($e->version===$d['expected_event_version']&&$f->id===$d['financial_revision_id'],409,'La fiesta o sus importes cambiaron.');
            $token=TipGuard::token();$g=TipGrant::create(['event_id'=>$eventId,'customer_id'=>$e->customer_id,'financial_revision_id'=>$f->id,'snapshot_sha256'=>$sha,'total_cents'=>$f->total_cents,
                'locale'=>$d['locale'],'token_hash'=>hash('sha256',$token),'expires_at'=>$e->ends_at->utc()->addDays(14),'created_by'=>$actor->id]);
            $g->encrypted_token=Crypt::encryptString(json_encode(['id'=>$g->id,'event_id'=>$eventId,'customer_id'=>$e->customer_id,'token'=>$token],JSON_THROW_ON_ERROR));$g->save();
            Audit::recordFor($actor,'tip.grant_issued',$g,[],['event_id'=>$eventId,'financial_revision_id'=>$f->id]);MutationKey::store($scope,$key,$hash,'tip_grant',$g->id);
            return $this->envelope($g);
        },3);
    }
    private function envelope(TipGrant $g):array{return ['id'=>$g->id,'version'=>$g->version,'expires_at'=>$g->expires_at->toIso8601String(),'link'=>TipGuard::link($g)];}
}
