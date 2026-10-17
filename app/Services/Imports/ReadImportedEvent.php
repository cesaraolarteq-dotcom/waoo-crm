<?php
namespace App\Services\Imports;
use App\Models\{Event,ImportedLedgerLine,ImportedProjectLink,ImportedProjectRevision,User};
final class ReadImportedEvent
{
    public function execute(User $actor,int $eventId):array
    {
        ImportInput::actor($actor);$link=ImportedProjectLink::where('event_id',$eventId)->firstOrFail();$r=ImportedProjectRevision::findOrFail($link->current_revision_id);
        return ['link'=>$link,'revision'=>$r,'ledger'=>ImportedLedgerLine::where('imported_project_revision_id',$r->id)->orderBy('source_position')->get(),'summary'=>ImportedAgreement::summary($eventId),'event'=>Event::findOrFail($eventId)->only(['id','version','status','starts_at','ends_at'])];
    }
}
