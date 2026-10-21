<?php
namespace App\Services\Booking;
use App\Models\{Event, QuickStripeLease, User};
use Illuminate\Support\Facades\DB;
final class ReadQuickStripeLease
{
    public function execute(User $actor,int $eventId):array
    {
        QuickStripeLeases::actor($actor);
        return DB::transaction(function()use($actor,$eventId){
            QuickStripeLeases::actor($actor);$event=Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            return QuickStripeLeases::view(QuickStripeLease::where('event_id',$eventId)->lockForUpdate()->firstOrFail(),$event);
        },3);
    }
}
