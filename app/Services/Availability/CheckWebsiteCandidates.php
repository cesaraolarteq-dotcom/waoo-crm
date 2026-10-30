<?php
namespace App\Services\Availability;

use App\Models\{Character, ServiceVariant};
use App\Services\Scheduling\BookingNoticePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * AVAIL-WEB-01 S2 (owner rules 2026-10-29, see WebsiteCapacity): checks the start times the website is considering.
 * Read-only: no reservation, hold, lead or message. Public answer only says available / owner approval / unavailable,
 * never which resource, party or person decided it.
 */
final class CheckWebsiteCandidates
{
    public const MAX_CANDIDATES = 24;

    /** @throws ValidationException for a malformed request (the controller answers 422) */
    public function execute(array $input): array
    {
        $now = CarbonImmutable::instance(now())->setTimezone('America/Chicago');
        $v = Validator::make($input, [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$now->toDateString(), 'before:2101-01-01'],
            'timezone' => ['required', 'in:America/Chicago'],
            'service_variant_id' => ['required', 'integer', 'min:1'],
            'characters' => ['present', 'array', 'max:6'],
            'characters.*' => ['array:character_id,quantity,outfit'],
            'characters.*.character_id' => ['required', 'integer', 'min:1', 'distinct'],
            'characters.*.quantity' => ['required', 'integer', 'min:1', 'max:6'],
            'characters.*.outfit' => ['present', 'nullable', 'string', 'max:60'],
            'destination' => ['sometimes', 'nullable', 'array:postal_code,latitude,longitude'],
            'destination.postal_code' => ['sometimes', 'regex:/^[0-9]{5}$/D'],
            'destination.latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'destination.longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'candidate_starts' => ['required', 'array', 'min:1', 'max:'.self::MAX_CANDIDATES],
            'candidate_starts.*' => ['array:local,offset_choice'],
            'candidate_starts.*.local' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', 'distinct'],
            'candidate_starts.*.offset_choice' => ['present', 'nullable', 'in:cdt,cst'],
        ]);
        if ($v->fails() || array_diff(array_keys($input), ['date', 'timezone', 'service_variant_id', 'characters', 'destination', 'candidate_starts'])) throw new ValidationException($v);
        $variant = ServiceVariant::whereKey((int) $input['service_variant_id'])->where('status', 'active')->first();
        if (! $variant) throw ValidationException::withMessages(['service_variant_id' => 'invalid']);

        $date = $input['date'];
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'America/Chicago');
        $base = ['schema_version' => 1, 'scope' => 'candidate_times', 'date' => $date, 'timezone' => 'America/Chicago',
            'demand' => app(ReadWebsiteDay::class)->demand($date, $now), 'alts' => [], 'checked_at' => $now->toIso8601String(),
            'expires_at' => $now->addMinutes(2)->toIso8601String(), 'reservation_created' => false];

        // Requirements of the package + the chosen characters; an incomplete choice is never filled in for the client.
        [$requirements, $complete] = $this->requirements($variant, $input['characters']);
        // A specific wardrobe/outfit is not matched unit by unit here yet: the owner confirms it (Codex review 2026-10-29).
        $outfit = collect($input['characters'])->contains(fn ($c) => ($c['outfit'] ?? null) !== null);
        $timing = $variant->duration_minutes && $variant->setup_minutes !== null && $variant->breakdown_minutes !== null;
        $parsed = [];
        foreach (array_values($input['candidate_starts']) as $i => $c) {
            [$d, $t] = explode('T', $c['local']);
            // Skipped March hour is refused; the repeated November hour needs an explicit CDT/CST choice.
            $iso = \App\Support\ChicagoTime::toIso($d, $t, $c['offset_choice'], 'candidate_starts.'.$i.'.local');
            $start = CarbonImmutable::parse($iso);
            // The operating day runs from 00:00 of the requested date to 00:00 of the next (24 hours, owner 2026-10-29).
            if ($start < $day || $start >= $day->addDay()) throw ValidationException::withMessages(['candidate_starts.'.$i.'.local' => 'outside_day']);
            if ($start <= $now) throw ValidationException::withMessages(['candidate_starts.'.$i.'.local' => 'past']);
            $parsed[$i] = $start;
        }
        if (! $complete || ! $timing || $outfit) {
            return [...$base, 'level' => 'unknown', 'capacity_state' => 'unknown', 'requires_owner_approval' => false, 'slots' => [],
                'unresolved' => array_map(fn ($i) => ['candidate' => $i, 'code' => $complete ? 'review_required' : 'incomplete_request'], array_keys($parsed))];
        }

        $google = self::busy($day->subDay(), $day->addDays(2));
        $capacity = new WebsiteCapacity($day, $google);
        // Real drive between neighbouring parties when the client's ZIP is known (tests bind 'waoo.availability.drive').
        $capacity->withTravel($input['destination']['postal_code'] ?? null,
            app()->bound('waoo.availability.drive') ? app('waoo.availability.drive') : [new \App\Services\Routing\DriveMinutes(), 'between']);
        $slots = []; $unresolved = [];
        foreach ($parsed as $i => $start) {
            if (! $capacity->calendarOk()) { $unresolved[] = ['candidate' => $i, 'code' => 'evaluation_unavailable']; continue; }
            $end = $start->addMinutes($variant->duration_minutes);
            $state = $capacity->check($start->subMinutes($variant->setup_minutes), $end->addMinutes($variant->breakdown_minutes + WebsiteCapacity::GAP_MINUTES), $date, $requirements);
            if ($state === 'unavailable' || $state === 'review_required') { $unresolved[] = ['candidate' => $i, 'code' => $state]; continue; }
            if (BookingNoticePolicy::requiresWebsiteApproval($start, $now)) $state = 'owner_approval_required';
            $slots[] = ['starts_at' => $start->setTimezone('America/Chicago')->toIso8601String(), 'ends_at' => $end->setTimezone('America/Chicago')->toIso8601String(),
                'state' => $state, 'requires_owner_approval' => $state === 'owner_approval_required'];
        }
        $allUnavailable = $slots === [] && $unresolved !== [] && collect($unresolved)->every(fn ($u) => $u['code'] === 'unavailable');
        $full = $capacity->partiesOn($date) >= WebsiteCapacity::MAX_PER_DAY;
        return [...$base,
            'level' => $full ? 'full' : ($slots === [] ? 'unknown' : ($capacity->partiesOn($date) >= WebsiteCapacity::MAX_PER_DAY - 3 ? 'filling' : 'open')),
            'capacity_state' => $slots !== [] ? 'available' : ($allUnavailable ? 'unavailable' : 'unknown'),
            'requires_owner_approval' => $slots !== [] && collect($slots)->every(fn ($s) => $s['requires_owner_approval']),
            'slots' => $slots, 'unresolved' => $unresolved];
    }

    /** Busy times outside the CRM (HoneyBook bookings until the switch); tests bind 'waoo.availability.busy' to simulate Google. */
    public static function busy(CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        if (app()->bound('waoo.availability.busy')) return app('waoo.availability.busy')($from, $to);
        // The HoneyBook calendar feed is the source until the switch; without it, the owner's primary Google calendar.
        // No source answering is unknown, never an empty agenda; only after the switch can the CRM be the only source.
        if (config('waoo.availability_crm_only', false)) return [];
        return HoneyBookCalendarFeed::configured() ? app(HoneyBookCalendarFeed::class)->between($from, $to)
            : app(\App\Services\Google\GoogleCalendar::class)->busyIntervals($from, $to);
    }

    /** @return array{0: array, 1: bool} requirements and whether the character choice is complete and valid */
    private function requirements(ServiceVariant $variant, array $chosen): array
    {
        $req = (array) $variant->requirements;
        $options = collect((array) $variant->character_options);
        $needed = (int) ($variant->character_quantity ?? 0);
        if ($options->isEmpty()) return [$req, $chosen === []];
        $total = 0;
        foreach ($chosen as $c) {
            $opt = $options->first(fn ($o) => (int) ($o['character_id'] ?? 0) === (int) $c['character_id']);
            if (! $opt || ! Character::whereKey($c['character_id'])->where('active', true)->exists()) return [$req, false];
            foreach ((array) ($opt['requirements'] ?? []) as $r) $req[] = [...$r, 'quantity' => (int) ($r['quantity'] ?? 1) * (int) $c['quantity']];
            $total += (int) $c['quantity'];
        }
        return [$req, $total >= max(1, $needed)];
    }
}
