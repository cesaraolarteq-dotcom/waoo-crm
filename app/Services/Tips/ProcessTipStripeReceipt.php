<?php
namespace App\Services\Tips;
use App\Models\{Event,LedgerEntry,TipCheckoutIntent,TipReceipt,WebhookReceipt};
use App\Services\Audit;
use App\Services\Finance\{Sandbox,StripeGateway};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Crypt,DB};
final class ProcessTipStripeReceipt {
    public function execute(string $receiptId):void {
        // Intake/cleanup of an already issued test collection survives a disabled tips UI.
        Sandbox::require();$receipt=WebhookReceipt::findOrFail($receiptId);if(in_array($receipt->state,['processed','ignored','reconciliation_required'],true))return;
        $receipt->increment('attempts');$payload=json_decode(Crypt::decryptString($receipt->encrypted_payload),true,flags:JSON_THROW_ON_ERROR);$o=$payload['data']['object'];$m=$o['metadata']??[];
        $id=$m['local_tip_intent_id']??null;$i=$id?TipCheckoutIntent::find($id):null;
        if(!$i&&!$id){$q=TipCheckoutIntent::query();$i=($o['object']??null)==='checkout.session'?$q->where('provider_session_id',$o['id'])->first():$q->where('provider_payment_id',($o['object']??null)==='payment_intent'?$o['id']:($o['payment_intent']??'unlinked'))->first();}
        if(!$i){$this->problem($receipt,null,'unlinked_tip_event');return;}
        if(isset($m['local_intent_id'])||isset($m['financial_revision_id'])||(isset($m['kind'])&&$m['kind']!=='tip')
            ||(isset($m['event_id'])&&(string)$m['event_id']!==(string)$i->event_id)||(isset($m['grant_id'])&&(string)$m['grant_id']!==(string)$i->tip_grant_id)){$this->problem($receipt,$i,'tip_namespace_conflict');return;}
        if(!in_array($receipt->event_type,['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.async_payment_failed','checkout.session.expired','payment_intent.succeeded'],true)){$this->problem($receipt,$i,'tip_refund_or_unsupported_event');return;}
        $sessionId=($o['object']??null)==='checkout.session'?$o['id']:$i->provider_session_id;if(!$sessionId)throw new \RuntimeException('tip_checkout_not_linked_yet');
        $s=app(StripeGateway::class)->retrieveCheckout($sessionId);
        if(($s['id']??null)!==$sessionId||!TipProvider::binding($i,$s)||($receipt->stripe_account_id&&$receipt->stripe_account_id!==$s['_verified_account'])){$this->problem($receipt,$i,'tip_session_binding_mismatch');return;}
        if(($s['payment_status']??null)!=='paid'){
            DB::transaction(function()use($i,$receipt,$s){Event::whereKey($i->event_id)->lockForUpdate()->firstOrFail();$locked=TipCheckoutIntent::whereKey($i->id)->lockForUpdate()->firstOrFail();$r=WebhookReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
                if(in_array($r->state,['processed','reconciliation_required'],true))return;
                if(!in_array($locked->status,['paid','reconciliation_required'],true)){
                    $expired=($s['status']??null)==='expired';$failed=$receipt->event_type==='checkout.session.async_payment_failed'&&in_array($s['payment_intent']['status']??null,['requires_payment_method','canceled'],true);
                    $locked->fill(['status'=>$expired?'expired':($failed?'failed':'processing'),'active_grant_id'=>($expired||$failed)?null:$locked->active_grant_id,'active_event_id'=>($expired||$failed)?null:$locked->active_event_id,'provider_session_id'=>$s['id'],'provider_account'=>$s['_verified_account']])->save();
                }
                $r->fill(['state'=>'processed','processed_at'=>now(),'last_error'=>null])->save();},3);return;
        }
        $p=$s['payment_intent']??null;$c=is_array($p)?($p['latest_charge']??null):null;
        if(!is_array($p)||!is_array($c)||!is_string($p['id']??null)||!preg_match('/^pi_[A-Za-z0-9_]{1,220}$/D',$p['id'])||($p['livemode']??null)!==false||($p['status']??null)!=='succeeded'
            ||($p['currency']??null)!=='usd'||!is_int($p['amount_received']??null)||$p['amount_received']!==$i->amount_cents||($c['captured']??null)!==true||($c['paid']??null)!==true||!is_int($c['created']??null)||$c['created']<=0||$c['created']>time()){$this->problem($receipt,$i,'tip_payment_not_verified');return;}
        if((($o['object']??null)==='payment_intent'&&($o['id']??null)!==$p['id'])||(is_string($o['payment_intent']??null)&&$o['payment_intent']!==$p['id'])){$this->problem($receipt,$i,'tip_payment_binding_mismatch');return;}
        DB::transaction(function()use($i,$receipt,$s,$p,$c){
            $e=Event::whereKey($i->event_id)->lockForUpdate()->firstOrFail();$current=true;
            try{TipGuard::grant($i->tip_grant_id);}catch(\Illuminate\Database\QueryException $error){throw $error;}catch(\Throwable){$current=false;}
            $locked=TipCheckoutIntent::whereKey($i->id)->lockForUpdate()->firstOrFail();$r=WebhookReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();if(in_array($r->state,['processed','reconciliation_required'],true))return;
            // Binding is checked again against the row now locked, after any concurrent create/receipt.
            if(!TipProvider::binding($locked,$s)||($locked->provider_payment_id&&$locked->provider_payment_id!==$p['id'])){$this->lockedProblem($r,$locked,'tip_current_binding_mismatch');return;}
            $external='stripe:test:'.$s['_verified_account'].':'.$p['id'];$sha=hash('sha256',$external);
            if(LedgerEntry::where('external_identity_key',$external)->exists()){$this->lockedProblem($r,$locked,'tip_booking_payment_collision');return;}
            $prior=TipReceipt::where('external_sha256',$sha)->orWhere('tip_checkout_intent_id',$locked->id)->lockForUpdate()->first();
            if($prior&&($prior->tip_checkout_intent_id!==$locked->id||$prior->external_sha256!==$sha||$prior->gross_cents!==$locked->amount_cents)){$this->lockedProblem($r,$locked,'tip_transaction_already_allocated');return;}
            if(!$prior){$tip=TipReceipt::create(['tip_checkout_intent_id'=>$locked->id,'event_id'=>$locked->event_id,'tip_grant_id'=>$locked->tip_grant_id,'external_sha256'=>$sha,
                'gross_cents'=>$locked->amount_cents,'currency'=>'USD','processing_fee_cents'=>null,'received_at'=>CarbonImmutable::createFromTimestampUTC($c['created']),'webhook_receipt_id'=>$r->id]);
                Audit::recordSystem('tip.received',$tip,[],['event_id'=>$locked->event_id,'tip_checkout_intent_id'=>$locked->id,'gross_cents'=>$locked->amount_cents]);}
            if(!$current)TipGuard::reconcile($locked->id,$r->id,'late_tip_source_changed');
            elseif(in_array($locked->status,['expired','failed','reconciliation_required'],true))TipGuard::reconcile($locked->id,$r->id,'late_tip_collection_changed');
            $locked->fill(['status'=>'paid','active_grant_id'=>null,'active_event_id'=>null,'provider_account'=>$s['_verified_account'],'provider_session_id'=>$s['id'],'provider_payment_id'=>$p['id'],'last_error'=>null])->save();
            $r->fill(['state'=>'processed','processed_at'=>now(),'last_error'=>null])->save();
        },3);
    }
    private function problem(WebhookReceipt $receipt,?TipCheckoutIntent $i,string $reason):void {
        DB::transaction(function()use($receipt,$i,$reason){if($i)Event::whereKey($i->event_id)->lockForUpdate()->firstOrFail();$locked=$i?TipCheckoutIntent::whereKey($i->id)->lockForUpdate()->firstOrFail():null;$r=WebhookReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();if(in_array($r->state,['processed','reconciliation_required'],true))return;$this->lockedProblem($r,$locked,$reason);},3);
    }
    private function lockedProblem(WebhookReceipt $r,?TipCheckoutIntent $i,string $reason):void {
        if($i&&$i->status!=='paid')$i->fill(['status'=>'reconciliation_required','last_error'=>$reason])->save();
        TipGuard::reconcile($i?->id,$r->id,$reason);$r->fill(['state'=>'reconciliation_required','last_error'=>$reason,'processed_at'=>now()])->save();
    }
}
