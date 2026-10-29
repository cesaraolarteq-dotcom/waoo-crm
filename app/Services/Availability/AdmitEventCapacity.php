<?php
namespace App\Services\Availability;

use App\Exceptions\RecordWriteRejected;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One capacity authority for the paths that commit a party before or with money (owner 2026-10-29; contract
 * docs/BOOKING-FEASIBILITY-CONTRACT.md "Admisión atómica antes de pago", steps 2–3): unpaid Stripe hold, Zelle quick
 * booking and "Apartar recursos y confirmar".
 * Lock order (never reversed): user → event → lease → capacity days (Houston dates, ascending) → characters → resources.
 * Two requests for the last place serialize on the shared day rows, re-read every commitment and exactly one fits.
 * Owner paths refuse only what cannot physically happen (4 parties at once, 10 per day, a costume or equipment unit
 * that is not free); people shortage, unproven travel or calendar-only entries stay the owner's call (he is the approver).
 */
final class AdmitEventCapacity
{
    /** Busy-time padding beyond the party when timing is unknown, plus the travel review margin. */
    private const MARGIN_MINUTES = WebsiteCapacity::UNKNOWN_MINUTES + WebsiteCapacity::GAP_MINUTES + WebsiteCapacity::TRAVEL_REVIEW_MINUTES;

    /** Locks every Houston day a party between $start and $end can touch. Must run inside the caller's transaction. */
    public static function lockDays(CarbonImmutable $start, CarbonImmutable $end): void
    {
        RecordWriteRejected::unless(DB::transactionLevel() > 0, 409, 'No se pudo revisar la agenda.');
        $first = $start->subMinutes(self::MARGIN_MINUTES)->setTimezone('America/Chicago')->startOfDay();
        $last = $end->addMinutes(self::MARGIN_MINUTES)->setTimezone('America/Chicago')->startOfDay();
        $days = [];
        for ($d = $first; $d <= $last && count($days) < 4; $d = $d->addDay()) $days[] = $d->toDateString();
        RecordWriteRejected::unless($d > $last, 422, 'La fiesta es demasiado larga para revisarla automáticamente.');
        DB::table('capacity_day_locks')->insertOrIgnore(array_map(fn ($day) => ['day' => $day], $days));
        DB::table('capacity_day_locks')->whereIn('day', $days)->orderBy('day')->lockForUpdate()->get();
    }

    /** 'available' | 'owner_approval_required' | 'unavailable' | 'review_required' for this party against everything else. */
    public static function state(Event $event, ?array $calendar = []): string
    {
        $start = CarbonImmutable::instance($event->starts_at); $end = CarbonImmutable::instance($event->ends_at);
        self::lockDays($start, $end);
        return self::evaluate($event, $start, $end, $calendar);
    }

    /**
     * Read-only preview for a reschedule ("¿Está libre esta fecha?"): the same party (package, characters, duration, setup)
     * at another start, against everything else, without locks or writes. Not a hold: the change re-checks under locks.
     */
    public static function preview(Event $event, CarbonImmutable $start, ?array $calendar, ?callable $drive = null): string
    {
        $end = $start->addSeconds(CarbonImmutable::instance($event->starts_at)->diffInSeconds(CarbonImmutable::instance($event->ends_at)));
        return self::evaluate($event, $start, $end, $calendar, $drive);
    }

    private static function evaluate(Event $event, CarbonImmutable $start, CarbonImmutable $end, ?array $calendar, ?callable $drive = null): string
    {
        if ($calendar === null) return 'review_required'; // the outside calendar did not answer: never "free"
        $requirements = $event->services()->get()->flatMap(fn ($s) => $s->requirements_snapshot ?? [])->all();
        $known = $event->setup_minutes !== null && $event->breakdown_minutes !== null;
        $local = $start->setTimezone('America/Chicago');
        $capacity = new WebsiteCapacity($local->startOfDay(), $calendar, $event->id);
        if ($drive !== null) $capacity->withTravel($event->postal_code, $drive);
        $state = $capacity->check($start->subMinutes($known ? $event->setup_minutes : WebsiteCapacity::UNKNOWN_MINUTES),
            $end->addMinutes(($known ? $event->breakdown_minutes : WebsiteCapacity::UNKNOWN_MINUTES) + WebsiteCapacity::GAP_MINUTES),
            $local->toDateString(), $requirements);
        return ($state === 'available' && ! $known) ? 'owner_approval_required' : $state;
    }

    /** Owner paths: refuse a party that cannot physically fit; CRM facts only (the owner sees HoneyBook himself). */
    public static function assertFits(Event $event): void
    {
        RecordWriteRejected::unless(self::state($event, []) !== 'unavailable', 409,
            'Ese horario no tiene cupo: ya hay 4 fiestas al mismo tiempo, 10 en el día, o un traje o equipo necesario está ocupado. Cambia la hora o revisa la agenda.');
    }
}
