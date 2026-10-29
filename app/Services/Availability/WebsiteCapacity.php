<?php
namespace App\Services\Availability;

use App\Models\{Event, ImportedProjectLink, LedgerEntry, QuickBookingRecord, Resource};
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Owner rules 2026-10-29 for website availability (read-only, no writes, no holds):
 * - at most 4 parties at the same time (4 speakers) and 10 parties per Houston day; any hour of the day;
 * - each party's busy time is setup + party + breakdown + 30 minutes between parties;
 * - costumes and equipment are physical: a start that needs more units than are free is unavailable;
 * - every party needs a driver, and staff with the package's skills; when people are short the start is still offered,
 *   but as owner approval (the owner can call someone in);
 * - until the switch, HoneyBook bookings reach the CRM only as imports, so the owner's Google Calendar busy times that
 *   match no CRM party count as one more party (no costume/equipment known).
 * Commitments are CRM parties that are booked: confirmed, a deposit, a quick booking or a HoneyBook import. Cancelled
 * parties and plain drafts (quotes not booked) do not block.
 */
final class WebsiteCapacity
{
    public const MAX_SIMULTANEOUS = 4;
    public const MAX_PER_DAY = 10;
    public const GAP_MINUTES = 30;

    /** @var list<array{from: CarbonImmutable, to: CarbonImmutable, day: string, needs: array<string,int>, source: string}> */
    private array $commitments;
    private array $units;
    private bool $googleOk;

    public function __construct(private CarbonImmutable $dayStart, ?array $googleBusy)
    {
        $from = $dayStart->subDay(); $to = $dayStart->addDays(2);
        $booked = Event::query()->where('status', '!=', 'cancelled')->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())
            ->with('services')->get()->filter(fn (Event $e) => $e->status === 'capacity_reserved'
                || ImportedProjectLink::where('event_id', $e->id)->exists() || QuickBookingRecord::where('event_id', $e->id)->exists()
                || LedgerEntry::where('event_id', $e->id)->where('kind', 'receipt')->exists());
        $this->commitments = $booked->map(fn (Event $e) => [
            'from' => CarbonImmutable::instance($e->starts_at)->subMinutes((int) $e->setup_minutes),
            'to' => CarbonImmutable::instance($e->ends_at)->addMinutes((int) $e->breakdown_minutes + self::GAP_MINUTES),
            'starts' => CarbonImmutable::instance($e->starts_at),
            'day' => CarbonImmutable::instance($e->starts_at)->setTimezone('America/Chicago')->toDateString(),
            'needs' => self::needs($e->services->flatMap(fn ($s) => $s->requirements_snapshot ?? [])->all()),
            'source' => 'crm',
        ])->values()->all();
        $this->googleOk = $googleBusy !== null;
        foreach ($googleBusy ?? [] as [$gFrom, $gTo]) {
            // A HoneyBook party already imported shows up in both places: count it once.
            $same = collect($this->commitments)->contains(fn ($c) => abs($c['starts']->diffInMinutes($gFrom, true)) <= 30);
            if ($same) continue;
            $this->commitments[] = ['from' => $gFrom, 'to' => $gTo->addMinutes(self::GAP_MINUTES), 'starts' => $gFrom,
                'day' => $gFrom->setTimezone('America/Chicago')->toDateString(), 'needs' => ['party' => 1, 'staff:driver' => 1], 'source' => 'google'];
        }
        $resources = Resource::query()->where('status', 'active')->get(['kind', 'equipment_kind', 'character_id', 'skills']);
        $units = ['party' => self::MAX_SIMULTANEOUS];
        foreach ($resources as $r) {
            if ($r->kind === 'costume' && $r->character_id) $units['costume:'.$r->character_id] = ($units['costume:'.$r->character_id] ?? 0) + 1;
            if ($r->kind === 'equipment' && $r->equipment_kind) $units['equipment:'.$r->equipment_kind] = ($units['equipment:'.$r->equipment_kind] ?? 0) + 1;
            if ($r->kind === 'staff') foreach ((array) $r->skills as $skill) $units['staff:'.$skill] = ($units['staff:'.$skill] ?? 0) + 1;
        }
        $this->units = $units;
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

    public function googleOk(): bool { return $this->googleOk; }

    public function partiesOn(string $day): int
    {
        return count(array_filter($this->commitments, fn ($c) => $c['day'] === $day));
    }

    /** 'available' | 'owner_approval_required' | 'unavailable' for one candidate busy interval. */
    public function check(CarbonImmutable $from, CarbonImmutable $to, string $day, array $needs): string
    {
        if ($this->partiesOn($day) >= self::MAX_PER_DAY) return 'unavailable';
        $overlap = array_values(array_filter($this->commitments, fn ($c) => $c['from'] < $to && $c['to'] > $from));
        // Peak use inside the candidate interval: evaluate at every overlapping commitment start (and the candidate start).
        $points = array_merge([$from], array_map(fn ($c) => max($c['from'], $from), $overlap));
        $short = false;
        foreach ($points as $p) {
            $active = array_filter($overlap, fn ($c) => $c['from'] <= $p && $c['to'] > $p);
            foreach ($needs as $key => $qty) {
                $used = array_sum(array_map(fn ($c) => $c['needs'][$key] ?? 0, $active));
                $have = $this->units[$key] ?? 0;
                if ($used + $qty <= $have) continue;
                if (str_starts_with($key, 'staff:')) { $short = true; continue; }
                return 'unavailable';
            }
        }
        return $short ? 'owner_approval_required' : 'available';
    }
}
