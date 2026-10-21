<?php
namespace App\Services\History;
use App\Exceptions\RecordWriteRejected;
use App\Models\{HistoricalCustomerRelink,HistoricalEvent,HistoricalEventRevision};
use App\Services\Finance\MutationKey;
final class HistoricalCustomerBinding
{
    public static function evidence(HistoricalCustomerRelink $record): array {
        try {
            $p=$record->payload;
            RecordWriteRejected::unless(hash_equals($record->sha256,MutationKey::hash($p))
                &&$p['history_id']===$record->historical_event_id&&$p['version']===$record->version
                &&$p['created_by']===$record->created_by&&$p['result']['revision_id']===$record->revision_id
                &&$record->contact_key===($p['contact_id']===''?null:hash('sha256',$p['contact_id'])),409,'La corrección de clienta requiere revisión.');
            $revision=HistoricalEventRevision::findOrFail($record->revision_id);
            RecordWriteRejected::unless($revision->historical_event_id===$record->historical_event_id&&$revision->version===$record->version
                &&$revision->customer_id===$p['result']['customer_id']&&$p['result']['history_id']===$record->historical_event_id
                &&$p['result']['history_version']===$record->version&&($revision->payload['previous_revision_id']??null)===$p['previous_revision_id'],409,'La corrección de clienta requiere revisión.');
            return $p;
        }catch(RecordWriteRejected $e){throw $e;}catch(\Throwable){throw new RecordWriteRejected(409,'La corrección de clienta requiere revisión.');}
    }
    public static function project(string $projectId): ?int {
        if($projectId==='')return null;
        $history=HistoricalEvent::where('project_key',hash('sha256',$projectId))->first();
        if(!$history)return null;
        $correction=HistoricalCustomerRelink::where('historical_event_id',$history->id)->orderByDesc('version')->first();
        if(!$correction)return null;
        $p=self::evidence($correction);
        HistoricalIntegrity::revision($history,HistoricalEventRevision::findOrFail($history->current_revision_id));
        RecordWriteRejected::unless($p['result']['customer_id']===$history->customer_id,409,'La corrección de clienta requiere revisión.');
        return $history->customer_id;
    }
    public static function disputed(string $contactId): bool {
        return $contactId!==''&&HistoricalCustomerRelink::where('contact_key',hash('sha256',$contactId))->exists();
    }
}
