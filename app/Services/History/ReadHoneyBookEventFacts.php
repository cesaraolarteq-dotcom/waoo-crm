<?php
namespace App\Services\History;
use App\Exceptions\RecordWriteRejected;
use App\Models\{ClientDetailsSubmission,Event,HoneyBookEventFacts,ImportedProjectLink,User};
use App\Services\Finance\MutationKey;
final class ReadHoneyBookEventFacts
{
    public const STAFF_FIELDS=['child_name','child_age','children_count','setup_area','event_language','notes'];
    public function execute(User $actor,int $eventId):array {HistoryAccess::actor($actor);$link=ImportedProjectLink::where('event_id',$eventId)->first();$event=Event::findOrFail($eventId);return [...self::forEvent($event,false),'event_version'=>$event->version,'imported_revision_id'=>$link?->current_revision_id,'originals'=>$link?ReadHoneyBookOriginal::listing($link->project_key):[]];}
    public static function metadata(HoneyBookEventFacts $record):array {self::payload($record);return ['id'=>$record->id,'event_id'=>$record->event_id,'version'=>$record->version,'source'=>'honeybook'];}
    public static function payload(HoneyBookEventFacts $record):array {
        try {
            $p=$record->payload;
            RecordWriteRejected::unless(($p['profile']??null)==='honeybook-event-facts-v1'&&$p['event_id']===$record->event_id&&$p['customer_id']===$record->customer_id&&$p['version']===$record->version&&$p['created_by']===$record->created_by&&hash_equals($record->sha256,MutationKey::hash($p)),409,'La evidencia HoneyBook requiere revisión.');return $p;
        }catch(RecordWriteRejected $e){throw $e;}catch(\Throwable){throw new RecordWriteRejected(409,'La evidencia HoneyBook requiere revisión.');}
    }
    /** Internal narrow staff projection, called only after real assignment authorization. */
    public static function forEvent(Event $event,bool $staff=true):array {
        if(!config('waoo.sandbox_history'))return ['source'=>'honeybook','version'=>0,'fields'=>[],'stale'=>false];
        $record=HoneyBookEventFacts::where('event_id',$event->id)->orderByDesc('version')->first();
        if(!$record)return ['source'=>'honeybook','version'=>0,'fields'=>[],'stale'=>false];
        $p=self::payload($record);$link=ImportedProjectLink::where('event_id',$event->id)->first();
        $stale=!$link||$link->current_revision_id!==$p['imported_revision_id']||$link->project_key!==$p['project_key']||$event->customer_id!==$p['customer_id'];
        $fields=$p['fields'];if($staff){$fields=array_intersect_key($fields,array_flip(self::STAFF_FIELDS));
            $native=ClientDetailsSubmission::where('event_id',$event->id)->orderByDesc('revision')->first()?->data??[];
            foreach($native as $key=>$value)if($value!==null&&$value!=='')unset($fields[$key]);
            if($stale)$fields=[];
        }
        return ['source'=>'honeybook','version'=>$record->version,'fields'=>$fields,'stale'=>$stale];
    }
}
