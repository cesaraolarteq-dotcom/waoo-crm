<?php
namespace App\Services\History;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Event,HoneyBookEventFacts,ImportedProjectLink,User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Imports\{ImportInput,ImportPersistence};
final class SaveHoneyBookEventFacts
{
    public function execute(User $actor,int $eventId,#[\SensitiveParameter] array $input,string $key):array {
        HistoryAccess::actor($actor);
        $d=ImportInput::input($input,['expected_event_version'=>'required|integer|min:1','expected_facts_version'=>'required|integer|min:0','imported_revision_id'=>'required|integer|min:1',
            'fields'=>'required|array:child_name,child_age,children_count,setup_area,event_language,notes,lead_source,sms_consent_honeybook',
            'fields.child_name'=>'sometimes|nullable|string|max:120','fields.child_age'=>'sometimes|nullable|integer|min:0|max:120',
            'fields.children_count'=>'sometimes|nullable|integer|min:1|max:500','fields.setup_area'=>'sometimes|nullable|in:indoor,outdoor',
            'fields.event_language'=>'sometimes|nullable|in:es,en,bilingual','fields.notes'=>'sometimes|nullable|string|max:2000',
            'fields.lead_source'=>'sometimes|nullable|string|max:255','fields.sms_consent_honeybook'=>'sometimes|nullable|string|max:2000']);
        foreach(['expected_event_version','expected_facts_version','imported_revision_id'] as $f)$d[$f]=(int)$d[$f];
        foreach(['child_age','children_count'] as $f)if(isset($d['fields'][$f]))$d['fields'][$f]=(int)$d['fields'][$f];
        $hash=MutationKey::hash($d);$scope='owner:'.$actor->id.':history:event:'.$eventId.':facts';
        return ImportPersistence::transaction(function()use($actor,$eventId,$d,$hash,$scope,$key){
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();ImportInput::lock();HistoryAccess::actor($actor);
            if($r=MutationKey::find($scope,$key,$hash,true))return ReadHoneyBookEventFacts::metadata(HoneyBookEventFacts::findOrFail($r->result_id));
            $event=Event::whereKey($eventId)->lockForUpdate()->firstOrFail();$link=ImportedProjectLink::where('event_id',$eventId)->lockForUpdate()->firstOrFail();
            RecordWriteRejected::unless($event->version===$d['expected_event_version']&&$link->current_revision_id===$d['imported_revision_id']&&$link->customer_id===$event->customer_id,409,'La fiesta o su evidencia de origen cambió.');
            $revision=\App\Models\ImportedProjectRevision::findOrFail($link->current_revision_id);
            RecordWriteRejected::unless($revision->imported_project_link_id===$link->id,409,'La revisión de origen requiere revisión.');
            $last=HoneyBookEventFacts::where('event_id',$eventId)->orderByDesc('version')->first();
            RecordWriteRejected::unless(($last?->version??0)===$d['expected_facts_version'],409,'Los datos de origen cambiaron. Revisa antes de guardar.');
            if($last){$p=ReadHoneyBookEventFacts::payload($last);if($p['fields']===$d['fields']&&$p['imported_revision_id']===$link->current_revision_id){MutationKey::store($scope,$key,$hash,'honeybook_event_facts',$last->id);return ReadHoneyBookEventFacts::metadata($last);}}
            $version=($last?->version??0)+1;
            $payload=['profile'=>'honeybook-event-facts-v1','event_id'=>$eventId,'customer_id'=>$event->customer_id,'project_key'=>$link->project_key,
                'imported_revision_id'=>$link->current_revision_id,'version'=>$version,'created_by'=>$actor->id,'fields'=>$d['fields']];
            $record=HoneyBookEventFacts::create(['event_id'=>$eventId,'customer_id'=>$event->customer_id,'version'=>$version,'payload'=>$payload,'sha256'=>MutationKey::hash($payload),'created_by'=>$actor->id]);
            Audit::recordFor($actor,'history.event_facts_saved',$record,[],['event_id'=>$eventId,'version'=>$version]);
            MutationKey::store($scope,$key,$hash,'honeybook_event_facts',$record->id);return ReadHoneyBookEventFacts::metadata($record);
        });
    }
}
