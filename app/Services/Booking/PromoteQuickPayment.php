<?php
namespace App\Services\Booking;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Event,LedgerEntry,QuickPaymentBooking,QuickBookingRecord,QuickCostumeHold,QuickStripeLease,User,PortalGrant};
use Illuminate\Support\Facades\DB;
final class PromoteQuickPayment
{
    /** Caller holds event + aggregate locks. Real receipt is durable in the same transaction. */
    public function execute(QuickPaymentBooking $b,Event $e,LedgerEntry $receipt,?User $actor=null):void
    {
        RecordWriteRejected::unless(DB::transactionLevel()>0&&$receipt->event_id===$e->id&&$receipt->financial_revision_id===$b->financial_revision_id&&$receipt->kind==='receipt',409,'El pago no corresponde.');
        $lease=QuickStripeLease::whereKey($b->quick_stripe_lease_id)->lockForUpdate()->firstOrFail();
        $summary=app(\App\Services\Finance\FinanceSummary::class)->forEvent($e);
        $grant=PortalGrant::whereKey($b->portal_grant_id)->lockForUpdate()->first();
        $ownerReviewedZelle=$actor!==null && $receipt->source_type==='owner_verified' && $receipt->method==='zelle' && $receipt->verified_by===$actor->id && QuickPaymentProtocol::pendingNotice($b)!==null;
        $valid=$grant && !$grant->revoked_at && $grant->expires_at->isFuture() && $b->receipt_id===null&&($b->expires_at->isFuture()||$ownerReviewedZelle)&&$e->starts_at->isFuture()&&$e->responsible_system==='crm'&&$lease->state==='active'&&$summary['deposit_shortfall_cents']===0;
        try{
            QuickPaymentProtocol::verify($b,$e);
            $p=QuickStripeLeases::verify($lease,$e,false,true);QuickStripeLeases::lockInventory($p);
            $p=QuickStripeLeases::verify($lease,$e,true,true);
        }catch(RecordWriteRejected){$valid=false;}
        if(!$valid){
            if($b->state!=='paid')QuickPaymentProtocol::transition($b,'paid_needs_review','real_payment_requires_owner_review',$actor,['receipt_id'=>$b->receipt_id??$receipt->id]);
            DB::table('payment_exceptions')->insertOrIgnore(['event_id'=>$e->id,'financial_revision_id'=>$b->financial_revision_id,'kind'=>'quick_payment_review',
                'exception_key'=>hash('sha256','quick-payment:'.$receipt->id),'state'=>'open','context'=>json_encode(['booking_id'=>$b->id,'receipt_id'=>$receipt->id]),'created_at'=>now(),'updated_at'=>now()]);return;
        }
        $g=PortalGrant::findOrFail($b->portal_grant_id);
        $q=QuickBookingRecord::create(['event_id'=>$e->id,'customer_id'=>$b->customer_id,'initial_financial_revision_id'=>$b->financial_revision_id,'receipt_id'=>$receipt->id,
            'created_by'=>$b->created_by,'portal_grant_id'=>$g->id,'portal_expires_at'=>$g->expires_at]);
        $h=QuickCostumeHold::create(['event_id'=>$e->id,'quick_booking_id'=>$q->id,'receipt_id'=>$receipt->id,'version'=>0,'state'=>'held','payload'=>[],'payload_sha256'=>str_repeat('0',64)]);
        QuickCostumeHolds::transition($actor,$e,$h,$p['issues']? 'review_required':'held',$p['rows'],$p['issues'],'verified_payment_replaces_unpaid_lease');
        QuickPaymentProtocol::transition($b,'paid','verified_payment',$actor,['receipt_id'=>$receipt->id,'quick_booking_id'=>$q->id]);
        QuickStripeLeases::append($actor,$lease,[...$p,'state'=>'promoted','rows'=>[],'reason'=>'verified_payment_replaces_unpaid_lease']);
    }
}
