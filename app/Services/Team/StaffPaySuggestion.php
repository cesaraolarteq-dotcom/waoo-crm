<?php
namespace App\Services\Team;

use App\Filament\Support\Labels;
use App\Models\{Event, EventTeamPlan, Resource, StaffPayLine, StaffPayRate};
use Carbon\CarbonImmutable;

/**
 * Owner 2026-11-03: what each person should get for the parties they worked, as a suggestion the owner can change.
 * Suggestion = the role's rate × the hours they were there (setup + party + breakdown), or a flat rate per party, plus the
 * driving rate when they drove. Each line says why. Nothing here pays anyone: it is the owner's list.
 */
final class StaffPaySuggestion
{
    public const DRIVING = 'driving';

    /** @return list<array{resource_id:int,name:string,total_cents:int,missing_rate:bool,paid:bool,lines:list<array>}> */
    public function week(CarbonImmutable $monday): array
    {
        $from = $monday->setTimezone('America/Chicago')->startOfDay(); $to = $from->addWeek();
        $rates = StaffPayRate::all()->keyBy('role');
        $events = Event::where('status', 'capacity_reserved')->where('starts_at', '>=', $from->utc())->where('starts_at', '<', $to->utc())->orderBy('starts_at')->get();
        $people = [];
        foreach ($events as $e) {
            $plan = EventTeamPlan::where('event_id', $e->id)->first();
            if (! $plan) continue;
            $seats = [];
            foreach ($plan->slots ?? [] as $s) if ($s['resource_id'] ?? null) $seats[(int) $s['resource_id']] = $s['skill'];
            foreach ($plan->costumes ?? [] as $c) if ($c['wearer_id'] ?? null) $seats[(int) $c['wearer_id']] = 'performer';
            foreach ($seats as $rid => $skill) {
                $people[$rid]['lines'][] = $this->line($e, $rid, $skill, (int) $plan->driver_resource_id === $rid, $rates);
            }
        }
        $names = Resource::whereIn('id', array_keys($people))->pluck('name', 'id');
        $out = [];
        foreach ($people as $rid => $p) {
            $out[] = ['resource_id' => $rid, 'name' => $names[$rid] ?? '—', 'lines' => $p['lines'],
                'total_cents' => array_sum(array_column($p['lines'], 'amount_cents')),
                'missing_rate' => collect($p['lines'])->contains(fn ($l) => $l['suggested_cents'] === null && $l['decided'] === null),
                'paid' => collect($p['lines'])->every(fn ($l) => $l['paid'])];
        }
        usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    private function line(Event $e, int $rid, string $skill, bool $drove, $rates): array
    {
        $minutes = (int) $e->setup_minutes + (int) $e->starts_at->diffInMinutes($e->ends_at) + (int) $e->breakdown_minutes;
        $hours = round($minutes / 60, 2);
        $role = Labels::SKILL[$skill] ?? $skill;
        $why = []; $cents = 0; $missing = false;
        if ($r = $rates[$skill] ?? null) {
            $part = $r->per === 'hour' ? (int) round($r->amount_cents * $minutes / 60) : (int) $r->amount_cents;
            $cents += $part;
            $why[] = $r->per === 'hour'
                ? $role.': '.self::hours($minutes).' ('.((int) $e->setup_minutes).' min montaje + '.self::hours((int) $e->starts_at->diffInMinutes($e->ends_at)).' fiesta + '.((int) $e->breakdown_minutes).' min desmontaje) × '.Labels::money($r->amount_cents).'/h = '.Labels::money($part)
                : $role.': tarifa por fiesta '.Labels::money($part);
        } else {
            $missing = true; $why[] = $role.': falta la tarifa de este puesto';
        }
        if ($drove) {
            if ($d = $rates[self::DRIVING] ?? null) {
                $part = $d->per === 'hour' ? (int) round($d->amount_cents * $minutes / 60) : (int) $d->amount_cents;
                $cents += $part; $why[] = 'Manejó: '.Labels::money($part);
            } else { $missing = true; $why[] = 'Manejó: falta la tarifa de manejo'; }
        }
        $decided = StaffPayLine::where('event_id', $e->id)->where('resource_id', $rid)->first();
        $suggested = $missing ? null : $cents;

        return ['event_id' => $e->id, 'title' => $e->title, 'when' => Labels::when($e->starts_at), 'role' => $role, 'hours' => $hours, 'drove' => $drove,
            'suggested_cents' => $suggested, 'why' => $why, 'decided' => $decided?->amount_cents, 'note' => $decided?->note, 'paid' => (bool) $decided?->paid_at,
            'amount_cents' => $decided?->amount_cents ?? $suggested ?? 0];
    }

    private static function hours(int $minutes): string
    {
        $h = intdiv($minutes, 60); $m = $minutes % 60;

        return $h.' h'.($m ? ' '.$m.' min' : '');
    }
}
