<?php
namespace App\Services\Tips;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Event,TipGrant,TipCheckoutIntent,User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use Illuminate\Support\Facades\DB;
final class RevokeTipGrant {
    public function execute(User $actor,int $grantId,array $input,string $key):array {
        $actor=TipGuard::owner($actor);$d=TipGuard::input($input,['expected_version'=>'required|integer|min:1']);$d['expected_version']=(int)$d['expected_version'];
        return DB::transaction(function()use($actor,$grantId,$d,$key){
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();TipGuard::owner($actor,true);$hint=TipGrant::findOrFail($grantId);Event::whereKey($hint->event_id)->lockForUpdate()->firstOrFail();$g=TipGrant::whereKey($grantId)->lockForUpdate()->firstOrFail();
            $scope='owner:'.$actor->id.':tip-revoke:'.$grantId;$hash=MutationKey::hash($d);
            if(!$r=TipGuard::receipt($scope,$key,$hash)){
                RecordWriteRejected::unless($g->version===$d['expected_version']&&!$g->revoked_at,409,'El enlace cambió.');
                $g->fill(['revoked_at'=>now(),'encrypted_token'=>null,'version'=>$g->version+1])->save();
                foreach(TipCheckoutIntent::where('active_grant_id',$grantId)->lockForUpdate()->get() as $i){TipGuard::reconcile($i->id,null,'grant_revoked_with_collection');\App\Jobs\ExpireTipCheckout::dispatch($i->id)->afterCommit();}
                Audit::recordFor($actor,'tip.grant_revoked',$g,[],['event_id'=>$g->event_id,'version'=>$g->version]);MutationKey::store($scope,$key,$hash,'tip_grant',$g->id);
            }
            return ['id'=>$g->id,'version'=>$g->version,'revoked'=>true];
        },3);
    }
}
