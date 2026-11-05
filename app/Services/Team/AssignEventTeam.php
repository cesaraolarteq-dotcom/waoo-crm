<?php
namespace App\Services\Team;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Character, Event, EventTeamPlan, Resource, ResourceReservation, User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\MatchEventResources;
use App\Services\Records\{RecordActor, RecordInput};
use App\Support\CostumeWearers;
use Illuminate\Support\Facades\DB;

/**
 * Saves who works one party (owner, the week of the party). Every seat must be a person with that skill who is free at that
 * time (blocks, other parties, travel gap); a costume seat needs a free unit of that character and a person allowed to wear
 * it. Someone on the team drives, or the owner marks an external driver. Re-saving replaces only what this plan reserved.
 */
final class AssignEventTeam
{
    public function execute(User $actor, int $eventId, array $input, string $key): EventTeamPlan
    {
        $actor = RecordActor::authorize($actor);
        $d = RecordInput::validate($input, ['expected_plan_version' => 'required|integer|min:0', 'people' => 'present|array|max:50', 'people.*' => 'nullable|integer',
            'costumes' => 'present|array|max:50', 'costumes.*.unit_id' => 'nullable|integer', 'costumes.*.wearer_id' => 'nullable|integer', 'driver' => 'nullable']);
        $scope = "owner:{$actor->id}:event:{$eventId}:team"; $hash = MutationKey::hash($d);
        return DB::transaction(function () use ($actor, $eventId, $d, $scope, $hash, $key) {
            \App\Services\Scheduling\DailyCapacity::lock();
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            if ($saved = MutationKey::find($scope, $key, $hash, true)) return EventTeamPlan::findOrFail($saved->result_id);
            RecordWriteRejected::unless($event->status === 'capacity_reserved' && $event->ends_at->isFuture(), 409, 'Solo se arma el equipo de fiestas confirmadas que no han pasado.');
            $plan = EventTeamPlan::where('event_id', $eventId)->lockForUpdate()->first();
            RecordWriteRejected::unless(($plan?->version ?? 0) === (int) $d['expected_plan_version'], 409, 'Alguien cambió este equipo. Recarga la página.');
            $need = TeamSlots::for($event);
            $people = collect($need['people'])->keyBy('key'); $costumeSeats = collect($need['costumes'])->keyBy('key');
            RecordWriteRejected::unless(array_diff(array_keys($d['people']), $people->keys()->all()) === [] && array_diff(array_keys($d['costumes']), $costumeSeats->keys()->all()) === [], 422, 'El paquete cambió. Recarga la página.');
            $ids = collect($d['people'])->filter()->merge(collect($d['costumes'])->pluck('unit_id')->filter())->merge(collect($d['costumes'])->pluck('wearer_id')->filter())
                ->merge(is_numeric($d['driver'] ?? null) ? [(int) $d['driver']] : [])->map(fn ($i) => (int) $i)->unique()->sort()->values();
            $characterIds = Resource::whereIn('id', $ids)->where('kind', 'costume')->pluck('character_id')->filter()->unique()->sort()->values();
            $characters = Character::whereIn('id', $characterIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $units = Resource::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            RecordWriteRejected::unless($units->count() === $ids->count(), 422, 'Una persona o traje ya no existe.');
            // Current reads after the unit locks: other parties' reservations of these people/costumes.
            ResourceReservation::whereIn('resource_id', $ids)->orderBy('id')->lockForUpdate()->get();
            $own = collect($plan?->reservation_ids ?? []);
            $team = [];
            foreach ($d['people'] as $seat => $id) {
                if (! $id) continue; $p = $units[(int) $id];
                RecordWriteRejected::unless($p->kind === 'staff' && in_array($people[$seat]['skill'], $p->skills ?? [], true), 422, $p->name.' no tiene la habilidad para '.$people[$seat]['label'].'.');
                RecordWriteRejected::unless(TeamSlots::free($p, $event), 409, $p->name.' ya tiene otra fiesta o un bloqueo a esa hora.');
                RecordWriteRejected::unless(! in_array($p->id, $team, true), 422, $p->name.' está en dos puestos de la misma fiesta.');
                $team[] = $p->id;
            }
            $compat = DB::table('costume_compatibilities')->get(); $costumes = [];
            foreach ($d['costumes'] as $seat => $c) {
                $unit = ($c['unit_id'] ?? null) ? $units[(int) $c['unit_id']] : null; $wearer = ($c['wearer_id'] ?? null) ? $units[(int) $c['wearer_id']] : null;
                $s = $costumeSeats[$seat];
                if ($unit) {
                    RecordWriteRejected::unless($characters->has((int) $unit->character_id) && \App\Services\CostumeOutfits::fits($s, $unit, $characters, []), 422, 'Ese traje no es de '.$s['label'].'.');
                    RecordWriteRejected::unless(TeamSlots::free($unit, $event), 409, 'El traje '.$unit->name.' ya está en otra fiesta a esa hora.');
                }
                if ($wearer) {
                    RecordWriteRejected::unless($unit !== null, 422, 'Elige primero el traje de '.$s['label'].'.');
                    RecordWriteRejected::unless($wearer->kind === 'staff' && CostumeWearers::allows($unit, $wearer, $characters->get((int) $unit->character_id), $compat), 422, $wearer->name.' no puede usar el traje '.$unit->name.'.');
                    RecordWriteRejected::unless(TeamSlots::free($wearer, $event), 409, $wearer->name.' ya tiene otra fiesta o un bloqueo a esa hora.');
                    RecordWriteRejected::unless(! in_array($wearer->id, $team, true), 422, $wearer->name.' está en dos puestos de la misma fiesta.');
                    $team[] = $wearer->id;
                }
                $costumes[] = ['key' => $seat, 'character_id' => $s['character_id'], 'unit_id' => $unit?->id, 'wearer_id' => $wearer?->id];
            }
            // Partial planning remains possible. A full roster cannot silently omit a package-wide capability.
            if (count($team) === count($need['people']) + count($need['costumes'])) {
                $missing = \App\Support\CrewSkills::missing($need['team_skills'] ?? [], $units->whereIn('id', $team));
                RecordWriteRejected::unless($missing === [], 422, 'Falta: '.collect($missing)->map(fn ($s) => \App\Filament\Support\Labels::label(\App\Filament\Support\Labels::SKILL, $s))->implode(', ').'.');
            }
            $external = ($d['driver'] ?? null) === 'external';
            $driver = is_numeric($d['driver'] ?? null) ? $units[(int) $d['driver']] : null;
            if ($driver) RecordWriteRejected::unless(in_array($driver->id, $team, true) && MatchEventResources::driver($driver), 422, 'El conductor tiene que ser alguien del equipo que maneje.');
            if (! $driver && ! $external) {
                $drivers = collect($team)->filter(fn ($id) => MatchEventResources::driver($units[$id]))->values();
                if ($drivers->count() === 1) $driver = $units[$drivers->first()]; // only one driver on the team: it is that person
            }
            // Replace what this plan reserved before; keep units reserved by the booking itself (e.g. costumes held at confirmation).
            ResourceReservation::whereIn('id', $own)->where('event_id', $event->id)->delete();
            $created = [];
            foreach (array_unique([...$team, ...array_filter(array_column($costumes, 'unit_id'))]) as $id) {
                if (ResourceReservation::where('event_id', $event->id)->where('resource_id', $id)->exists()) continue;
                $created[] = ResourceReservation::create(['event_id' => $event->id, 'resource_id' => $id, 'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at])->id;
            }
            $slots = $people->map(fn ($s) => [...$s, 'resource_id' => isset($d['people'][$s['key']]) && $d['people'][$s['key']] ? (int) $d['people'][$s['key']] : null])->values()->all();
            $payload = ['event_id' => $event->id, 'slots' => $slots, 'costumes' => $costumes, 'driver' => $driver?->id, 'external' => $external];
            $before = $plan?->only(['slots', 'costumes', 'driver_resource_id', 'external_driver']) ?? [];
            $values = ['version' => ($plan?->version ?? 0) + 1, 'slots' => $slots, 'costumes' => $costumes, 'driver_resource_id' => $driver?->id, 'external_driver' => $external,
                'reservation_ids' => $created, 'payload_sha256' => MutationKey::hash($payload), 'assigned_by' => $actor->id];
            $plan = $plan ? tap($plan)->update($values) : EventTeamPlan::create(['event_id' => $event->id, ...$values]);
            Audit::recordFor($actor, 'event.team_assigned', $event, $before, ['plan_version' => $plan->version, 'people' => $team, 'driver' => $driver?->id, 'external_driver' => $external]);
            MutationKey::store($scope, $key, $hash, 'event_team_plan', $plan->id);
            return $plan;
        }, 3);
    }
}
