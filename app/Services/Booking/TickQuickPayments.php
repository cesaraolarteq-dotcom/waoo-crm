<?php
namespace App\Services\Booking;
use App\Models\{Event,QuickPaymentBooking,QuickStripeLease,ResourceReservation,QuickPaymentNotification};
use App\Services\Finance\MutationKey;
use Illuminate\Support\Facades\DB;
final class TickQuickPayments
{
    public function execute(int $limit=100):array
    {
        QuickPaymentProtocol::gate(true);
        foreach(\App\Models\WebhookReceipt::where('state','queued')->orderBy('created_at')->limit($limit)->pluck('id') as $webhook){
            $r=\App\Models\WebhookReceipt::findOrFail($webhook);
            $p=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($r->encrypted_payload),true,flags:JSON_THROW_ON_ERROR);
            if(isset($p['data']['object']['metadata']['quick_booking_request_id'])) {
                try{app(ProcessQuickPaymentReceipt::class)->execute($webhook);}catch(\Throwable){/* Retry durable signed receipt on next cron; no private exception logging. */}
            }
        }
        $counts=['reminders'=>0,'expired'=>0,'review_required'=>0];
        foreach(QuickPaymentBooking::whereIn('state',['awaiting_payment','zelle_pending','processing'])->orderBy('id')->limit($limit)->pluck('id') as $id){
            DB::transaction(function()use($id,&$counts){
                $stub=QuickPaymentBooking::findOrFail($id);$e=Event::whereKey($stub->event_id)->lockForUpdate()->firstOrFail();$b=QuickPaymentProtocol::booking($e->id);
                QuickPaymentProtocol::verify($b,$e);
                if(!in_array($b->state,['awaiting_payment','zelle_pending','processing','review_required'],true))return;
                $lease=QuickStripeLease::whereKey($b->quick_stripe_lease_id)->lockForUpdate()->firstOrFail();
                if($b->expires_at->isFuture()){
                    if(!$b->reminder_at->isFuture()&&!QuickPaymentProtocol::pendingNotice($b)){
                        if(app(QuickPaymentNotifications::class)->reminder($b))$counts['reminders']++;
                    }return;
                }
                if(QuickPaymentProtocol::pendingNotice($b)){
                    if($b->state!=='review_required'){QuickPaymentProtocol::transition($b,'review_required','pending_zelle_at_original_deadline');$counts['review_required']++;}return;
                }
                $p=QuickStripeLeases::verify($lease,$e,false);QuickStripeLeases::lockInventory($p);$p=QuickStripeLeases::verify($lease,$e);
                ResourceReservation::whereIn('id',array_column($p['rows'],'reservation_id'))->where('event_id',$e->id)->delete();
                QuickStripeLeases::append(null,$lease,[...$p,'state'=>'expired','rows'=>[],'reason'=>'original_48h_deadline']);
                QuickPaymentProtocol::transition($b,'expired','original_48h_deadline');$counts['expired']++;
            },3);
        }
        // Best-effort provider closure outside DB locks; never restore released stock after a late charge.
        foreach(\App\Models\PaymentIntent::where('purpose','quick_deposit')->where(function($q){$q->whereIn('event_id',QuickPaymentBooking::where('state','expired')->select('event_id'))->orWhere('status','superseded');})->whereIn('status',['open','prepared','processing','superseded'])->whereNotNull('stripe_checkout_session_id')->limit($limit)->get() as $intent){
            try{
                $gateway=app(\App\Services\Finance\StripeGateway::class);$gateway->expireCheckout($intent->stripe_checkout_session_id);
                $remote=$gateway->retrieveCheckout($intent->stripe_checkout_session_id);$attempt=\App\Models\QuickPaymentAttempt::where('payment_intent_id',$intent->id)->firstOrFail();
                QuickPaymentCheckout::session($remote,\App\Models\QuickStripeCheckoutRequest::findOrFail($attempt->id));
                if(($remote['status']??null)==='expired' && ($remote['payment_status']??null)!=='paid') DB::transaction(function()use($intent){
                    Event::whereKey($intent->event_id)->lockForUpdate()->firstOrFail();$i=\App\Models\PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
                    if($i->status!=='paid')$i->update(['status'=>'expired','collection_slot'=>null,'last_error'=>null]);
                },3);
            }catch(\Throwable){\App\Models\PaymentIntent::whereKey($intent->id)->where('status','!=','paid')->update(['last_error'=>'provider_close_pending']);}
        }
        app(DispatchQuickPaymentNotifications::class)->execute($limit);return $counts;
    }
}
