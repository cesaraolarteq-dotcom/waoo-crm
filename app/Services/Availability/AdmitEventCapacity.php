<?php
namespace App\Services\Availability;

use App\Exceptions\RecordWriteRejected;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One capacity authority for the paths that commit a party before or with money (owner 2026-10-29; contract
 * docs/BOOKING-FEASIBILITY-CONTRACT.md "Admisión atómica antes de pago", steps 2–3): unpaid Stripe hold (a Zelle quick
 * booking is not gated: the deposit is already in the bank and its costume hold goes to review instead) and
 * "Apartar recursos y confirmar".
 * Lock order (never reversed): global DailyCapacity mutex → user (when applicable) → event → lease
 * → capacity days (Houston dates, ascending) → characters → resources.
 * Two requests for the last place serialize on the shared day rows, re-read every commitment and exactly one fits.
 * Owner paths refuse only the party limits (4 at once, 10 per day); costumes/equipment/people keep each path's own unit
 * checks, and unproven travel or calendar-only entries stay the owner's call (he is the approver).
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

    /**
     * Owner paths: the party limits César set (4 at once for the 4 speakers, 10 per Houston day) over every commitment the
     * CRM knows, including HoneyBook imports, unpaid holds and quick bookings that reserve no unit. Costumes, equipment and
     * people stay with each path's own unit checks (their exact messages, and a received deposit is never refused there).
     */
    public static function assertFits(Event $event): void
    {
        $start = CarbonImmutable::instance($event->starts_at); $end = CarbonImmutable::instance($event->ends_at);
        self::lockDays($start, $end);
        $known = $event->setup_minutes !== null && $event->breakdown_minutes !== null;
        $local = $start->setTimezone('America/Chicago');
        $capacity = new WebsiteCapacity($local->startOfDay(), [], $event->id);
        RecordWriteRejected::unless($capacity->partiesOn($local->toDateString()) < WebsiteCapacity::MAX_PER_DAY, 422,
            'Ese día ya tiene 10 fiestas, el máximo por día. Elige otra fecha.');
        RecordWriteRejected::unless($capacity->simultaneousOk($start->subMinutes($known ? $event->setup_minutes : WebsiteCapacity::UNKNOWN_MINUTES),
            $end->addMinutes(($known ? $event->breakdown_minutes : WebsiteCapacity::UNKNOWN_MINUTES) + WebsiteCapacity::GAP_MINUTES)), 422,
            'A esa hora ya hay 4 fiestas al mismo tiempo (los 4 speakers), contando montaje, desmontaje y 30 minutos. Cambia la hora.');
    }
}
