<?php
namespace App\Services\Booking;
use App\Models\{Event, User};
use Illuminate\Support\Facades\DB;
final class ReadQuickBookingCostumeHold
{
    public function execute(User $actor, int $eventId): array
    {
        QuickCostumeHolds::actor($actor);
        return DB::transaction(function () use ($actor, $eventId) {
            QuickCostumeHolds::actor($actor); $e = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            return QuickCostumeHolds::view($e);
        }, 3);
    }
}
