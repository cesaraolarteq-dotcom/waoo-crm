<?php
namespace App\Services\Booking;

use App\Exceptions\RecordWriteRejected;
use App\Models\PaymentIntent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** No session ID does not prove that Stripe never received the prepared request. */
final class QuickPaymentExposure
{
    public static function unresolved(int $eventId): Builder
    {
        return PaymentIntent::where('event_id', $eventId)->where('purpose', 'quick_deposit')
            ->whereNotIn('status', ['expired', 'failed', 'cancelled']);
    }

    /** Caller holds the event lock; a competing checkout must hold that same row before creating its attempt. */
    public static function assertNone(int $eventId): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Payment exposure checks require an event transaction.');
        RecordWriteRejected::unless(!self::unresolved($eventId)->lockForUpdate()->exists(), 409,
            'Hay un intento de cobro pendiente de cerrar o conciliar. Conserva el apartado hasta verificar su resultado.');
    }
}
