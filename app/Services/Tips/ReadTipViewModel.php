<?php
namespace App\Services\Tips;
use App\Models\{BookingEvidence,Resource,TipCheckoutIntent,TipReceipt};
use Illuminate\Support\Facades\DB;
final class ReadTipViewModel {
    public function read(TipContext $context):array {
        return DB::transaction(function()use($context){
            [$g,$e]=$context->authorize();$locale=$context->locale;$first=fn($name)=>preg_split('/[\s\p{Z}]+/u',trim((string)$name),2)[0]??'';
            $evidence=BookingEvidence::where('event_id',$e->id)->latest('id')->first();$ids=collect($evidence?->staff_assignment_snapshot['assignments']??[])->where('kind','staff')->pluck('resource_id')->unique()->all();
            $reserved=$e->reservations()->where('starts_at',$e->starts_at)->where('ends_at',$e->ends_at)->pluck('resource_id');
            $team=Resource::where('kind','staff')->whereIn('id',$ids)->whereIn('id',$reserved)->orderBy('id')->pluck('name')->map($first)->unique()->implode(', ');
            $paid=false;
            if($context->checkoutId){$i=TipCheckoutIntent::whereKey($context->checkoutId)->where('tip_grant_id',$g->id)->firstOrFail();$paid=TipReceipt::where('tip_checkout_intent_id',$i->id)->exists();}
            $query=$context->checkoutId?'&checkout='.rawurlencode($context->checkoutId):'';
            $review=config('waoo.mail_review_url');if(!is_string($review)||parse_url($review,PHP_URL_SCHEME)!=='https'||!filter_var($review,FILTER_VALIDATE_URL)||parse_url($review,PHP_URL_USER)||parse_url($review,PHP_URL_PASS))$review=null;
            return ['lang'=>$locale,'lang_url'=>['es'=>'/portal/tip?lang=es'.$query,'en'=>'/portal/tip?lang=en'.$query],'test_mode'=>true,'preview'=>false,
                'first_name'=>$first($e->customer->name),'review_url'=>$review,'tip'=>['state'=>$paid?'paid':'open','when'=>$e->starts_at->setTimezone('America/Chicago')->locale($locale)->isoFormat($locale==='es'?'D [de] MMMM [de] YYYY':'MMMM D, YYYY'),
                    'team'=>$team,'total'=>TipAmounts::money($g->total_cents),'financial_revision_id'=>$g->financial_revision_id,'snapshot_sha256'=>$g->snapshot_sha256,'options'=>TipAmounts::options($g->total_cents)],'actions'=>['checkout'=>'/portal/api/tip/checkout']];
        },3);
    }
}
