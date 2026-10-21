<?php
namespace App\Services\Booking;
use App\Exceptions\RecordWriteRejected;
use App\Models\ZelleNotice;
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Portal\PortalContext;
use Illuminate\Support\Facades\DB;
final class SubmitQuickPaymentZelle
{
    public function execute(PortalContext $client,array $input,string $key):ZelleNotice
    {
        QuickPaymentProtocol::gate();
        $d=QuickPaymentProtocol::input($input,['amount_cents'=>'required|integer|min:1|max:100000000','reference'=>'nullable|string|min:3|max:255']);
        $d['amount_cents']=(int)$d['amount_cents'];$d['reference']=$d['reference']??null;
        $scope="portal:{$client->grantId}:quick-payment-zelle";$hash=MutationKey::hash($d);
        return DB::transaction(function()use($client,$d,$key,$scope,$hash){
            [$b,$e]=QuickPaymentProtocol::portal($client);
            if($r=MutationKey::find($scope,$key,$hash,true))return ZelleNotice::findOrFail($r->result_id);
            QuickPaymentProtocol::pin($b,$d);QuickPaymentProtocol::submission($b,$e);
            RecordWriteRejected::unless($b->state==='awaiting_payment'&&$b->expires_at->isFuture()&&!QuickPaymentProtocol::pendingNotice($b),409,'El apartado cambió o venció. / This hold changed or expired.');
            RecordWriteRejected::unless(!\App\Models\PaymentIntent::where('event_id',$e->id)->whereNotIn('status',['expired','failed','cancelled'])->exists(),409,'Hay un pago de Stripe pendiente. / A Stripe payment is pending.');
            $n=ZelleNotice::create(['event_id'=>$e->id,'customer_id'=>$client->customerId,'portal_grant_id'=>$client->grantId,
                'financial_revision_id'=>$b->financial_revision_id,'amount_cents'=>$d['amount_cents'],'reference'=>$d['reference'],
                'ip'=>$client->ip,'user_agent'=>$client->userAgent,'submitted_at'=>now()]);
            Audit::recordSystem('portal.zelle_notice_submitted',$n,[],['event_id'=>$e->id,'portal_grant_id'=>$client->grantId,'amount_cents'=>$n->amount_cents]);
            QuickPaymentProtocol::transition($b,'zelle_pending','client_claim_not_receipt');
            MutationKey::store($scope,$key,$hash,'zelle_notice',$n->id);return $n;
        },3);
    }
}
