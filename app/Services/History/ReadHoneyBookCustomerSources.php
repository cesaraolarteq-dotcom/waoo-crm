<?php
namespace App\Services\History;
use App\Models\{Customer,Event,HistoricalEvent,HistoricalEventRevision,HoneyBookEventFacts,User};
use App\Exceptions\RecordWriteRejected;
final class ReadHoneyBookCustomerSources
{
    public function execute(User $actor,int $customerId):array {
        HistoryAccess::actor($actor);Customer::findOrFail($customerId);$sources=[];$consents=[];
        $history=HistoricalEvent::where('customer_id',$customerId)->limit(1001)->get();
        $facts=HoneyBookEventFacts::where('customer_id',$customerId)->orderByDesc('version')->limit(1001)->get();
        RecordWriteRejected::unless($history->count()<=1000&&$facts->count()<=1000,409,'La información de origen requiere una lectura por volumen.');
        foreach($history as $event){$p=HistoricalIntegrity::revision($event,HistoricalEventRevision::findOrFail($event->current_revision_id));$s=$p['source'];
            $label=trim($s['lead_source_text']??'')?:trim($s['lead_source']);if($label!=='')$sources[$label]=true;
            if(($s['sms_consent_honeybook']??'')!=='')$consents[]=['history_id'=>$event->id,'raw_value'=>$s['sms_consent_honeybook'],'source'=>'honeybook'];
        }
        foreach($facts->unique('event_id') as $record){$event=Event::findOrFail($record->event_id);if($event->customer_id!==$customerId)continue;$read=ReadHoneyBookEventFacts::forEvent($event,false);if($read['stale'])continue;$s=$read['fields'];
            if(trim($s['lead_source']??'')!=='')$sources[trim($s['lead_source'])]=true;
            if(($s['sms_consent_honeybook']??'')!=='')$consents[]=['event_id'=>$event->id,'raw_value'=>$s['sms_consent_honeybook'],'source'=>'honeybook'];
        }
        return ['customer_id'=>$customerId,'source'=>'honeybook','lead_sources'=>array_keys($sources),'sms_consent_evidence'=>$consents,'native_contact_overwritten'=>false];
    }
}
