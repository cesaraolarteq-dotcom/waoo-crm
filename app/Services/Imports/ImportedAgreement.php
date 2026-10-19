<?php
namespace App\Services\Imports;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Event,ImportedLedgerLine,ImportedProjectLink,ImportedProjectRevision};
use App\Services\Finance\MutationKey;
final class ImportedAgreement
{
    public static function blockCrmLifecycle(int $eventId):void
    {
        RecordWriteRejected::unless(!ImportedProjectLink::where('event_id',$eventId)->exists(),409,'Este evento conserva su acuerdo de HoneyBook. Requiere conciliación de corte antes de crear cobros, enlaces o contratos nuevos.');
    }
    public static function operations(Event $event,bool $current=false):string
    {
        $query=$event->services()->with(['characters'=>fn($q)=>$current?$q->sharedLock():$q])->orderBy('id');
        if($current)$query->sharedLock();
        return MutationKey::hash($query->get()->map(fn($s)=>['price_version_id'=>$s->price_version_id,'duration_minutes'=>$s->duration_minutes,'requirements'=>$s->requirements_snapshot,
            'characters'=>$s->characters->map(fn($c)=>$c->only(['character_id','quantity','outfit']))->all()])->all());
    }
    /** Money revisions retain the original capacity source; never create new booking evidence. */
    public static function capacityMatches(Event $event,ImportedProjectRevision $revision,bool $current=false):bool
    {
        $id=$revision->mapping['money_resync']['capacity_revision_id']??$revision->id;
        $query=ImportedProjectRevision::whereKey($id)->where('imported_project_link_id',$revision->imported_project_link_id);
        $base=($current?$query->sharedLock():$query)->first();
        if(!$base||$event->status!=='capacity_reserved'||$event->version!==$base->event_version+1)return false;
        $e=\App\Models\BookingEvidence::where('event_id',$event->id)->where('method','honeybook_import')->where('imported_revision_id',$base->id);
        $evidence=($current?$e->sharedLock():$e)->first();if(!$evidence)return false;
        $q=$event->reservations()->orderBy('id');$reservations=($current?$q->sharedLock():$q)->get();
        if(array_diff(array_column($evidence->staff_assignment_snapshot['assignments']??[],'resource_id'),$reservations->pluck('resource_id')->all())!==[])return false;
        return $reservations->isNotEmpty()&&$reservations->every(fn($r)=>$r->starts_at->equalTo($event->starts_at)&&$r->ends_at->equalTo($event->ends_at));
    }
    public static function payment(ImportedProjectLink $link,ImportedProjectRevision $r):array
    {
        $sum=(int)ImportedLedgerLine::where('imported_project_revision_id',$r->id)->sum('amount_cents');
        RecordWriteRejected::unless($sum===$r->normalized['paid_cents'],409,'El dinero histórico no coincide con su revisión importada.');
        return ['method'=>'honeybook_import','reference'=>'honeybook:'.$link->project_key.':'.$r->id,'amount_cents'=>$sum,'received_at'=>null,
            'received_date'=>ImportedLedgerLine::where('imported_project_revision_id',$r->id)->max('received_date'),'imported_revision_id'=>$r->id];
    }
    public static function summary(int $eventId):?array
    {
        $link=ImportedProjectLink::where('event_id',$eventId)->first();if(!$link)return null;$r=ImportedProjectRevision::findOrFail($link->current_revision_id);
        $payment=self::payment($link,$r);$n=$r->normalized;
        $lines=ImportedLedgerLine::where('imported_project_revision_id',$r->id)->get();
        $gross=(int)$lines->where('amount_cents','>',0)->sum('amount_cents');$refunds=-(int)$lines->where('amount_cents','<',0)->sum('amount_cents');
        return ['environment'=>'honeybook_import','source'=>'honeybook','funds_verified'=>false,'financial_revision'=>null,'imported_revision_id'=>$r->id,
            'received_cents'=>$gross,'refunded_cents'=>$refunds,'net_received_cents'=>$payment['amount_cents'],'balance_cents'=>max(0,$n['balance_cents']),
            'credit_cents'=>max(0,-$n['balance_cents']),'deposit_shortfall_cents'=>max(0,intdiv($n['total_cents']+1,2)-$n['paid_cents']),
            'balance_label'=>'HoneyBook source balance','source_balance_due_date'=>$n['balance_due_date'],'contract_status'=>$n['contract_signed']?'signed_in_honeybook':'not_signed_in_source',
            'capacity_status'=>Event::findOrFail($eventId)->status,'exceptions'=>[]];
    }
}
