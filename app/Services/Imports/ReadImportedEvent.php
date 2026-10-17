<?php
namespace App\Services\Imports;
use App\Models\{Event,ImportedLedgerLine,ImportedProjectLink,ImportedProjectRevision,User};
final class ReadImportedEvent
{
    public function execute(User $actor,int $eventId):array
    {
        ImportInput::actor($actor);$link=ImportedProjectLink::where('event_id',$eventId)->firstOrFail();$r=ImportedProjectRevision::findOrFail($link->current_revision_id);
        return ['link'=>$link,'revision'=>$r,'ledger'=>ImportedLedgerLine::where('imported_project_revision_id',$r->id)->orderBy('source_position')->get(),'payment_display'=>ImportedLedgerLine::where('imported_project_revision_id',$r->id)->orderBy('source_position')->get()->map(fn($line)=>['id'=>$line->id,...HoneyBookPaymentLabels::display($line->method,$r->mapping['payment_label_map_version']??null)]),'summary'=>ImportedAgreement::summary($eventId),'event'=>Event::findOrFail($eventId)->only(['id','version','status','starts_at','ends_at'])];
    }
}
