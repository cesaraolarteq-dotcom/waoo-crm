<?php
namespace App\Services\Finance;

use App\Jobs\ExpireTestCheckout;
use App\Models\{PaymentIntent, User};
use App\Services\Audit;
use Illuminate\Support\Facades\Schema;

final class SupersedeCollections
{
    // Caller owns the event row lock; remote expiration is always after commit.
    public static function forEvent(int $eventId, string $reason, ?User $actor = null, ?string $except = null): void
    {
        if (! Schema::hasTable('payment_intents')) return;
        $query = PaymentIntent::where('event_id', $eventId)->whereIn('status', ['creating','open','processing']);
        if ($except) $query->where('id', '!=', $except);
        foreach ($query->orderBy('id')->lockForUpdate()->get() as $intent) {
            $intent->fill(['status' => 'superseded', 'collection_slot' => null, 'last_error' => $reason])->save();
            if ($actor) Audit::recordFor($actor, 'payment.checkout_superseded', $intent, [], ['reason' => $reason]);
            else Audit::recordSystem('payment.checkout_superseded', $intent, [], ['reason' => $reason]);
            if ($intent->stripe_checkout_session_id) ExpireTestCheckout::dispatch($intent->id)->afterCommit();
        }
    }
}
