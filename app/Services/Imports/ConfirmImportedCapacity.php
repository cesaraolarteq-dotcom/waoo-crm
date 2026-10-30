<?php
namespace App\Services\Imports;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Event,ImportedProjectLink,ImportedProjectRevision,User};
use App\Services\{Audit,ConfirmBooking};
use App\Services\Finance\MutationKey;
use App\Services\Records\ConfirmCapacity;
final class ConfirmImportedCapacity
{
    public function execute(User $actor,int $eventId,array $input,string $key):Event
    {
        $actor=ImportInput::actor($actor);$rules=ConfirmCapacity::rules();foreach(array_keys($rules) as $f)if($f==='payment'||str_starts_with($f,'payment.'))unset($rules[$f]);$rules['imported_revision_id']='required|integer|min:1';
        $d=ImportInput::input($input,$rules);$d['expected_version']=(int)$d['expected_version'];$d['imported_revision_id']=(int)$d['imported_revision_id'];$d['resource_ids']=array_map('intval',$d['resource_ids']);sort($d['resource_ids']);
        $hash=MutationKey::hash($d);$scope="owner:{$actor->id}:import:event:{$eventId}:capacity";
        return ImportPersistence::transaction(function()use($actor,$eventId,$d,$hash,$scope,$key){
            \App\Services\Scheduling\DailyCapacity::lock();
            $event=Event::whereKey($eventId)->lockForUpdate()->firstOrFail();ImportInput::actor($actor);
            if(MutationKey::find($scope,$key,$hash))return $event;
            $link=ImportedProjectLink::where('event_id',$eventId)->lockForUpdate()->firstOrFail();
            RecordWriteRejected::unless($link->current_revision_id===$d['imported_revision_id'],409,'La revisión importada cambió.');$r=ImportedProjectRevision::findOrFail($link->current_revision_id);
            $saved=Audit::metadataOnly(fn()=>app(ConfirmBooking::class)->execute($eventId,[...$d,'payment'=>ImportedAgreement::payment($link,$r)],$actor));
            Audit::recordFor($actor,'import.capacity_confirmed',$link,[],['imported_revision_id'=>$r->id,'event_version'=>$saved->version]);MutationKey::store($scope,$key,$hash,'event',$eventId);return $saved;
        },3);
    }
}
