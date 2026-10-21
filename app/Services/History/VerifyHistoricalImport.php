<?php
namespace App\Services\History;
use App\Models\{HistoricalEvent,HistoricalEventRevision,HistoryImportDecision,HistoryImportRow,User};
use App\Services\Finance\MutationKey;
use App\Services\Imports\{ImportCsv,ImportInput,ImportPersistence};
final class VerifyHistoricalImport
{
    public function execute(User $actor,int $batchId):array {
        HistoryAccess::actor($actor);
        return ImportPersistence::transaction(function()use($actor,$batchId){
            ImportInput::lock();HistoryAccess::actor($actor);$batch=ReadHistoricalImport::batch($batchId);$m=HistoryEnvelope::batch($batch);
            $parsed=app(ImportCsv::class)->parse($batch->artifact['bytes'],'projects',['encoding'=>'utf-8','delimiter'=>',','columns'=>[]]);
            $original=[];foreach($parsed['rows'] as $source)$original[$source['line']]=array_combine($parsed['headers'],$source['cells']);
            $differences=[];$imported=$excluded=$pending=0;$rows=HistoryImportRow::where('history_import_batch_id',$batchId)->orderBy('id')->get();
            foreach($rows as $row){
                $p=HistoryEnvelope::row($row,$m);$source=$original[$row->source_line]??null;
                if(!$source||MutationKey::hash($source)!==MutationKey::hash($p['source']))$differences[]=['row_id'=>$row->id,'code'=>'staged_source_differs'];
                if(!isset($p['decision'])){$pending++;continue;}
                $decision=HistoricalIntegrity::decision(HistoryImportDecision::findOrFail($p['decision']['id']));
                if($decision['result']['state']==='excluded'){$excluded++;continue;}
                $result=$decision['result'];$event=HistoricalEvent::findOrFail($result['history_id']);$revision=HistoricalEventRevision::findOrFail($result['revision_id']);
                $copy=HistoricalIntegrity::revision($event,$revision,false);$imported++;
                if(MutationKey::hash($source??[])!==MutationKey::hash($copy['source']))$differences[]=['row_id'=>$row->id,'code'=>'destination_source_differs'];
                foreach(['booked_total','paid_total','balance'] as $field){
                    $raw=$source[$field]??'';$expected=null;
                    if(($source['currency']??'')==='USD'&&preg_match('/^(-?)([0-9]{1,9})\.([0-9]{2})$/D',$raw,$parts))$expected=($parts[1]==='-'?-1:1)*(((int)$parts[2])*100+(int)$parts[3]);
                    if(($copy['facts'][$field.'_cents']??null)!==$expected)$differences[]=['row_id'=>$row->id,'code'=>$field.'_conversion_differs'];
                }
                if(($copy['facts']['source_date']??null)!==(($source['event_date']??'')?:null))$differences[]=['row_id'=>$row->id,'code'=>'source_date_differs'];
            }
            if(count($original)!==$rows->count())$differences[]=['row_id'=>null,'code'=>'row_coverage_differs'];
            return ['batch_id'=>$batchId,'batch_version'=>$batch->version,'source_sha256'=>$batch->source_sha256,'source_rows'=>count($original),
                'imported'=>$imported,'excluded'=>$excluded,'pending'=>$pending,'differences'=>$differences,
                'verdict'=>$differences?'differences':($pending?'pending_decisions':'copied_fields_match'),
                'human_independent_reviews_complete'=>false];
        });
    }
}
