<?php
namespace App\Services\Tips;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Event,TipCheckoutIntent};
use App\Services\Audit;
use App\Services\Finance\{MutationKey,Sandbox,StripeGateway};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class CreateTipCheckout {
    public function execute(TipContext $context,array $input,string $key):array {
        TipGuard::enabled();
        $d=TipGuard::input($input,['financial_revision_id'=>'required|integer|min:1','snapshot_sha256'=>'required|string|regex:/^[a-f0-9]{64}$/D','option'=>'required|in:pct_5,pct_10,pct_15,custom','custom_amount'=>'sometimes|required|string|max:10']);$d['financial_revision_id']=(int)$d['financial_revision_id'];
        $i=DB::transaction(function()use($context,$d,$key){
            [$g]=$context->authorize();RecordWriteRejected::unless($g->financial_revision_id===$d['financial_revision_id']&&hash_equals($g->snapshot_sha256,$d['snapshot_sha256']),409,'Los importes cambiaron; recarga.');
            $amount=TipAmounts::amount($g->total_cents,$d);$scope='tip-grant:'.$g->id.':checkout';$hash=MutationKey::hash($d);
            if($r=TipGuard::receipt($scope,$key,$hash))return TipCheckoutIntent::whereKey($r->result_id)->where('tip_grant_id',$g->id)->lockForUpdate()->firstOrFail();
            RecordWriteRejected::unless(!TipCheckoutIntent::where('active_event_id',$g->event_id)->lockForUpdate()->first(),409,'Ya hay una propina en proceso. Reutiliza el intento o pide revisión; no abras otro cobro.');
            $expires=now()->toImmutable()->utc()->addHour()->min($g->expires_at);
            RecordWriteRejected::unless($expires->greaterThan(now()->addMinutes(30)),409,'Este enlace está por vencer.');
            $i=TipCheckoutIntent::create(['id'=>(string)Str::uuid(),'tip_grant_id'=>$g->id,'event_id'=>$g->event_id,'financial_revision_id'=>$g->financial_revision_id,'active_grant_id'=>$g->id,'active_event_id'=>$g->event_id,
                'environment'=>'test','option'=>$d['option'],'amount_cents'=>$amount,'currency'=>'USD','status'=>'creating','expires_at'=>$expires]);
            Audit::recordSystem('tip.checkout_created',$i,[],['event_id'=>$g->event_id,'tip_grant_id'=>$g->id,'amount_cents'=>$amount,'environment'=>'test']);MutationKey::store($scope,$key,$hash,'tip_intent',$i->id);return $i;
        },3);
        if($i->status!=='creating')return $this->result($context,$i->id);
        if($i->created_at->lessThan(now()->subHours(23))){$this->problem($i,'provider_retry_window_exceeded');throw new RecordWriteRejected(409,'La propina requiere conciliación antes de reintentar.');}
        // Revalidate immediately before the provider; in-flight transitions remain reconciliation obligations.
        DB::transaction(fn()=>$context->authorize(),3);$origin=Sandbox::origin();$metadata=['kind'=>'tip','local_tip_intent_id'=>$i->id,'event_id'=>(string)$i->event_id,'grant_id'=>(string)$i->tip_grant_id];
        try{$s=app(StripeGateway::class)->createCheckout(['mode'=>'payment','payment_method_types'=>['card'],'client_reference_id'=>$i->id,'metadata'=>$metadata,'payment_intent_data'=>['metadata'=>$metadata],
            'line_items'=>[['quantity'=>1,'price_data'=>['currency'=>'usd','unit_amount'=>$i->amount_cents,'product_data'=>['name'=>$context->locale==='es'?'Propina para el equipo':'Tip for the team']]]],
            'expires_at'=>$i->expires_at->timestamp,'success_url'=>$origin.'/portal/tip?checkout='.$i->id,'cancel_url'=>$origin.'/portal/tip?checkout='.$i->id], 'waoo-tip-test:'.$i->id);
        }catch(\Illuminate\Auth\Access\AuthorizationException $e){TipCheckoutIntent::whereKey($i->id)->update(['last_error'=>'provider_configuration_denied']);throw $e;}
        catch(\Throwable){TipCheckoutIntent::whereKey($i->id)->where('status','creating')->update(['last_error'=>'provider_outcome_unknown']);throw new RecordWriteRejected(503,'No se conoce el resultado. Reintenta la misma solicitud; no abras otra propina.');}
        if(!TipProvider::binding($i,$s)||!TipProvider::url($s['url']??null)){$this->problem($i,'invalid_checkout_response');throw new RecordWriteRejected(409,'La respuesta requiere conciliación.');}
        DB::transaction(function()use($i,$s,$context){
            Event::whereKey($i->event_id)->lockForUpdate()->firstOrFail();$allowed=true;try{$context->authorize();}catch(\Illuminate\Database\QueryException $e){throw $e;}catch(\Throwable){$allowed=false;}
            $locked=TipCheckoutIntent::whereKey($i->id)->lockForUpdate()->firstOrFail();
            if($locked->provider_session_id&&$locked->provider_session_id!==$s['id']){TipGuard::reconcile($locked->id,null,'conflicting_create_session');return;}
            $locked->provider_account=$s['_verified_account'];$locked->provider_session_id=$s['id'];
            if(is_string($s['payment_intent']??null)&&!$locked->provider_payment_id)$locked->provider_payment_id=$s['payment_intent'];
            if($locked->status==='creating'){
                if($allowed)$locked->fill(['status'=>'open','checkout_url'=>$s['url'],'last_error'=>null]);
                else{$locked->fill(['status'=>'reconciliation_required','last_error'=>'source_changed']);TipGuard::reconcile($locked->id,null,'source_changed');}
            }
            $locked->save();if(!$allowed&&$locked->status!=='paid')\App\Jobs\ExpireTipCheckout::dispatch($locked->id)->afterCommit();
        },3);
        return $this->result($context,$i->id);
    }
    private function result(TipContext $context,string $id):array {
        $result=DB::transaction(function()use($context,$id){[$g]=$context->authorize();$i=TipCheckoutIntent::whereKey($id)->where('tip_grant_id',$g->id)->lockForUpdate()->firstOrFail();
            RecordWriteRejected::unless(in_array($i->status,['open','paid'],true),409,'Este intento requiere revisión.');
            if($i->status==='open'&&!$i->expires_at->isFuture()){
                TipGuard::reconcile($i->id,null,'checkout_expiry_requires_verification');
                \App\Jobs\ExpireTipCheckout::dispatch($i->id)->afterCommit();return ['_expiry_pending'=>true];
            }
            $url=$i->status==='open'?$i->checkout_url:null;RecordWriteRejected::unless($url===null||TipProvider::url($url),409,'Este intento requiere revisión.');
            return ['intent_id'=>$i->id,'status'=>$i->status,'checkout_url'=>$url];},3);
        RecordWriteRejected::unless(!isset($result['_expiry_pending']),409,'El cobro venció; estamos verificando su cierre. No abras otro intento.');return $result;
    }
    private function problem(TipCheckoutIntent $i,string $reason):void {
        DB::transaction(function()use($i,$reason){Event::whereKey($i->event_id)->lockForUpdate()->firstOrFail();$locked=TipCheckoutIntent::whereKey($i->id)->lockForUpdate()->firstOrFail();
            if($locked->status!=='paid')$locked->fill(['status'=>'reconciliation_required','last_error'=>$reason])->save();TipGuard::reconcile($i->id,null,$reason);},3);
    }
}
