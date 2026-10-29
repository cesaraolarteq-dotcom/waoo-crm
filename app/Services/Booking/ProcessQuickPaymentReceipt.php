<?php
namespace App\Services\Booking;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Event,LedgerEntry,PaymentIntent,QuickPaymentAttempt,QuickPaymentReceipt,QuickStripeCheckoutRequest,WebhookReceipt};
use App\Services\Audit;
use App\Services\Finance\{MutationKey,StripeGateway};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Crypt,DB};
final class ProcessQuickPaymentReceipt
{
    public function execute(string $id):void
    {
        QuickPaymentProtocol::gate(true);$r=WebhookReceipt::findOrFail($id);if(in_array($r->state,['processed','ignored','reconciliation_required'],true))return;
        $p=json_decode(Crypt::decryptString($r->encrypted_payload),true,flags:JSON_THROW_ON_ERROR);$o=$p['data']['object'];
        $requestId=$o['metadata']['quick_booking_request_id']??null;
        $a=$requestId?QuickPaymentAttempt::find($requestId):null;
        if(!$a){$intent=PaymentIntent::where('purpose','quick_deposit')->where(function($q)use($o){
            if(($o['object']??null)==='checkout.session')$q->where('stripe_checkout_session_id',$o['id']);
            else $q->where('stripe_payment_intent_id',($o['object']??null)==='payment_intent'?$o['id']:($o['payment_intent']??''));
        })->first();$a=$intent?QuickPaymentAttempt::where('payment_intent_id',$intent->id)->first():null;}
        if(!$a){$this->problem($r,null,'unlinked_quick_payment');return;}
        $request=QuickStripeCheckoutRequest::findOrFail($a->id);$envelope=QuickPaymentCheckout::verify($request);$intent=PaymentIntent::findOrFail($a->payment_intent_id);
        if(!in_array($r->event_type,['checkout.session.completed','checkout.session.expired','checkout.session.async_payment_succeeded','checkout.session.async_payment_failed','payment_intent.succeeded'],true)){$this->problem($r,$intent,'unsupported_event');return;}
        $sid=($o['object']??null)==='checkout.session'?$o['id']:$intent->stripe_checkout_session_id;
        if(!$sid)throw new \RuntimeException('quick_checkout_not_linked_yet');
        $s=app(StripeGateway::class)->retrieveCheckout($sid);
        try{QuickPaymentCheckout::session($s,$request);
            RecordWriteRejected::unless((!$r->stripe_account_id||$r->stripe_account_id===$s['_verified_account'])&&(!$intent->stripe_checkout_session_id||$intent->stripe_checkout_session_id===$sid),409,'session_mismatch');
        }catch(RecordWriteRejected){$this->problem($r,$intent,'session_binding_mismatch');return;}
        if(($s['payment_status']??null)!=='paid'){
            DB::transaction(function()use($r,$intent,$s){
            \App\Services\Scheduling\DailyCapacity::lock();
                Event::whereKey($intent->event_id)->lockForUpdate()->firstOrFail();$i=PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
                if($i->status!=='paid'){
                    $expired=($s['status']??null)==='expired';$failed=$r->event_type==='checkout.session.async_payment_failed'&&in_array($s['payment_intent']['status']??null,['canceled','requires_payment_method'],true);
                    $i->fill(['status'=>$expired?'expired':($failed?'failed':'processing'),'stripe_checkout_session_id'=>$s['id'],'collection_slot'=>$expired||$failed?null:$i->collection_slot])->save();
                }
                WebhookReceipt::whereKey($r->id)->update(['state'=>'processed','processed_at'=>now(),'last_error'=>null]);
            },3);return;
        }
        $pi=$s['payment_intent']??null;$charge=is_array($pi)?($pi['latest_charge']??null):null;$t=$envelope['terms'];
        $valid=is_array($pi)&&is_array($charge)&&($pi['livemode']??null)===false&&($pi['status']??null)==='succeeded'&&($pi['currency']??null)==='usd'
            &&($pi['amount_received']??null)===$t['payable_cents']&&is_array($pi['metadata']??null)&&MutationKey::hash($pi['metadata'])===MutationKey::hash($envelope['parameters']['metadata'])
            &&is_string($pi['id']??null)&&preg_match('/\Api_[A-Za-z0-9_]+\z/',$pi['id'])
            &&in_array($charge['payment_method_details']['type']??null,$envelope['capabilities']['methods'],true)
            &&($charge['paid']??null)===true&&($charge['captured']??null)===true&&($charge['livemode']??null)===false
            &&($charge['amount']??null)===$t['payable_cents']&&($charge['currency']??null)==='usd'&&($charge['payment_intent']??null)===$pi['id']
            &&is_int($charge['created']??null)&&$charge['created']>0&&$charge['created']<=now()->timestamp+300
            &&(!$intent->stripe_payment_intent_id||$intent->stripe_payment_intent_id===$pi['id']);
        if(!$valid){$this->problem($r,$intent,'unverified_captured_payment');return;}
        DB::transaction(function()use($a,$r,$intent,$request,$s,$pi,$charge,$t){
            \App\Services\Scheduling\DailyCapacity::lock();
            $e=Event::whereKey($intent->event_id)->lockForUpdate()->firstOrFail();$b=QuickPaymentProtocol::booking($e->id);$i=PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
            $receipt=WebhookReceipt::whereKey($r->id)->lockForUpdate()->firstOrFail();if($receipt->state==='processed')return;
            QuickPaymentCheckout::verify($request);
            $identity='stripe:test:'.$s['_verified_account'].':'.$pi['id'];$existing=LedgerEntry::where('external_identity_key',$identity)->lockForUpdate()->first();
            if($existing){
                $verification=QuickPaymentReceipt::where('external_identity_key',$identity)->first();
                if(!$verification||$verification->request_id!==$a->id||$verification->ledger_entry_id!==$existing->id){$this->problem($receipt,$i,'transaction_already_allocated');return;}
            }else{
                $fields=['event_id'=>$e->id,'financial_revision_id'=>$b->financial_revision_id,'environment'=>'test','method'=>'stripe','currency'=>'USD','received_at'=>CarbonImmutable::createFromTimestampUTC($charge['created']),
                    'external_reference'=>$pi['id'],'verified_by'=>null,'source_type'=>'verified_quick_stripe','source_id'=>$r->id];
                $entry=LedgerEntry::create([...$fields,'kind'=>'receipt','amount_cents'=>$t['principal_cents'],'external_identity_key'=>$identity]);
                $fee=$t['customer_fee_cents']>0?LedgerEntry::create([...$fields,'kind'=>'customer_fee','amount_cents'=>$t['customer_fee_cents'],'external_identity_key'=>'stripe:fee:'.hash('sha256',$identity)]):null;
                $v=['schema_version'=>1,'request_id'=>$a->id,'request_sha256'=>$request->envelope_sha256,'booking_id'=>$b->id,'event_id'=>$e->id,'financial_revision_id'=>$b->financial_revision_id,
                    'ledger_entry_id'=>$entry->id,'fee_entry_id'=>$fee?->id,'external_identity_key'=>$identity,'webhook_id'=>$r->id,'account'=>$s['_verified_account'],'session_id'=>$s['id'],'payment_id'=>$pi['id'],
                    'principal_cents'=>$t['principal_cents'],'customer_fee_cents'=>$t['customer_fee_cents'],'gross_cents'=>$t['payable_cents'],'captured_at'=>$charge['created']];
                QuickPaymentReceipt::create(['quick_payment_booking_id'=>$b->id,'request_id'=>$a->id,'ledger_entry_id'=>$entry->id,'fee_entry_id'=>$fee?->id,'external_identity_key'=>$identity,'webhook_id'=>$r->id,
                    'principal_cents'=>$t['principal_cents'],'customer_fee_cents'=>$t['customer_fee_cents'],'gross_cents'=>$t['payable_cents'],'verification'=>$v,'verification_sha256'=>MutationKey::hash($v)]);
                \App\Services\Scheduling\DailyCapacity::recordPaidOverflow($e,$b->financial_revision_id,$entry->id);
                app(PromoteQuickPayment::class)->execute($b,$e,$entry);
                Audit::recordSystem('payment.quick_stripe_received',$entry,[],['request_id'=>$a->id,'webhook_id'=>$r->id,'principal_cents'=>$t['principal_cents'],'customer_fee_cents'=>$t['customer_fee_cents']]);
                app(QuickPaymentNotifications::class)->paid($b,$entry);
            }
            $i->fill(['status'=>'paid','collection_slot'=>null,'stripe_checkout_session_id'=>$s['id'],'stripe_payment_intent_id'=>$pi['id'],'last_error'=>null])->save();
            \App\Services\Finance\SupersedeCollections::forEvent($e->id,'quick_payment_received',null,$i->id);
            $receipt->fill(['state'=>'processed','processed_at'=>now(),'last_error'=>null])->save();
        },3);
    }
    private function problem(WebhookReceipt $r,?PaymentIntent $i,string $reason):void
    {
        DB::transaction(function()use($r,$i,$reason){
            \App\Services\Scheduling\DailyCapacity::lock();
            if($i){Event::whereKey($i->event_id)->lockForUpdate()->firstOrFail();DB::table('payment_exceptions')->insertOrIgnore(['event_id'=>$i->event_id,'financial_revision_id'=>$i->financial_revision_id,'kind'=>'quick_stripe_reconciliation',
                'exception_key'=>hash('sha256',$r->id.':'.$reason),'state'=>'open','context'=>json_encode(['webhook_id'=>$r->id,'intent_id'=>$i->id,'reason'=>$reason]),'created_at'=>now(),'updated_at'=>now()]);}
            $r->fill(['state'=>'reconciliation_required','last_error'=>$reason,'processed_at'=>now()])->save();
        },3);
    }
}
