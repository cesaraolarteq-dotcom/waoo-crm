<?php
namespace App\Services\Tips;
use App\Models\{Event,TipReceipt,TipRefund,User};
use Illuminate\Support\Facades\DB;
final class ReadEventTips {
    public function execute(User $actor,int $eventId):array {
        TipGuard::owner($actor);
        return DB::transaction(function()use($eventId){Event::whereKey($eventId)->lockForUpdate()->firstOrFail();$rows=TipReceipt::where('event_id',$eventId)->orderBy('id')->get();
            $refunds=TipRefund::whereIn('tip_receipt_id',$rows->pluck('id'))->get()->groupBy('tip_receipt_id');
            $data=$rows->map(fn($r)=>['id'=>$r->id,'gross_cents'=>$r->gross_cents,'currency'=>$r->currency,'received_at'=>$r->received_at->toIso8601String(),'refunded_cents'=>(int)($refunds->get($r->id)?->sum('amount_cents')??0)])->all();
            $gross=array_sum(array_column($data,'gross_cents'));$back=array_sum(array_column($data,'refunded_cents'));
            $pending=\App\Models\TipCheckoutIntent::where('event_id',$eventId)->whereNotIn('status',['paid','expired','failed'])->count();
            $issues=DB::table('tip_reconciliations as r')->join('tip_checkout_intents as i','i.id','=','r.tip_checkout_intent_id')->where('i.event_id',$eventId)->where('r.status','open')->count();
            return ['receipts'=>$data,'gross_cents'=>$gross,'refunded_cents'=>$back,'net_staff_cents'=>($pending||$issues)?null:$gross-$back,
                'pending_checkout_count'=>$pending,'reconciliation_count'=>$issues,'reconciliation_required'=>($pending+$issues)>0];},3);
    }
}
