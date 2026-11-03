<?php
namespace App\Services\Team;

use App\Filament\Support\Labels;
use App\Models\{Character, Event, EventTeamPlan, Resource, ResourceBlock, ResourceReservation};
use Carbon\CarbonImmutable;

/**
 * Owner 2026-11-02: the roles a party needs come from its package (e.g. Waoo = 1 animador + 2 recreadores + Elsa), and the
 * team is chosen the week of the party. Driving is not a separate seat: someone on the team drives (or an external driver).
 */
final class TeamSlots
{
    /** Gap kept between two parties for the same person or costume when the drive is unknown (same as the website rule). */
    public const GAP_MINUTES = 60;

    /** @return array{people:list<array{key:string,label:string,skill:string}>, costumes:list<array{key:string,label:string,character_id:int,outfit:?string}>, needs_driver:bool} */
    public static function for(Event $event): array
    {
        $people = []; $costumes = []; $driver = false; $count = [];
        foreach ($event->services()->orderBy('id')->get() as $service) {
            foreach ((array) $service->requirements_snapshot as $r => $req) {
                for ($i = 0; $i < (int) ($req['quantity'] ?? 0); $i++) {
                    $key = $service->id.'-'.$r.'-'.$i;
                    if ($req['kind'] === 'staff' && ($req['skill'] ?? null) === 'driver') { $driver = true; continue; }
                    if ($req['kind'] === 'staff' && ($req['skill'] ?? null) === 'performer') continue; // the costume seat below
                    if ($req['kind'] === 'staff') {
                        $skill = (string) ($req['skill'] ?? 'host');
                        $n = $count[$skill] = ($count[$skill] ?? 0) + 1;
                        $people[] = ['key' => 's'.$key, 'label' => (Labels::SKILL[$skill] ?? $skill).' '.$n, 'skill' => $skill];
                    } elseif ($req['kind'] === 'costume') {
                        $name = Character::whereKey($req['character_id'])->value('name') ?? 'Personaje';
                        $costumes[] = ['key' => 'c'.$key, 'label' => $name.(isset($req['outfit']) ? ' ('.$req['outfit'].')' : ''), 'character_id' => (int) $req['character_id'], 'outfit' => $req['outfit'] ?? null];
                    }
                }
            }
        }
        return ['people' => $people, 'costumes' => $costumes, 'needs_driver' => $driver || $people !== [] || $costumes !== []];
    }

    /** Busy window of a party (setup to breakdown). */
    public static function window(Event $e): array
    {
        return [CarbonImmutable::instance($e->starts_at)->subMinutes((int) $e->setup_minutes), CarbonImmutable::instance($e->ends_at)->addMinutes((int) $e->breakdown_minutes)];
    }

    /** Whether a person or costume is free for this party (blocks and other parties, keeping the travel gap). */
    public static function free(Resource $unit, Event $event, array $alsoBusy = []): bool
    {
        if ($unit->status !== 'active') return false;
        [$from, $to] = self::window($event);
        if (ResourceBlock::where('resource_id', $unit->id)->whereNull('released_at')->where('starts_at', '<', $to)->where('ends_at', '>', $from)->exists()) return false;
        $gap = self::GAP_MINUTES;
        foreach (ResourceReservation::where('resource_id', $unit->id)->where('event_id', '!=', $event->id)->with('event')->get() as $r) {
            $o = $r->event; if (! $o || $o->status === 'cancelled') continue;
            [$of, $ot] = self::window($o);
            if ($of->lessThan($to->addMinutes($gap)) && $ot->greaterThan($from->subMinutes($gap))) return false;
        }
        foreach ($alsoBusy as [$bf, $bt]) if ($bf->lessThan($to->addMinutes($gap)) && $bt->greaterThan($from->subMinutes($gap))) return false;
        return true;
    }

    /** The current plan of a party, or an empty one. */
    public static function current(Event $e): array
    {
        $p = EventTeamPlan::where('event_id', $e->id)->first();
        return ['people' => collect($p?->slots ?? [])->pluck('resource_id', 'key')->all(), 'costumes' => collect($p?->costumes ?? [])->keyBy('key')->all(),
            'driver' => $p?->external_driver ? 'external' : $p?->driver_resource_id, 'version' => $p?->version ?? 0];
    }
}
