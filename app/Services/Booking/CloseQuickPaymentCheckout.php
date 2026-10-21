<?php
namespace App\Services\Booking;
use App\Exceptions\RecordWriteRejected;
use App\Models\{PaymentIntent,QuickPaymentAttempt,QuickStripeCheckoutRequest};
use App\Services\Finance\{MutationKey,StripeGateway};
use App\Services\Portal\PortalContext;
use Illuminate\Support\Facades\DB;
final class CloseQuickPaymentCheckout
{
    public function execute(PortalContext $client,array $input,string $key):array
    {
        QuickPaymentProtocol::gate();$d=QuickPaymentProtocol::input($input,['request_id'=>'required|uuid']);$scope="portal:{$client->grantId}:quick-checkout-close";$hash=MutationKey::hash($d);
        [$r,$i,$replay]=DB::transaction(function()use($client,$d,$scope,$hash,$key){
            [$b]=QuickPaymentProtocol::portal($client);$r=QuickStripeCheckoutRequest::findOrFail($d['request_id']);$a=QuickPaymentAttempt::whereKey($r->id)->where('quick_payment_booking_id',$b->id)->where('portal_grant_id',$client->grantId)->firstOrFail();
            $i=PaymentIntent::whereKey($a->payment_intent_id)->lockForUpdate()->firstOrFail();QuickPaymentCheckout::verify($r);
            if(MutationKey::find($scope,$key,$hash,true))return [$r,$i,true];QuickPaymentProtocol::pin($b,$d);
            RecordWriteRejected::unless($b->state==='awaiting_payment'&&$i->stripe_checkout_session_id&&in_array($i->status,['open','prepared'],true),409,'El pago está en proceso o requiere revisión. / Payment is processing or needs review.');
            return [$r,$i,false];
        },3);
        if($replay)return ['state'=>'expired'];
        $gateway=app(StripeGateway::class);$gateway->expireCheckout($i->stripe_checkout_session_id);$s=$gateway->retrieveCheckout($i->stripe_checkout_session_id);QuickPaymentCheckout::session($s,$r);
        RecordWriteRejected::unless(($s['status']??null)==='expired'&&($s['payment_status']??null)==='unpaid'&&!in_array($s['payment_intent']['status']??null,['processing','succeeded'],true),409,'Stripe todavía no confirmó el cierre. / Stripe has not confirmed closure.');
        return DB::transaction(function()use($client,$d,$r,$i,$scope,$hash,$key){
            [$b]=QuickPaymentProtocol::portal($client);$locked=PaymentIntent::whereKey($i->id)->lockForUpdate()->firstOrFail();
            if(MutationKey::find($scope,$key,$hash,true))return ['state'=>'expired'];QuickPaymentProtocol::pin($b,$d);
            RecordWriteRejected::unless($b->state==='awaiting_payment'&&$locked->status!=='paid'&&$locked->stripe_checkout_session_id===$i->stripe_checkout_session_id,409,'El pago cambió. Actualiza el estado. / Refresh payment status.');
            $locked->update(['status'=>'expired','collection_slot'=>null]);
            \App\Services\Audit::recordSystem('quick_payment.checkout_closed',$r,[],['event_id'=>$b->event_id,'request_id'=>$r->id]);
            MutationKey::store($scope,$key,$hash,'quick_stripe_checkout_request',$r->id);return ['state'=>'expired'];
        },3);
    }
}
