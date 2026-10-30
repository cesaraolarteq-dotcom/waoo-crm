<?php
namespace App\Services\Availability;

use App\Models\{Event, ImportedProjectLink, LedgerEntry, QuickBookingRecord, QuickCostumeHold, QuickStripeLease, Resource, ResourceBlock, ResourceReservation};
use Carbon\CarbonImmutable;

/**
 * Owner rules 2026-10-29 for website availability (read-only, no writes, no holds):
 * - at most 4 parties at the same time (4 speakers) and 10 parties per Houston day; any hour;
 * - every party is one job over its whole busy time (setup + party + breakdown + 30 min), solved jointly with Codex's
 *   PoolCapacitySolver: one unit per role for the whole job, no hand-over mid-party, a multiskilled person only once;
 * - the driver is a member of the party's own team (César: ~95 %); no team with a driver = owner approval;
 * - costumes and equipment are physical: if they cannot be covered even with unlimited people, the start is unavailable;
 *   if only people are short, it is owner approval (he can call someone in);
 * - unit reservations are pinned to their unit (never silently reassigned); active blocks make a unit busy;
 * - not proven = owner approval, never instant: unknown setup/breakdown, a calendar entry the CRM cannot identify, a
 *   neighbouring party whose drive is not known (César: real drive + 30 min, until travel evidence is wired), solver budget.
 * Commitments: non-cancelled CRM parties that are booked or hold anything (confirmed, HoneyBook import, quick booking,
 * receipt, unit reservation, active Stripe lease or costume hold) and HoneyBook calendar entries (one-to-one matched with
 * imported parties by start and end; unmatched entries count as an extra party needing one team member who drives).
 */
final class WebsiteCapacity
{
    public const MAX_SIMULTANEOUS = 4;
    public const MAX_PER_DAY = 10;
    /** From this many characters in one party the owner always approves. */
    public const OWNER_CHARACTERS = 5;
    public const GAP_MINUTES = 30;
    /** A neighbouring party closer than this (beyond the 30-minute gap) needs the drive checked by the owner. */
    public const TRAVEL_REVIEW_MINUTES = 60;
    /** Conservative busy time around a party whose setup/breakdown is unknown, or a calendar-only entry. */
    public const UNKNOWN_MINUTES = 60;
    /** Neighbouring parties further than this from the candidate are never reached by a shared person or costume in time anyway. */
    public const MAX_DRIVE_MINUTES = 120;

    /** @var list<array{key:string,from:CarbonImmutable,to:CarbonImmutable,day:?string,uncertain:bool,requirements:array,pinned:array<int,int>}> */
    private array $parties = [];
    private array $resources = [];
    private array $units = [];
    private array $blocks = [];
    private ?string $zip = null;
    private ?array $compat = null;
    /** @var (callable(string, string, CarbonImmutable): ?int)|null */
    private $drive = null;

    /**
     * @param list<array{0: CarbonImmutable, 1: CarbonImmutable}>|null $calendar null = calendar did not answer
     * @param int|null $exclude the party being admitted (AdmitEventCapacity): never counted against itself
     */
    public function __construct(private CarbonImmutable $dayStart, private ?array $calendar, private ?int $exclude = null)
    {
        $from = $dayStart->subDay(); $to = $dayStart->addDays(2);
        foreach (Resource::query()->where('status', 'active')->get(['id', 'kind', 'equipment_kind', 'character_id', 'skills', 'seats']) as $r) {
            $this->units[$r->id] = $r;
            $this->resources[] = match ($r->kind) {
                'staff' => ['id' => $r->id, 'kind' => 'staff', 'driver' => in_array('driver', (array) $r->skills, true)],
                // Unknown seats are never assumed (Codex audit R3): such a vehicle can't prove a requirement (review instead).
                'vehicle' => ['id' => $r->id, 'kind' => 'vehicle', 'seats' => max(1, (int) $r->seats)],
                default => ['id' => $r->id, 'kind' => in_array($r->kind, ['equipment', 'costume'], true) ? $r->kind : 'equipment'],
            };
        }
        $imported = [];
        foreach (Event::query()->where('status', '!=', 'cancelled')->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())->with('services')->get() as $e) {
            if ($e->id === $this->exclude) continue;
            $isImport = ImportedProjectLink::where('event_id', $e->id)->exists();
            $reserved = ResourceReservation::where('event_id', $e->id)->pluck('resource_id')->all();
            $holds = $e->status === 'capacity_reserved' || $isImport || $reserved || QuickBookingRecord::where('event_id', $e->id)->exists()
                || LedgerEntry::where('event_id', $e->id)->where('kind', 'receipt')->exists()
                || QuickStripeLease::where('event_id', $e->id)->where('state', 'active')->exists()
                || QuickCostumeHold::where('event_id', $e->id)->whereIn('state', ['held', 'review_required', 'consumed'])->exists();
            if (! $holds) continue;
            $known = $e->setup_minutes !== null && $e->breakdown_minutes !== null;
            $start = CarbonImmutable::instance($e->starts_at); $end = CarbonImmutable::instance($e->ends_at);
            $this->parties[] = ['key' => 'e'.$e->id, 'start' => $start, 'end' => $end,
                'from' => $start->subMinutes($known ? $e->setup_minutes : self::UNKNOWN_MINUTES),
                'to' => $end->addMinutes(($known ? $e->breakdown_minutes : self::UNKNOWN_MINUTES) + self::GAP_MINUTES),
                'day' => $start->setTimezone('America/Chicago')->toDateString(), 'uncertain' => ! $known,
                'requirements' => $e->services->flatMap(fn ($s) => $s->requirements_snapshot ?? [])->all(),
                'pinned' => array_values(array_filter($reserved, fn ($id) => isset($this->units[$id]))),
                'zip' => preg_match('/^\d{5}$/D', (string) $e->postal_code) ? $e->postal_code : null];
            if ($isImport) $imported[] = count($this->parties) - 1;
        }
        // Active blocks (maintenance, day off): the unit is busy for the whole block. Overlapping blocks on one unit stay one unit.
        foreach (ResourceBlock::query()->whereNull('released_at')->whereIn('resource_id', array_keys($this->units))
            ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())->get() as $b) {
            $this->blocks[] = ['resource_id' => $b->resource_id, 'starts_at' => CarbonImmutable::instance($b->starts_at)->getTimestamp(), 'ends_at' => CarbonImmutable::instance($b->ends_at)->getTimestamp()];
        }
        // HoneyBook calendar: each entry is absorbed by at most one imported party with the same start and end; others stay.
        $free = $imported; $n = 0;
        foreach ($calendar ?? [] as [$cFrom, $cTo]) {
            $match = null;
            foreach ($free as $i => $idx) {
                $p = $this->parties[$idx];
                if (abs($p['start']->diffInMinutes($cFrom, true)) <= 5 && abs($p['end']->diffInMinutes($cTo, true)) <= 15) { $match = $i; break; }
            }
            if ($match !== null) { unset($free[$match]); continue; }
            $this->parties[] = ['key' => 'cal'.(++$n), 'start' => $cFrom, 'end' => $cTo, 'from' => $cFrom->subMinutes(self::UNKNOWN_MINUTES),
                'to' => $cTo->addMinutes(self::UNKNOWN_MINUTES + self::GAP_MINUTES), 'day' => $cFrom->setTimezone('America/Chicago')->toDateString(),
                'uncertain' => true, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => '*']], 'pinned' => [], 'zip' => null];
        }
    }

    /** The candidate's ZIP and a drive-time source: neighbouring parties then get their real drive instead of the fixed review window. */
    public function withTravel(?string $zip, callable $drive): self
    {
        $this->zip = preg_match('/^\d{5}$/D', (string) $zip) ? $zip : null; $this->drive = $drive;
        return $this;
    }

    public function calendarOk(): bool { return $this->calendar !== null; }

    public function partiesOn(string $day): int
    {
        return count(array_filter($this->parties, fn ($p) => $p['day'] === $day));
    }

    /** 4 parties at the same time (speakers), checked at every start inside the candidate. */
    public function simultaneousOk(CarbonImmutable $from, CarbonImmutable $to): bool
    {
        $overlap = array_values(array_filter($this->parties, fn ($p) => $p['from'] < $to && $p['to'] > $from));
        foreach (array_merge([$from], array_map(fn ($p) => max($p['from'], $from), $overlap)) as $t) {
            if (count(array_filter($overlap, fn ($p) => $p['from'] <= $t && $p['to'] > $t)) + 1 > self::MAX_SIMULTANEOUS) return false;
        }
        return true;
    }

    /** 'available' | 'owner_approval_required' | 'unavailable' | 'review_required' for one candidate busy interval. */
    public function check(CarbonImmutable $from, CarbonImmutable $to, string $day, array $requirements): string
    {
        if ($this->partiesOn($day) >= self::MAX_PER_DAY) return 'unavailable';
        $state = $this->evaluate($from, $to, $requirements);
        if ($state === 'unavailable' || $state === 'review_required') return $state;
        // César 2026-10-29: 5 or more characters always need the owner, whatever the inventory (Codex audit R2).
        $characters = array_sum(array_map(fn ($r) => ($r['kind'] ?? null) === 'costume' ? max(1, (int) ($r['quantity'] ?? 1)) : 0, $requirements));
        if ($characters >= self::OWNER_CHARACTERS) return 'owner_approval_required';
        if (! $this->travelFits($from, $to, $requirements, $state)) return 'owner_approval_required';
        $near = array_filter($this->parties, fn ($p) => $p['uncertain'] && $p['from'] < $to->addMinutes(self::TRAVEL_REVIEW_MINUTES) && $p['to'] > $from->subMinutes(self::TRAVEL_REVIEW_MINUTES));
        return $near ? 'owner_approval_required' : $state;
    }

    /**
     * César: between two parties that share a person, costume or equipment, the real drive + 30 minutes. With the candidate's
     * ZIP, every known neighbour within MAX_DRIVE_MINUTES keeps its own busy time stretched by the drive toward the candidate;
     * the answer must not change. Any drive not known (no ZIP, Google slow or failing) falls back to the fixed 60-minute
     * window on both sides: if that changes the answer, the owner checks.
     */
    private function travelFits(CarbonImmutable $from, CarbonImmutable $to, array $requirements, string $state): bool
    {
        if ($this->zip !== null && $this->drive !== null) {
            $saved = $this->parties; $known = true;
            foreach ($saved as $i => $p) {
                if ($p['uncertain']) continue; // calendar-only or unknown timing: owner approval below
                $before = $p['to'] <= $from && $p['to'] > $from->subMinutes(self::MAX_DRIVE_MINUTES);
                $after = $p['from'] >= $to && $p['from'] < $to->addMinutes(self::MAX_DRIVE_MINUTES);
                if (! $before && ! $after) continue;
                $m = $p['zip'] === null ? null : ($this->drive)($before ? $p['zip'] : $this->zip, $before ? $this->zip : $p['zip'], $before ? $p['to'] : $to);
                if (! is_int($m) || $m < 0 || $m > self::MAX_DRIVE_MINUTES) { $known = false; break; }
                if ($before) $this->parties[$i]['to'] = $p['to']->addMinutes($m); else $this->parties[$i]['from'] = $p['from']->subMinutes($m);
            }
            if ($known) {
                try { return $this->evaluate($from, $to, $requirements) === $state; } finally { $this->parties = $saved; }
            }
            $this->parties = $saved;
        }
        return $this->evaluate($from->subMinutes(self::TRAVEL_REVIEW_MINUTES), $to->addMinutes(self::TRAVEL_REVIEW_MINUTES), $requirements) === $state;
    }

    private function evaluate(CarbonImmutable $from, CarbonImmutable $to, array $requirements): string
    {
        if (! $this->simultaneousOk($from, $to)) return 'unavailable';
        $candidate = ['key' => 'candidate', 'from' => $from, 'to' => $to, 'requirements' => $requirements, 'pinned' => []];
        // Only parties connected to the candidate through overlapping busy time can change the answer.
        $jobs = $this->connected(array_merge($this->parties, [$candidate]), count($this->parties));
        $physical = $this->solve($jobs, false);
        if ($physical === 'unknown') return 'review_required';
        if ($physical === 'infeasible') return 'unavailable';
        $full = $this->solve($jobs, true);
        return $full === 'feasible' ? 'available' : ($full === 'unknown' ? 'review_required' : 'owner_approval_required');
    }

    /** Parties whose busy time chains to the candidate (index $c) through overlaps. */
    private function connected(array $all, int $c): array
    {
        $in = [$c => true]; $queue = [$c];
        while ($queue) {
            $i = array_pop($queue);
            foreach ($all as $j => $p) if (! isset($in[$j]) && $p['from'] < $all[$i]['to'] && $p['to'] > $all[$i]['from']) { $in[$j] = true; $queue[] = $j; }
        }
        return array_values(array_intersect_key($all, $in));
    }

    /** 'feasible' | 'infeasible' | 'unknown'. Without people, only costumes and equipment are checked. */
    private function skillOf(array $party, array $slot): ?string
    {
        return $slot['skill'] ?? null;
    }

    /** Active staff with this skill. @return list<int> */
    private function staffWith(string $skill): array
    {
        return array_values(array_keys(array_filter($this->units, fn ($u) => $u->kind === 'staff' && in_array($skill, (array) $u->skills, true))));
    }

    /** Recorded [costume unit, staff] combinations for these costume units. @return list<array{0:int,1:int}> */
    private function wearers(array $costumeIds): array
    {
        $this->compat ??= \Illuminate\Support\Facades\DB::table('costume_compatibilities')->get(['costume_id', 'staff_id'])
            ->map(fn ($r) => [(int) $r->costume_id, (int) $r->staff_id])->all();
        return array_values(array_filter($this->compat, fn ($c) => in_array($c[0], $costumeIds, true) && isset($this->units[$c[1]])));
    }

    private function solve(array $parties, bool $withPeople): string
    {
        $jobs = []; $blocks = $this->blocks;
        foreach ($parties as $p) {
            $slots = []; $pinned = $p['pinned']; $k = 0;
            foreach ($p['requirements'] as $req) {
                $kind = $req['kind'] ?? null;
                if ($kind === 'staff' && ! $withPeople) continue;
                $eligible = array_keys(array_filter($this->units, fn ($u) => match ($kind) {
                    'costume' => $u->kind === 'costume' && (int) $u->character_id === (int) ($req['character_id'] ?? 0),
                    'equipment' => $u->kind === 'equipment' && $u->equipment_kind === ($req['equipment_kind'] ?? null),
                    'staff' => $u->kind === 'staff' && (($req['skill'] ?? null) === '*' || in_array($req['skill'] ?? null, (array) $u->skills, true)),
                    'vehicle' => $u->kind === 'vehicle' && (int) $u->seats >= max(1, (int) ($req['seats'] ?? 1)),
                    default => false,
                }));
                if (! in_array($kind, ['costume', 'equipment', 'staff', 'vehicle'], true)) continue;
                // A vehicle whose seats are not recorded proves nothing: the candidate is reviewed, never instant or refused.
                if ($kind === 'vehicle' && $eligible === [] && $p['key'] === 'candidate'
                    && array_filter($this->units, fn ($u) => $u->kind === 'vehicle' && ! $u->seats)) return 'unknown';
                for ($q = 0; $q < max(1, (int) ($req['quantity'] ?? 1)); $q++) {
                    // A reserved unit of this pool is pinned to this party: it covers this slot and nothing else.
                    $pin = null;
                    foreach ($pinned as $i => $id) if (in_array($id, $eligible, true)) { $pin = $id; unset($pinned[$i]); break; }
                    if ($eligible === [] && $pin === null) {
                        // The candidate needs something nobody has: impossible. An already booked party is a fact: skip that role.
                        if ($p['key'] === 'candidate') return 'infeasible';
                        continue;
                    }
                    $slots[] = ['key' => $kind.($k++), 'kind' => $kind, 'eligible' => $pin !== null ? [$pin] : $eligible, 'skill' => $req['skill'] ?? null];
                }
            }
            // Who can wear the costume (Codex audit R4, like ConfirmBooking): each costume of the candidate is worn by one of its
            // performers, paired by the recorded "Quién puede usarlo" list. No recorded wearer = nobody can (people pass fails).
            if ($withPeople && $p['key'] === 'candidate') {
                $used = [];
                foreach ($slots as $ci => $slot) {
                    if ($slot['kind'] !== 'costume') continue;
                    $wi = null;
                    foreach ($slots as $si => $s2) if ($s2['kind'] === 'staff' && ! isset($used[$si]) && in_array($this->skillOf($p, $s2), ['performer'], true)) { $wi = $si; break; }
                    if ($wi === null) { $slots[] = ['key' => 'wearer'.$ci, 'kind' => 'staff', 'eligible' => $this->staffWith('performer')]; $wi = array_key_last($slots); }
                    $used[$wi] = true;
                    $slots[$ci]['pair'] = $slots[$wi]['key'];
                    $slots[$ci]['pairs'] = $this->wearers($slot['eligible']);
                }
            }
            // Reserved units that match no requirement are still busy for this party.
            foreach ($pinned as $id) $blocks[] = ['resource_id' => $id, 'starts_at' => $p['from']->getTimestamp(), 'ends_at' => $p['to']->getTimestamp()];
            $hasStaff = (bool) array_filter($slots, fn ($s) => $s['kind'] === 'staff');
            if ($slots === []) continue;
            $jobs[] = ['key' => $p['key'], 'starts_at' => $p['from']->getTimestamp(), 'ends_at' => $p['to']->getTimestamp(), 'slots' => $slots,
                'minimum_drivers' => $withPeople && $hasStaff ? 1 : 0, 'people' => $withPeople];
        }
        if ($jobs === []) return 'feasible';
        $r = (new PoolCapacitySolver())->solve($this->resources, $jobs, $blocks);
        return in_array($r['state'], ['feasible', 'infeasible'], true) ? $r['state'] : 'unknown';
    }
}
