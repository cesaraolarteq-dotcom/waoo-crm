<?php
namespace App\Services\Staff;

use App\Models\{Event, EventTeamPlan, StaffAvailability, StaffJobAnswer};
use App\Services\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owner 2026-11-03: from their own link, each person (1) answers "Confirmo / No puedo" for a party they were given and
 * (2) on Monday says which days of the coming weekend they can work. Only about themselves; never touches the team.
 */
final class SaveStaffAnswers
{
    public function job(StaffContext $staff, int $eventId, string $answer, ?string $note): StaffJobAnswer
    {
        if (! in_array($answer, ['yes', 'no'], true)) throw ValidationException::withMessages(['answer' => 'Elige una respuesta.']);
        $note = $note !== null ? mb_substr(trim($note), 0, 300) : null;

        return DB::transaction(function () use ($staff, $eventId, $answer, $note) {
            [, $person] = $staff->authorize();
            $event = Event::whereKey($eventId)->lockForUpdate()->first();
            $plan = $event ? EventTeamPlan::where('event_id', $event->id)->first() : null;
            $inTeam = $plan && (collect($plan->slots)->contains(fn ($s) => (int) ($s['resource_id'] ?? 0) === $person->id)
                || collect($plan->costumes)->contains(fn ($c) => (int) ($c['wearer_id'] ?? 0) === $person->id));
            abort_unless($event && $event->status === 'capacity_reserved' && $event->ends_at->isFuture() && $inTeam, 404, 'Esta fiesta ya no está en tu semana.');
            $row = StaffJobAnswer::updateOrCreate(['event_id' => $event->id, 'resource_id' => $person->id],
                ['answer' => $answer, 'note' => $answer === 'no' ? $note : null, 'event_starts_at' => $event->starts_at, 'answered_at' => now()]);
            Audit::recordSystem('staff.job_answered', $event, [], ['resource_id' => $person->id, 'answer' => $answer]);

            return $row;
        }, 3);
    }

    /** @param array<string,string> $days sat/fri/sun/weekday => yes|no */
    public function availability(StaffContext $staff, string $weekStart, array $days, ?string $note): StaffAvailability
    {
        $clean = array_filter(array_intersect_key($days, array_flip(StaffAvailability::DAYS)), fn ($v) => in_array($v, ['yes', 'no'], true));
        if (! isset($clean['sat'])) throw ValidationException::withMessages(['sat' => 'Dinos primero si puedes el sábado.']);
        $week = CarbonImmutable::parse($weekStart, 'America/Chicago')->startOfWeek(CarbonImmutable::MONDAY);
        abort_unless($week->equalTo(self::askedWeek()), 422, 'Esa semana ya no se puede cambiar.');

        return DB::transaction(function () use ($staff, $week, $clean, $note) {
            [, $person] = $staff->authorize();
            return StaffAvailability::updateOrCreate(['resource_id' => $person->id, 'week_start' => $week->toDateString()],
                ['days' => $clean, 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 300) : null, 'answered_at' => now()]);
        }, 3);
    }

    /** Monday–Thursday: this weekend. Friday–Sunday: the next one (this weekend is already planned). */
    public static function askedWeek(): CarbonImmutable
    {
        $now = CarbonImmutable::now('America/Chicago');
        $monday = $now->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();

        return $now->dayOfWeekIso >= 5 ? $monday->addWeek() : $monday;
    }
}
