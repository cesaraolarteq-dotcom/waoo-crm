<?php
namespace App\Services\Team;

use App\Models\{Character, Event, Resource};
use App\Services\MatchEventResources;
use App\Support\CostumeWearers;
use Illuminate\Support\Facades\DB;

/**
 * "Sugerir todo": fills the whole weekend at once instead of party by party, so the first party never takes the person a
 * later overlapping party needed. Parties whose busy windows overlap (with the travel gap) are matched together, each person
 * once per group; later groups can reuse people who are free by then. Read only: the owner reviews and saves.
 */
final class SuggestWeekendTeams
{
    /** @param list<Event> $events @return array<int, array{people:array<string,int>, costumes:array<string,array{unit_id:?int,wearer_id:?int}>, driver:int|string|null}> */
    public function execute(array $events): array
    {
        usort($events, fn ($a, $b) => $a->starts_at <=> $b->starts_at);
        $staff = Resource::where('kind', 'staff')->where('status', 'active')->orderBy('id')->get();
        $costumeUnits = Resource::where('kind', 'costume')->where('status', 'active')->orderBy('id')->get();
        $compat = DB::table('costume_compatibilities')->get();
        $busy = []; // resource_id => list of [from,to] chosen in this suggestion
        $out = [];
        foreach ($this->groups($events) as $group) {
            $slots = []; $owners = [];
            foreach ($group as $e) {
                $need = TeamSlots::for($e);
                foreach ($need['people'] as $s) { $slots[] = ['kind' => 'staff', 'skill' => $s['skill'], 'event' => $e, 'key' => $s['key']]; }
                foreach ($need['costumes'] as $c) {
                    // Costume unit first: a free unit of that character (and version), then its wearer as a people seat.
                    $unit = $costumeUnits->first(fn ($u) => (int) $u->character_id === $c['character_id'] && ($c['outfit'] === null || $u->outfit === null || $u->outfit === $c['outfit'])
                        && TeamSlots::free($u, $e, $busy[$u->id] ?? []));
                    $out[$e->id]['costumes'][$c['key']] = ['unit_id' => $unit?->id, 'wearer_id' => null];
                    if ($unit) { $busy[$unit->id][] = TeamSlots::window($e); $slots[] = ['kind' => 'staff', 'skill' => 'performer', 'costume_unit' => $unit, 'event' => $e, 'key' => $c['key']]; }
                }
            }
            $candidates = $staff->filter(fn ($p) => collect($group)->contains(fn ($e) => TeamSlots::free($p, $e, $busy[$p->id] ?? [])))->values()->all();
            try {
                $assigned = app(MatchEventResources::class)->execute($slots, $candidates, function ($slot, $p) use ($busy, $compat) {
                    if (! TeamSlots::free($p, $slot['event'], $busy[$p->id] ?? [])) return false;
                    if (! in_array($slot['skill'], $p->skills ?? [], true)) return false;
                    if (isset($slot['costume_unit'])) return CostumeWearers::allows($slot['costume_unit'], $p, Character::find($slot['costume_unit']->character_id), $compat);
                    return true;
                });
            } catch (\Illuminate\Validation\ValidationException) {
                $assigned = $this->partial($slots, $candidates, $busy, $compat); // not everyone fits: fill what can be filled
            }
            foreach ($assigned as $i => $slotIndex) {
                $p = $candidates[$i]; $slot = $slots[$slotIndex]; $e = $slot['event'];
                if (isset($slot['costume_unit'])) $out[$e->id]['costumes'][$slot['key']]['wearer_id'] = $p->id;
                else $out[$e->id]['people'][$slot['key']] = $p->id;
                $busy[$p->id][] = TeamSlots::window($e);
            }
        }
        foreach ($events as $e) {
            $out[$e->id] ??= ['people' => [], 'costumes' => []];
            $out[$e->id] += ['people' => [], 'costumes' => []];
            $team = array_filter([...array_values($out[$e->id]['people']), ...array_column($out[$e->id]['costumes'], 'wearer_id')]);
            $drivers = $staff->whereIn('id', $team)->filter(fn ($p) => MatchEventResources::driver($p))->pluck('id')->values();
            $out[$e->id]['driver'] = $drivers->first(); // one driver on the team: it is that person; several: the first, the owner may change it
        }
        return $out;
    }

    /** Parties that overlap (busy window + travel gap) are planned together. */
    private function groups(array $events): array
    {
        $groups = []; $end = null;
        foreach ($events as $e) {
            [$from, $to] = TeamSlots::window($e);
            if ($end && $from->lessThan($end->addMinutes(TeamSlots::GAP_MINUTES))) { $groups[count($groups) - 1][] = $e; $end = max($end, $to); }
            else { $groups[] = [$e]; $end = $to; }
        }
        return $groups;
    }

    /** Best effort when not every seat can be covered: seat by seat, first free fitting person. */
    private function partial(array $slots, array $candidates, array $busy, $compat): array
    {
        $used = []; $out = [];
        foreach ($slots as $si => $slot) {
            foreach ($candidates as $ci => $p) {
                if (isset($used[$ci]) || ! in_array($slot['skill'], $p->skills ?? [], true) || ! TeamSlots::free($p, $slot['event'], $busy[$p->id] ?? [])) continue;
                if (isset($slot['costume_unit']) && ! CostumeWearers::allows($slot['costume_unit'], $p, Character::find($slot['costume_unit']->character_id), $compat)) continue;
                $used[$ci] = true; $out[$ci] = $si; break;
            }
        }
        return $out;
    }
}
