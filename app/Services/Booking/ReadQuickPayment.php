<?php
namespace App\Services\Booking;
use App\Services\Portal\PortalContext;
use Illuminate\Support\Facades\DB;
final class ReadQuickPayment
{
    public function execute(PortalContext $client):array
    {
        return DB::transaction(function()use($client){
            [$b,$e]=QuickPaymentProtocol::portal($client);$s=QuickPaymentProtocol::verify($b,$e);$f=\App\Models\FinancialRevision::findOrFail($b->financial_revision_id);
            $intent=\App\Models\PaymentIntent::where('event_id',$e->id)->where('purpose','quick_deposit')->whereNotIn('status',['expired','failed','cancelled'])->orderByDesc('created_at')->first();
            $attempt=$intent?\App\Models\QuickPaymentAttempt::where('payment_intent_id',$intent->id)->first():null;
            $url=$intent?->checkout_url;
            if(!is_string($url)||!preg_match('~^https://checkout\.stripe\.com/[^\s]+$~D',$url))$url=null;
            $active=$intent && $attempt && $intent->status!=='paid' ? ['request_id'=>$attempt->id,'state'=>$intent->status,'checkout_url'=>$intent->status==='open'?$url:null] : null;
            if($active && $intent->status==='prepared' && $attempt->portal_grant_id===$client->grantId){
                $request=\App\Models\QuickStripeCheckoutRequest::findOrFail($attempt->id);$p=QuickPaymentCheckout::verify($request);
                $mutation=DB::table('mutation_receipts')->where('scope',"portal:{$client->grantId}:quick-checkout")->where('result_id',$request->id)->where('result_type','quick_stripe_checkout_request')->firstOrFail();
                $active['replay']=['payload'=>['expected_booking_version'=>$attempt->booking_version,'expected_payment_sha256'=>$b->agreement_sha256,'deposit_percent'=>$p['terms']['deposit_percent']],'idempotency_key'=>$mutation->request_key];
            }
            $generation=\App\Models\QuickPaymentAttempt::where('quick_payment_booking_id',$b->id)->count();
            $pending=(bool)QuickPaymentProtocol::pendingNotice($b);
            return ['attempt_generation'=>$generation,'active_checkout'=>$active,'state'=>$b->state,'expected_booking_version'=>$b->version,'expected_payment_sha256'=>$b->agreement_sha256,'total_cents'=>$f->total_cents,
                'balance_cents'=>app(\App\Services\Finance\FinanceSummary::class)->forEvent($e)['balance_cents'],'deposit_cents'=>$s['terms']['principal_cents'],'currency'=>'USD','expires_at'=>$b->expires_at->toIso8601String(),
                'choices'=>array_map(fn($percent)=>QuickStripeTerms::freeze($f->total_cents,\Carbon\CarbonImmutable::createFromTimestampUTC($s['terms']['created_at']),$percent)->snapshot(),[50,100]),
                'can_pay'=>$active===null&&$b->state==='awaiting_payment'&&$b->expires_at->isFuture(),'can_sign'=>$pending||in_array($b->state,['paid','paid_needs_review'],true),
                'zelle_pending'=>$pending,'receipt_recorded'=>$b->receipt_id!==null];
        },3);
    }
}
