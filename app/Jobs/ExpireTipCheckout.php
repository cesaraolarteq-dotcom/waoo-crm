<?php
namespace App\Jobs;
use App\Models\{Event,TipCheckoutIntent};
use App\Services\Finance\{Sandbox,StripeGateway};
use App\Services\Tips\{TipGuard,TipProvider};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
class ExpireTipCheckout implements ShouldQueue {
    use Queueable;public int $tries=3;public int $timeout=40;
    public function __construct(public string $intentId){}
    public function handle():void {
        Sandbox::require();$i=TipCheckoutIntent::findOrFail($this->intentId);if(!$i->provider_session_id||in_array($i->status,['paid','expired','failed'],true))return;
        $gateway=app(StripeGateway::class);$s=$gateway->retrieveCheckout($i->provider_session_id);
        if(!TipProvider::binding($i,$s)){TipGuard::reconcile($i->id,null,'expiry_binding_mismatch');return;}
        if(($s['payment_status']??null)==='paid'){TipGuard::reconcile($i->id,null,'paid_checkout_needs_verified_receipt');return;}
        if(($s['status']??null)==='open'){$gateway->expireCheckout($s['id']);$s=$gateway->retrieveCheckout($s['id']);}
        // Unknown expiry never releases the collection slot or asserts a refund.
        if(($s['status']??null)!=='expired'||!TipProvider::binding($i,$s)){TipGuard::reconcile($i->id,null,'external_expiry_unconfirmed');return;}
        DB::transaction(function()use($i){Event::whereKey($i->event_id)->lockForUpdate()->firstOrFail();$current=TipCheckoutIntent::whereKey($i->id)->lockForUpdate()->firstOrFail();if($current->status!=='paid'){$current->fill(['status'=>'expired','active_grant_id'=>null,'active_event_id'=>null,'checkout_url'=>null])->save();DB::table('tip_reconciliations')->where('tip_checkout_intent_id',$i->id)->where('status','open')->whereIn('reason',['checkout_expiry_requires_verification','external_expiry_unconfirmed','grant_revoked_with_collection'])->update(['status'=>'resolved','updated_at'=>now()]);}},3);
    }
}
