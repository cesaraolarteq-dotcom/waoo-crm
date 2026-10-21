<?php
namespace App\Services\History;
use App\Exceptions\RecordWriteRejected;
use App\Models\{HistoricalEvent,HistoricalEventRevision,HoneyBookEventFacts,User};
final class ReadHoneyBookArchiveReport
{
    public function execute(User $actor):array {
        HistoryAccess::actor($actor);$events=HistoricalEvent::orderBy('id')->limit(10001)->get();
        RecordWriteRejected::unless($events->count()<=10000,409,'El historial requiere un reporte por volumen.');
        $sources=[];$balances=[];$prospects=0;
        foreach($events as $event){
            $p=HistoricalIntegrity::revision($event,HistoricalEventRevision::findOrFail($event->current_revision_id));$f=$p['facts'];$s=$p['source'];
            if(($f['record_kind']??'event')==='prospect')$prospects++;
            $label=trim($s['lead_source_text']??'')?:trim($s['lead_source']);
            if($label!==''){$sources[$label]['projects']=($sources[$label]['projects']??0)+1;$sources[$label]['customers'][$event->customer_id]=true;}
            if(($f['record_kind']??'event')==='event'&&($f['balance_cents']??0)>0&&$f['currency']==='USD'){
                $balances[]=['history_id'=>$event->id,'customer_id'=>$event->customer_id,'source_date'=>$f['source_date'],'project_name'=>$s['project_name'],
                    'balance_cents'=>$f['balance_cents'],'source'=>'honeybook','funds_verified'=>false,'status'=>$f['status']];
            }
        }
        $futureFacts=HoneyBookEventFacts::orderByDesc('version')->limit(10001)->get();
        RecordWriteRejected::unless($futureFacts->count()<=10000,409,'La información de origen requiere un reporte por volumen.');
        foreach($futureFacts->unique('event_id') as $record){
            $event=\App\Models\Event::findOrFail($record->event_id);$read=ReadHoneyBookEventFacts::forEvent($event,false);
            if($read['stale'])continue;$label=trim($read['fields']['lead_source']??'');
            if($label!==''){$sources[$label]['projects']=($sources[$label]['projects']??0)+1;$sources[$label]['customers'][$event->customer_id]=true;}
        }
        ksort($sources);$labels=[];foreach($sources as $label=>$count)$labels[]=['label'=>$label,'projects'=>$count['projects'],'customers'=>count($count['customers'])];
        return ['source'=>'honeybook','historical_projects'=>$events->count()-$prospects,'prospects'=>$prospects,'lead_sources'=>$labels,
            'balances_to_review'=>$balances,'balance_total_cents'=>array_sum(array_column($balances,'balance_cents')),'bank_verified'=>false];
    }
}
