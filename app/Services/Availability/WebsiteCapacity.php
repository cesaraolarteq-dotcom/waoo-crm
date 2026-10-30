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
 * receipt, unit reservation, active Stripe lease or costume hold) and HoneyBook calendar entries. Matching start/end
 * times cannot establish identity; unreconciled entries remain uncertain and consume capacity.
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

    /** @var list<array{key:string,from:CarbonImmutable,to:CarbonImmutable,day:?string,uncertain:bool,requirements:array,pinned:array<int,int>}> */
    private array $parties = [];
    private array $resources = [];
    private array $units = [];
    private array $blocks = [];
    private ?string $zip = null;
    private ?string $location = null;
    private ?array $compat = null;
    private bool $unfilled = false;
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
            // Codex audit S5: a committed party whose recipe is empty, or that holds a unit no longer active, is not proof
            // that its people/equipment are free: it stays uncertain (the owner checks nearby starts), never demand 0.
            $requirementsOf = $e->services->flatMap(fn ($s) => $s->requirements_snapshot ?? [])->all();
            $broken = $requirementsOf === [] || array_diff($reserved, array_keys($this->units)) !== [];
            $start = CarbonImmutable::instance($e->starts_at); $end = CarbonImmutable::instance($e->ends_at);
            $this->parties[] = ['key' => 'e'.$e->id, 'start' => $start, 'end' => $end,
                'from' => $start->subMinutes($known ? $e->setup_minutes : self::UNKNOWN_MINUTES),
                'to' => $end->addMinutes(($known ? $e->breakdown_minutes : self::UNKNOWN_MINUTES) + self::GAP_MINUTES),
                'day' => $start->setTimezone('America/Chicago')->toDateString(), 'uncertain' => ! $known || $broken,
                'requirements' => $requirementsOf,
                'pinned' => array_values(array_filter($reserved, fn ($id) => isset($this->units[$id]))),
                'address' => filled($e->address) ? $e->address : null, 'zip' => preg_match('/^\d{5}$/D', (string) $e->postal_code) ? $e->postal_code : null];
            if ($isImport) $imported[] = count($this->parties) - 1;
        }
        // Active blocks (maintenance, day off): the unit is busy for the whole block. Overlapping blocks on one unit stay one unit.
        foreach (ResourceBlock::query()->whereNull('released_at')->whereIn('resource_id', array_keys($this->units))
            ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())->get() as $b) {
            $this->blocks[] = ['resource_id' => $b->resource_id, 'starts_at' => CarbonImmutable::instance($b->starts_at)->getTimestamp(), 'ends_at' => CarbonImmutable::instance($b->ends_at)->getTimestamp()];
        }
        // A similar timestamp is only a review hint, never proof that two records describe the same party.
        $free = $imported; $n = 0;
        $scope = HoneyBookCalendarFeed::scope();
        $identities = $scope === null ? collect() : \Illuminate\Support\Facades\DB::table('calendar_identity_links as identity')
            ->join('events as e', 'e.id', '=', 'identity.event_id')
            ->join('imported_project_links as imported', 'imported.event_id', '=', 'e.id')
            ->where('identity.scope_sha256', $scope)->where('e.status', '!=', 'cancelled')
            ->whereColumn('e.starts_at', 'identity.starts_at')->whereColumn('e.ends_at', 'identity.ends_at')
            ->get(['identity.uid_sha256', 'identity.event_id', 'identity.starts_at', 'identity.ends_at'])->keyBy('uid_sha256');
        foreach ($calendar ?? [] as $entry) {
            [$cFrom, $cTo] = $entry;
            $identity = ($entry[2]['scope_sha256'] ?? null) === $scope ? $identities->get($entry[2]['uid_sha256'] ?? '') : null;
            if ($identity && CarbonImmutable::parse($identity->starts_at, 'UTC')->equalTo($cFrom)
                && CarbonImmutable::parse($identity->ends_at, 'UTC')->equalTo($cTo)) continue;
            $match = null;
            foreach ($free as $i => $idx) {
                $p = $this->parties[$idx];
                if (abs($p['start']->diffInMinutes($cFrom, true)) <= 5 && abs($p['end']->diffInMinutes($cTo, true)) <= 15) { $match = $i; break; }
            }
            if ($match !== null) {
                // Matching times cannot establish identity: retain uncertainty until reconciled by a stable source ID.
                $this->parties[$free[$match]]['uncertain'] = true;
                unset($free[$match]);
            }
            $this->parties[] = ['key' => 'cal'.(++$n), 'start' => $cFrom, 'end' => $cTo, 'from' => $cFrom->subMinutes(self::UNKNOWN_MINUTES),
                'to' => $cTo->addMinutes(self::UNKNOWN_MINUTES + self::GAP_MINUTES), 'day' => $cFrom->setTimezone('America/Chicago')->toDateString(),
                'uncertain' => true, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => '*']], 'pinned' => [], 'zip' => null];
        }
    }

    /** The candidate's ZIP and a drive-time source: neighbouring parties then get their real drive instead of the fixed review window. */
    public function withTravel(?string $zip, callable $drive, ?string $location = null): self
    {
        $this->location = $location;
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
        if (! $this->travelFits($from, $to, $requirements)) return 'owner_approval_required';
        // Unknown demand can hide the very costume/crew needed by this candidate. A time window cannot prove it free.
        $uncertain = array_filter($this->parties, fn ($p) => $p['uncertain']);
        return $uncertain ? 'owner_approval_required' : $state;
    }

    /**
     * A shared resource needs route evidence from breakdown-end to setup-start, with 30 extra minutes. An unknown
     * or insufficient route prohibits sharing that resource; a separate complete team can still prove feasibility.
     * No 60/120-minute window, ZIP-centroid estimate or independent role-by-role answer proves a transfer.
     */
    private function travelFits(CarbonImmutable $from, CarbonImmutable $to, array $requirements): bool
    {
        $candidate = ['key' => 'candidate', 'from' => $from, 'to' => $to, 'requirements' => $requirements, 'pinned' => [],
            'uncertain' => false, 'zip' => $this->zip, 'address' => $this->location];
        $parties = [...$this->parties, $candidate];
        $separations = [];
        foreach ($parties as $i => $a) {
            foreach (array_slice($parties, $i + 1) as $b) {
                if ($a['to'] > $b['from'] && $b['to'] > $a['from']) continue;
                [$before, $after] = $a['to'] <= $b['from'] ? [$a, $b] : [$b, $a];
                $minutes = null;
                if (!$before['uncertain'] && !$after['uncertain'] && $this->drive !== null
                    && (($before['zip'] !== null && $after['zip'] !== null)
                        || (filled($before['address'] ?? null) && filled($after['address'] ?? null)))) {
                    $departure = $before['to']->subMinutes(self::GAP_MINUTES);
                    try { $minutes = ($this->drive)($before['zip'], $after['zip'], $departure, $before['address'] ?? null, $after['address'] ?? null); }
                    catch (\Throwable) { $minutes = null; }
                }
                $fits = is_int($minutes) && $minutes >= 0 && $minutes <= 1440
                    && $before['to']->addMinutes($minutes) <= $after['from'];
                if (!$fits) $separations[] = ['first' => $before['key'], 'second' => $after['key'], 'resources' => array_keys($this->units)];
            }
        }
        // One allocation must cover every party and transfer, including the existing unassigned teams.
        $this->unfilled = false;
        return $this->solve($parties, true, $separations) === 'feasible' && !$this->unfilled;
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
        $this->unfilled = false;
        $full = $this->solve($jobs, true);
        if ($full === 'feasible' && $this->unfilled) return 'owner_approval_required';
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

    /** Allowed [costume unit, staff] combinations for these costume units (App\Support\CostumeWearers). @return list<array{0:int,1:int}> */
    private function wearers(array $costumeIds): array
    {
        $this->compat ??= \Illuminate\Support\Facades\DB::table('costume_compatibilities')->get(['costume_id', 'staff_id'])->all();
        $chars = \App\Models\Character::whereIn('id', array_map(fn ($id) => (int) $this->units[$id]->character_id, $costumeIds))->get()->keyBy('id');
        $staff = array_filter($this->units, fn ($u) => $u->kind === 'staff');
        $pairs = [];
        foreach ($costumeIds as $cid) {
            $costume = $this->units[$cid] ?? null;
            if (! $costume) continue;
            foreach ($staff as $sid => $person) {
                if (\App\Support\CostumeWearers::allows($costume, $person, $chars->get((int) $costume->character_id), $this->compat)) $pairs[] = [$cid, $sid];
            }
        }
        return $pairs;
    }

    private function solve(array $parties, bool $withPeople, array $separations = []): string
    {
        $jobs = []; $blocks = $this->blocks;
        foreach ($parties as $p) {
            $slots = []; $pinned = $p['pinned']; $required = []; $k = 0;
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
                    if ($eligible === []) {
                        // The candidate needs something nobody has: impossible. An already booked party is a fact: its role is
                        // skipped here, and the party is marked uncertain so nearby starts go to the owner (Codex audit S5).
                        if ($p['key'] === 'candidate') return 'infeasible';
                        $this->unfilled = true;
                        continue;
                    }
                    $slots[] = ['key' => $kind.($k++), 'kind' => $kind, 'eligible' => $eligible, 'skill' => $req['skill'] ?? null];
                }
            }
            // Who can wear the costume (Codex audit R4, like ConfirmBooking): each costume of the candidate is worn by one of its
            // performers, paired by the recorded "Quién puede usarlo" list. No recorded wearer = nobody can (people pass fails).
            if ($withPeople && $p['key'] === 'candidate') {
                $used = [];
                foreach ($slots as $ci => $slot) {
                    if ($slot['kind'] !== 'costume') continue;
                    $wi = null;
                    foreach ($slots as $si => $s2) if ($s2['kind'] === 'staff' && ! isset($used[$si]) && in_array($this->skillOf($p, $s2), ['performer', 'performer_wig'], true)) { $wi = $si; break; }
                    if ($wi === null) { $slots[] = ['key' => 'wearer'.$ci, 'kind' => 'staff', 'eligible' => array_values(array_unique([...$this->staffWith('performer'), ...$this->staffWith('performer_wig')]))]; $wi = array_key_last($slots); }
                    $used[$wi] = true;
                    $slots[$ci]['pair'] = $slots[$wi]['key'];
                    $slots[$ci]['pairs'] = $this->wearers($slot['eligible']);
                }
            }
            foreach ($pinned as $i => $id) {
                foreach ($slots as $slot) {
                    if (in_array($id, $slot['eligible'], true)) { $required[] = $id; unset($pinned[$i]); break; }
                }
            }
            // A held unit absent from the recipe is still part of the committed team, including its transfer.
            foreach ($pinned as $id) {
                $kind = $this->units[$id]->kind;
                if ($kind === 'staff' && ! $withPeople) continue;
                $slots[] = ['key' => 'pinned'.$id, 'kind' => $kind, 'eligible' => [$id]];
            }
            $hasStaff = (bool) array_filter($slots, fn ($s) => $s['kind'] === 'staff');
            if ($slots === []) continue;
            $jobs[] = ['key' => $p['key'], 'starts_at' => $p['from']->getTimestamp(), 'ends_at' => $p['to']->getTimestamp(), 'slots' => $slots,
                'minimum_drivers' => $withPeople && $hasStaff ? 1 : 0, 'people' => $withPeople, 'required' => $required];
        }
        if ($jobs === []) return 'feasible';
        $jobKeys = array_column($jobs, 'key');
        $separations = array_values(array_filter($separations, fn ($edge) => in_array($edge['first'], $jobKeys, true) && in_array($edge['second'], $jobKeys, true)));
        $r = (new PoolCapacitySolver())->solve($this->resources, $jobs, $blocks, $separations);
        return in_array($r['state'], ['feasible', 'infeasible'], true) ? $r['state'] : 'unknown';
    }
}
