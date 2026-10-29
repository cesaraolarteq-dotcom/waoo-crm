<?php
namespace App\Services\Availability;

use App\Models\{Event, ImportedProjectLink, LedgerEntry, QuickBookingRecord, QuickCostumeHold, QuickStripeLease, Resource, ResourceBlock, ResourceReservation};
use Carbon\CarbonImmutable;

/**
 * Owner rules 2026-10-29 for website availability (read-only, no writes, no holds):
 * - at most 4 parties at the same time (4 speakers) and 10 parties per Houston day; any hour;
 * - busy time = setup + party + breakdown + 30 minutes between parties;
 * - costumes and equipment are physical: needing more units than are free (blocks included) is unavailable;
 * - every party needs a driver plus the package's skills; one person covers one role at a time (joint matching, so a
 *   multiskilled person is never counted twice); people short is offered as owner approval (the owner can call someone);
 * - anything not proven keeps the start on owner approval, never instant: unknown setup/breakdown, a calendar entry the CRM
 *   cannot identify, or a neighbouring party close enough that the drive between them is not known (Codex review 2026-10-29).
 * Commitments: non-cancelled CRM parties that are booked or hold anything (confirmed, HoneyBook import, quick booking,
 * receipt, unit reservation, active Stripe lease or costume hold), active resource blocks, and HoneyBook calendar entries
 * (one-to-one matched with imported parties by start and end; unmatched entries count as an extra party).
 */
final class WebsiteCapacity
{
    public const MAX_SIMULTANEOUS = 4;
    public const MAX_PER_DAY = 10;
    public const GAP_MINUTES = 30;
    /** A neighbouring party closer than this (beyond the 30-minute gap) needs the drive checked by the owner. */
    public const TRAVEL_REVIEW_MINUTES = 60;
    /** Conservative busy time around a party whose setup/breakdown is unknown, or a calendar-only entry. */
    public const UNKNOWN_MINUTES = 60;

    private array $commitments = [];
    private array $units = ['party' => self::MAX_SIMULTANEOUS];
    /** @var list<list<string>> skills of each active staff member */
    private array $staff = [];

    /** @param list<array{0: CarbonImmutable, 1: CarbonImmutable}>|null $calendar null = calendar did not answer */
    public function __construct(private CarbonImmutable $dayStart, private ?array $calendar)
    {
        $from = $dayStart->subDay(); $to = $dayStart->addDays(2);
        $imported = [];
        foreach (Event::query()->where('status', '!=', 'cancelled')->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())->with('services')->get() as $e) {
            $isImport = ImportedProjectLink::where('event_id', $e->id)->exists();
            $holds = $e->status === 'capacity_reserved' || $isImport || QuickBookingRecord::where('event_id', $e->id)->exists()
                || LedgerEntry::where('event_id', $e->id)->where('kind', 'receipt')->exists()
                || ResourceReservation::where('event_id', $e->id)->exists()
                || QuickStripeLease::where('event_id', $e->id)->where('state', 'active')->exists()
                || QuickCostumeHold::where('event_id', $e->id)->whereIn('state', ['held', 'review_required', 'consumed'])->exists();
            if (! $holds) continue;
            $known = $e->setup_minutes !== null && $e->breakdown_minutes !== null;
            $start = CarbonImmutable::instance($e->starts_at); $end = CarbonImmutable::instance($e->ends_at);
            $c = ['from' => $start->subMinutes($known ? $e->setup_minutes : self::UNKNOWN_MINUTES),
                'to' => $end->addMinutes(($known ? $e->breakdown_minutes : self::UNKNOWN_MINUTES) + self::GAP_MINUTES),
                'start' => $start, 'end' => $end, 'party' => true, 'uncertain' => ! $known,
                'day' => $start->setTimezone('America/Chicago')->toDateString(),
                'needs' => self::needs($e->services->flatMap(fn ($s) => $s->requirements_snapshot ?? [])->all())];
            $this->commitments[] = $c;
            if ($isImport) $imported[] = count($this->commitments) - 1;
        }
        $resources = Resource::query()->where('status', 'active')->get(['id', 'kind', 'equipment_kind', 'character_id', 'skills']);
        $poolOf = [];
        foreach ($resources as $r) {
            $key = match ($r->kind) { 'costume' => $r->character_id ? 'costume:'.$r->character_id : null,
                'equipment' => $r->equipment_kind ? 'equipment:'.$r->equipment_kind : null, default => null };
            if ($key) { $this->units[$key] = ($this->units[$key] ?? 0) + 1; $poolOf[$r->id] = $key; }
            if ($r->kind === 'staff') { $this->staff[] = array_values(array_unique((array) $r->skills)); $poolOf[$r->id] = 'staff#'.(count($this->staff) - 1); }
        }
        // A blocked unit (maintenance, day off) is unavailable for its whole block.
        foreach (ResourceBlock::query()->whereNull('released_at')->whereIn('resource_id', array_keys($poolOf))
            ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())->get() as $b) {
            $key = $poolOf[$b->resource_id];
            $this->commitments[] = ['from' => CarbonImmutable::instance($b->starts_at), 'to' => CarbonImmutable::instance($b->ends_at), 'party' => false,
                'uncertain' => false, 'day' => null, 'needs' => str_starts_with($key, 'staff#') ? ['person:'.substr($key, 6) => 1] : [$key => 1]];
        }
        // HoneyBook calendar: each entry is absorbed by at most one imported party with the same start and end; others stay.
        $free = $imported;
        foreach ($calendar ?? [] as [$cFrom, $cTo]) {
            $match = null;
            foreach ($free as $i => $idx) {
                $p = $this->commitments[$idx];
                if (abs($p['start']->diffInMinutes($cFrom, true)) <= 5 && abs($p['end']->diffInMinutes($cTo, true)) <= 15) { $match = $i; break; }
            }
            if ($match !== null) { unset($free[$match]); continue; }
            $this->commitments[] = ['from' => $cFrom->subMinutes(self::UNKNOWN_MINUTES), 'to' => $cTo->addMinutes(self::UNKNOWN_MINUTES + self::GAP_MINUTES),
                'party' => true, 'uncertain' => true, 'day' => $cFrom->setTimezone('America/Chicago')->toDateString(), 'needs' => ['party' => 1, 'staff:driver' => 1]];
        }
    }

    /** Requirement list (catalog / snapshot shape) -> pooled needs; every party uses one party slot and one driver. */
    public static function needs(array $requirements): array
    {
        $n = ['party' => 1, 'staff:driver' => 1];
        foreach ($requirements as $req) {
            $q = max(1, (int) ($req['quantity'] ?? 1));
            $key = match ($req['kind'] ?? null) {
                'costume' => isset($req['character_id']) ? 'costume:'.(int) $req['character_id'] : null,
                'equipment' => isset($req['equipment_kind']) ? 'equipment:'.$req['equipment_kind'] : null,
                'staff' => isset($req['skill']) && $req['skill'] !== 'driver' ? 'staff:'.$req['skill'] : null,
                default => null,
            };
            if ($key) $n[$key] = ($n[$key] ?? 0) + $q;
        }
        return $n;
    }

    public function calendarOk(): bool { return $this->calendar !== null; }

    public function partiesOn(string $day): int
    {
        return count(array_filter($this->commitments, fn ($c) => $c['party'] && $c['day'] === $day));
    }

    /** 'available' | 'owner_approval_required' | 'unavailable' for one candidate busy interval. */
    public function check(CarbonImmutable $from, CarbonImmutable $to, string $day, array $needs): string
    {
        if ($this->partiesOn($day) >= self::MAX_PER_DAY) return 'unavailable';
        $state = $this->evaluate($from, $to, $needs);
        if ($state === 'unavailable') return 'unavailable';
        // The drive to/from a neighbouring party is not proven: if a wider window would change the answer, the owner checks.
        $wide = $this->evaluate($from->subMinutes(self::TRAVEL_REVIEW_MINUTES), $to->addMinutes(self::TRAVEL_REVIEW_MINUTES), $needs);
        if ($wide !== $state) return 'owner_approval_required';
        $uncertain = array_filter($this->commitments, fn ($c) => $c['uncertain'] && $c['from'] < $to->addMinutes(self::TRAVEL_REVIEW_MINUTES) && $c['to'] > $from->subMinutes(self::TRAVEL_REVIEW_MINUTES));
        return $uncertain ? 'owner_approval_required' : $state;
    }

    private function evaluate(CarbonImmutable $from, CarbonImmutable $to, array $needs): string
    {
        $overlap = array_values(array_filter($this->commitments, fn ($c) => $c['from'] < $to && $c['to'] > $from));
        $points = array_merge([$from], array_map(fn ($c) => max($c['from'], $from), $overlap));
        $short = false;
        foreach ($points as $p) {
            $active = array_filter($overlap, fn ($c) => $c['from'] <= $p && $c['to'] > $p);
            $total = $needs;
            foreach ($active as $c) foreach ($c['needs'] as $k => $q) $total[$k] = ($total[$k] ?? 0) + $q;
            foreach ($total as $key => $qty) {
                if (str_starts_with($key, 'staff:') || str_starts_with($key, 'person:')) continue;
                if ($qty > ($this->units[$key] ?? 0)) return 'unavailable';
            }
            if (! $this->staffFits($total)) $short = true;
        }
        return $short ? 'owner_approval_required' : 'available';
    }

    /** Joint assignment: every staff role unit needs a different person with that skill; blocked people are out. */
    private function staffFits(array $total): bool
    {
        $blocked = []; $roles = [];
        foreach ($total as $key => $qty) {
            if (str_starts_with($key, 'person:')) $blocked[(int) substr($key, 7)] = true;
            if (str_starts_with($key, 'staff:')) for ($i = 0; $i < $qty; $i++) $roles[] = substr($key, 6);
        }
        $people = array_keys(array_diff_key($this->staff, $blocked));
        if (count($roles) > count($people)) return false;
        $owner = []; // person index => role index
        foreach (array_keys($roles) as $r) {
            $seen = [];
            if (! $this->augment($r, $roles, $people, $owner, $seen)) return false;
        }
        return true;
    }

    private function augment(int $r, array $roles, array $people, array &$owner, array &$seen): bool
    {
        foreach ($people as $p) {
            if (isset($seen[$p]) || ! in_array($roles[$r], $this->staff[$p], true)) continue;
            $seen[$p] = true;
            if (! isset($owner[$p]) || $this->augment($owner[$p], $roles, $people, $owner, $seen)) { $owner[$p] = $r; return true; }
        }
        return false;
    }
}
