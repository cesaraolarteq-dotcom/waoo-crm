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
    public static function operations(Event $event):string
    {
        return MutationKey::hash($event->services()->with('characters')->orderBy('id')->get()->map(fn($s)=>['price_version_id'=>$s->price_version_id,'duration_minutes'=>$s->duration_minutes,'requirements'=>$s->requirements_snapshot,
            'characters'=>$s->characters->map(fn($c)=>$c->only(['character_id','quantity','outfit']))->all()])->all());
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
        return ['environment'=>'honeybook_import','source'=>'honeybook','funds_verified'=>false,'financial_revision'=>null,'imported_revision_id'=>$r->id,
            'received_cents'=>$payment['amount_cents'],'refunded_cents'=>0,'net_received_cents'=>$payment['amount_cents'],'balance_cents'=>max(0,$n['balance_cents']),
            'credit_cents'=>max(0,-$n['balance_cents']),'deposit_shortfall_cents'=>max(0,intdiv($n['total_cents']+1,2)-$n['paid_cents']),
            'balance_label'=>'HoneyBook source balance','source_balance_due_date'=>$n['balance_due_date'],'contract_status'=>$n['contract_signed']?'signed_in_honeybook':'not_signed_in_source',
            'capacity_status'=>Event::findOrFail($eventId)->status,'exceptions'=>[]];
    }
}
