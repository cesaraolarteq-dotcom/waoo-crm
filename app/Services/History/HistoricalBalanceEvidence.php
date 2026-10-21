<?php
namespace App\Services\History;
use App\Exceptions\RecordWriteRejected;
use App\Models\{HistoricalBalanceResolution,HistoricalEvent};
use App\Services\Finance\MutationKey;
final class HistoricalBalanceEvidence
{
    public static function read(HistoricalBalanceResolution $record):array {
        try{$p=$record->payload;
            RecordWriteRejected::unless(($p['schema_version']??null)===1&&$p['history_id']===$record->historical_event_id&&$p['version']===$record->version
                &&$p['created_by']===$record->created_by&&hash_equals($record->sha256,MutationKey::hash($p)),409,'La declaración de saldo requiere revisión.');return $p;
        }catch(RecordWriteRejected $e){throw $e;}catch(\Throwable){throw new RecordWriteRejected(409,'La declaración de saldo requiere revisión.');}
    }
    public static function current(HistoricalEvent $event):?array {
        $record=HistoricalBalanceResolution::where('historical_event_id',$event->id)->orderByDesc('version')->first();
        if(!$record)return null;$p=self::read($record);
        return ['id'=>$record->id,'version'=>$record->version,'kind'=>$p['input']['kind'],'owner_note'=>$p['input']['owner_note'],
            'source_revision_id'=>$p['input']['expected_revision_id'],'stale'=>$p['input']['expected_revision_id']!==$event->current_revision_id,
            'bank_verified'=>false];
    }
}
