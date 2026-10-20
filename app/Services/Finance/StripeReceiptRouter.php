<?php
namespace App\Services\Finance;
use App\Models\{TipCheckoutIntent,WebhookReceipt,PaymentIntent,ImportedProjectLink};
use App\Services\Tips\ProcessTipStripeReceipt;
use Illuminate\Support\Facades\Crypt;
final class StripeReceiptRouter {
    public function execute(string $id, ?StripeReceiptProcessor $booking = null):void {
        Sandbox::require();$r=WebhookReceipt::findOrFail($id);if(in_array($r->state,['processed','ignored','reconciliation_required'],true))return;
        $p=json_decode(Crypt::decryptString($r->encrypted_payload),true,flags:JSON_THROW_ON_ERROR);$o=$p['data']['object'];$m=$o['metadata']??[];
        $refundObject = in_array($r->event_type, ['refund.created', 'refund.updated', 'charge.refund.updated'], true);
        if ($refundObject || $r->event_type === 'charge.refunded' || str_starts_with($r->event_type, 'charge.dispute.')) {
            $providerPayment = is_string($o['payment_intent'] ?? null) ? $o['payment_intent'] : null;
            if ($providerPayment && TipCheckoutIntent::where('provider_payment_id', $providerPayment)->exists())
                app(ProcessTipStripeReceipt::class)->execute($id);
            elseif ($providerPayment && ($native = PaymentIntent::where('stripe_payment_intent_id', $providerPayment)->first())
                && !ImportedProjectLink::where('event_id', $native->event_id)->exists()) ($booking ?? app(StripeReceiptProcessor::class))->execute($id);
            elseif ($refundObject) app(CaptureOpeningStripeRefund::class)->execute($id);
            else app(CaptureOpeningChargeNotice::class)->execute($id);
            return;
        }
        $tip=isset($m['local_tip_intent_id'])||isset($m['kind']);
        if(!$tip&&!isset($m['local_intent_id'])){
            $tip=(($o['object']??null)==='checkout.session'&&TipCheckoutIntent::where('provider_session_id',$o['id'])->exists())
                ||(is_string($o['payment_intent']??null)&&TipCheckoutIntent::where('provider_payment_id',$o['payment_intent'])->exists())
                ||(($o['object']??null)==='payment_intent'&&TipCheckoutIntent::where('provider_payment_id',$o['id'])->exists());
        }
        if($tip)app(ProcessTipStripeReceipt::class)->execute($id);else ($booking??app(StripeReceiptProcessor::class))->execute($id);
    }
}
