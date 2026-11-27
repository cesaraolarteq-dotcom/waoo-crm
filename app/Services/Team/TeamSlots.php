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
            $paired=collect($service->requirements_snapshot ?? [])->where('kind','costume')->sum('quantity');
            foreach ((array) $service->requirements_snapshot as $r => $req) {
                for ($i = 0; $i < (int) ($req['quantity'] ?? 0); $i++) {
                    $key = $service->id.'-'.$r.'-'.$i;
                    if ($req['kind'] === 'staff' && ($req['skill'] ?? null) === 'driver') { $driver = true; continue; }
                    if ($req['kind'] === 'staff' && in_array($req['skill'] ?? null,['performer','performer_wig'],true) && $paired>0) { $paired--;continue; } // exactly one wearer per costume seat
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
        return ['people' => $people, 'costumes' => $costumes, 'needs_driver' => $driver || $people !== [] || $costumes !== [], 'team_skills' => self::teamSkills($event)];
    }

    /**
     * Owner 2026-11-04: in the Waoo «1 animador + 2 recreadoras», at least one of the three paints faces — it is not a seat of its own.
     * A service that brings the face-paint kit without a face-painter seat needs someone on the team with that skill.
     */
    public static function teamSkills(Event $event): array
    {
        return \App\Support\CrewSkills::required($event->services()->get()->flatMap(fn ($s) => $s->requirements_snapshot ?? [])->all());
    }

    /** Busy window of a party (setup to breakdown). */
    public static function window(Event $e): array
    {
        return [CarbonImmutable::instance($e->starts_at)->subMinutes((int) $e->setup_minutes), CarbonImmutable::instance($e->ends_at)->addMinutes((int) $e->breakdown_minutes)];
    }

    /** Whether a person or costume is free for this party (blocks and other parties, keeping the travel gap). */
    public static function free(Resource $unit, Event $event, array $alsoBusy = [], int $gapMinutes = self::GAP_MINUTES, bool $currentRead = false): bool
    {
        if ($unit->status !== 'active') return false;
        [$from, $to] = self::window($event);
        $blocks=ResourceBlock::where('resource_id', $unit->id)->whereNull('released_at')->where('starts_at', '<', $to)->where('ends_at', '>', $from);
        if (($currentRead?$blocks->lockForUpdate():$blocks)->exists()) return false;
        $gap = max(0,$gapMinutes); // zero only when the caller separately verifies a real route proof before committing
        $bookings=ResourceReservation::where('resource_id', $unit->id)->where('event_id', '!=', $event->id)
            ->with(['event'=>fn($q)=>$currentRead?$q->sharedLock():$q]);
        foreach (($currentRead?$bookings->lockForUpdate():$bookings)->get() as $r) {
            $o = $r->event; if (!$o) return false;if ($o->status === 'cancelled') continue;
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
