<?php

namespace App\Services\Booking;

use App\Models\{Event, QuickStripeCheckoutRequest, QuickStripeLease, User};
use Illuminate\Support\Facades\DB;

final class ReadQuickStripeCheckoutRequest
{
    public function execute(User $actor, int $eventId): array
    {
        $actor = QuickStripeLeases::actor($actor);
        return DB::transaction(function () use ($actor, $eventId) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            QuickStripeLeases::actor($actor);
            Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            $lease = QuickStripeLease::where('event_id', $eventId)->lockForUpdate()->firstOrFail();
            $request = QuickStripeCheckoutRequest::where('quick_stripe_lease_id', $lease->id)->sharedLock()->firstOrFail();
            return QuickStripeCheckoutRequests::view($request, $lease);
        }, 3);
    }
}
