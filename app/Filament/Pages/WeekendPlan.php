<?php

namespace App\Filament\Pages;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Support\Labels;
use App\Models\{Event, Resource};
use App\Services\MatchEventResources;
use App\Services\Team\{AssignEventTeam, SuggestWeekendTeams, TeamSlots};
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

// Owner 2026-11-02: plan the whole weekend's teams at once (Tue–Wed), seats from each package, "Sugerir todo", one save.
// 2026-11-03: the whole week, what each person answered on Monday, who confirmed, and "Reemplazar" for who can't.
class WeekendPlan extends Page
{
    protected static ?string $slug = 'planear-fin-de-semana';
    protected static ?string $title = 'Planear el fin de semana';
    protected static bool $shouldRegisterNavigation = false; // reached through the Agenda tabs
    protected string $view = 'filament.pages.weekend-plan';

    /** Friday (Houston) of the weekend on screen. */
    public string $friday = '';
    /** event_id => ['people' => [seat => id|null], 'costumes' => [seat => ['unit_id','wearer_id']], 'driver' => id|'external'|null] */
    public array $picks = [];
    #[Locked] public array $versions = [];
    #[Locked] public array $keys = [];

    public function mount(): void
    {
        $now = CarbonImmutable::now('America/Chicago');
        // From a party's "Equipo" tab: open the weekend of that party.
        if ($party = Event::find((int) request()->query('fiesta'))) $now = CarbonImmutable::instance($party->starts_at)->setTimezone('America/Chicago');
        $this->friday = ($now->isFriday() || $now->isWeekend() ? $now->startOfWeek(CarbonImmutable::MONDAY)->addDays(4) : $now->next(CarbonImmutable::FRIDAY))->toDateString();
        $this->load();
    }

    public function shiftWeek(int $weeks): void
    {
        $this->friday = CarbonImmutable::parse($this->friday)->addWeeks($weeks)->toDateString();
        $this->load();
    }

    /** @return \Illuminate\Support\Collection<int, Event> confirmed parties Fri–Sun of the weekend on screen */
    public function events()
    {
        // Owner 2026-11-03: the whole week (Mon–Sun) so a party on a weekday is planned here too; past ones drop off.
        $from = CarbonImmutable::parse($this->friday, 'America/Chicago')->subDays(4)->startOfDay()->utc();
        return Event::where('status', 'capacity_reserved')->where('starts_at', '>=', $from)->where('starts_at', '<', $from->addDays(7))
            ->where('ends_at', '>', now())->orderBy('starts_at')->with('services')->get();
    }

    private function load(): void
    {
        $this->picks = []; $this->versions = []; $this->keys = [];
        foreach ($this->events() as $e) {
            $c = TeamSlots::current($e);
            $need = TeamSlots::for($e);
            // Audit 2026-11-04: with no team saved yet, a costume already reserved for this party (at confirm) fills its seat.
            $reserved = Resource::where('kind', 'costume')->whereIn('id', \App\Models\ResourceReservation::where('event_id', $e->id)->pluck('resource_id'))->orderBy('name')->get();
            $taken = collect($c['costumes'])->pluck('unit_id')->filter()->map(fn ($id) => (int) $id)->all();
            $reserved = $reserved->whereNotIn('id', $taken)->values();
            $missing = array_values(array_filter($need['costumes'], fn ($s) => empty($c['costumes'][$s['key']]['unit_id'])));
            $characters = \App\Models\Character::whereIn('id', array_column($missing, 'character_id'))->get()->keyBy('id');
            // Match all missing seats together: a generic seat must not take the only unit of a specific wardrobe.
            $assignment = app(\App\Services\MatchEventResources::class)->partial($missing, $reserved->all(),
                fn ($slot, $unit) => \App\Services\CostumeOutfits::fits($slot, $unit, $characters, []));
            $defaults = [];
            foreach ($assignment as $unitIndex => $slotIndex) $defaults[$missing[$slotIndex]['key']] = $reserved[$unitIndex]->id;
            $this->picks[$e->id] = ['people' => collect($need['people'])->mapWithKeys(fn ($s) => [$s['key'] => $c['people'][$s['key']] ?? null])->all(),
                'costumes' => collect($need['costumes'])->mapWithKeys(function ($s) use ($c, $defaults) {
                    $unit = $c['costumes'][$s['key']]['unit_id'] ?? $defaults[$s['key']] ?? null;
                    return [$s['key'] => ['unit_id' => $unit, 'wearer_id' => $c['costumes'][$s['key']]['wearer_id'] ?? null]];
                })->all(),
                'driver' => $c['driver']];
            $this->versions[$e->id] = $c['version'];
            $this->keys[$e->id] = (string) Str::uuid();
        }
    }

    public function suggest(): void
    {
        $s = app(SuggestWeekendTeams::class)->execute($this->events()->all());
        foreach ($s as $id => $team) {
            if (! isset($this->picks[$id])) continue;
            foreach ($team['people'] as $seat => $person) if (array_key_exists($seat, $this->picks[$id]['people'])) $this->picks[$id]['people'][$seat] = $person;
            foreach ($team['costumes'] as $seat => $c) if (array_key_exists($seat, $this->picks[$id]['costumes'])) $this->picks[$id]['costumes'][$seat] = $c;
            $this->picks[$id]['driver'] = $team['driver'] ?? null;
        }
        Notification::make()->success()->title('Sugerencia lista')->body('Revisa y cambia lo que quieras. Nada se guarda hasta que toques «Guardar».')->send();
    }

    public function saveAll(): void
    {
        $ok = 0; $errors = [];
        foreach ($this->events() as $e) {
            if (! isset($this->picks[$e->id])) continue;
            try {
                $pk = $this->picks[$e->id];
                // A driver who is no longer on the team (replaced or changed) is dropped; the team's only driver is then chosen again.
                $team = array_map('intval', array_filter([...array_values($pk['people']), ...array_column($pk['costumes'], 'wearer_id')]));
                $driver = $pk['driver'] ?: null;
                if (is_numeric($driver) && ! in_array((int) $driver, $team, true)) $driver = null;
                app(AssignEventTeam::class)->execute(Filament::auth()->user(), $e->id, ['expected_plan_version' => $this->versions[$e->id] ?? 0,
                    'people' => $pk['people'], 'costumes' => $pk['costumes'], 'driver' => $driver], $this->keys[$e->id]);
                $ok++;
            } catch (RecordWriteRejected|ValidationException|AuthorizationException $x) {
                $errors[] = $e->title.': '.($x instanceof ValidationException ? collect($x->errors())->flatten()->first() : $x->getMessage());
            }
        }
        $this->load();
        if ($errors) Notification::make()->warning()->title($ok.' guardadas · '.count($errors).' con problema')->body(implode("\n", $errors))->persistent()->send();
        else Notification::make()->success()->title('Equipo guardado')->body($ok.' fiestas. Cada persona ya lo ve en su enlace del staff.')->send();
    }

    /** Audit 2026-11-04: an empty week offers a jump to the next week with a confirmed party. */
    public function nextWeekWithParties(): ?array
    {
        $from = CarbonImmutable::parse($this->friday, 'America/Chicago')->subDays(4)->startOfDay()->addDays(7);
        $e = Event::where('status', 'capacity_reserved')->where('starts_at', '>=', $from->utc())->orderBy('starts_at')->first();
        if (! $e) return null;
        $local = CarbonImmutable::instance($e->starts_at)->setTimezone('America/Chicago');

        return ['weeks' => (int) floor($from->diffInDays($local->startOfDay()) / 7) + 1, 'label' => $local->locale('es')->translatedFormat('D j M')];
    }

    /** "Reemplazar": the best free person for that seat who is not already on the team (said yes first). The owner still saves. */
    public function replace(int $eventId, string $seat, bool $costume = false): void
    {
        $e = $this->events()->firstWhere('id', $eventId);
        if (! $e || ! isset($this->picks[$eventId])) return;
        $p = $this->picks[$eventId];
        $current = (int) ($costume ? ($p['costumes'][$seat]['wearer_id'] ?? 0) : ($p['people'][$seat] ?? 0));
        $team = array_map('intval', array_filter([...array_values($p['people']), ...array_column($p['costumes'], 'wearer_id')]));
        $skill = $costume ? 'performer' : (collect(TeamSlots::for($e)['people'])->firstWhere('key', $seat)['skill'] ?? null);
        if (! $skill) return;
        $unit = $costume && ($p['costumes'][$seat]['unit_id'] ?? null) ? Resource::find($p['costumes'][$seat]['unit_id']) : null;
        $compat = \Illuminate\Support\Facades\DB::table('costume_compatibilities')->get();
        $pick = Resource::where('kind', 'staff')->where('status', 'active')->orderBy('name')->get()
            ->filter(fn ($r) => $r->id !== $current && ! in_array($r->id, $team, true) && in_array($skill, $r->skills ?? [], true) && TeamSlots::free($r, $e)
                && \App\Models\StaffAvailability::for($r->id, $e->starts_at) !== 'no'
                && (! $unit || \App\Support\CostumeWearers::allows($unit, $r, \App\Models\Character::find($unit->character_id), $compat)))
            ->sortBy(fn ($r) => [\App\Models\StaffAvailability::for($r->id, $e->starts_at) === 'yes' ? 0 : 1, $r->name])->first();
        if (! $pick) { Notification::make()->warning()->title('No hay a quién poner')->body('Nadie más con esa habilidad está libre ese día.')->send(); return; }
        if ($costume) $this->picks[$eventId]['costumes'][$seat]['wearer_id'] = $pick->id; else $this->picks[$eventId]['people'][$seat] = $pick->id;
        if ((int) ($p['driver'] ?? 0) === $current) $this->picks[$eventId]['driver'] = null;
        Notification::make()->success()->title('Propuesto: '.$pick->name)->body('Toca «Guardar» para confirmarlo. Le aparece en su enlace.')->send();
    }

    /** View model: day groups → parties → seats with only the people who fit that seat and are free then. */
    public function board(): array
    {
        $staff = Resource::where('kind', 'staff')->where('status', 'active')->orderBy('name')->get();
        $avail = \App\Models\StaffAvailability::whereDate('week_start', CarbonImmutable::parse($this->friday)->subDays(4)->toDateString())->get()->keyBy('resource_id');
        $answerOf = fn (int $id, Event $e) => $avail[$id]->days[\App\Models\StaffAvailability::dayKey($e->starts_at->setTimezone('America/Chicago'))] ?? null;
        // Who said yes first, then no answer, then who said no (still choosable: the owner decides).
        $label = fn (Resource $r, Event $e) => $r->name.match ($answerOf($r->id, $e)) { 'yes' => ' · puede', 'no' => ' · dijo que no', default => '' };
        $rank = fn (Resource $r, Event $e) => match ($answerOf($r->id, $e)) { 'yes' => 0, 'no' => 2, default => 1 };
        $costumes = Resource::where('kind', 'costume')->where('status', 'active')->orderBy('name')->get();
        $days = [];
        foreach ($this->events() as $e) {
            $need = TeamSlots::for($e); $p = $this->picks[$e->id] ?? ['people' => [], 'costumes' => [], 'driver' => null];
            $free = $staff->filter(fn ($s) => TeamSlots::free($s, $e))->values();
            $sorted = $free->sortBy(fn ($r) => [$rank($r, $e), $r->name])->values();
            $answers = \App\Models\StaffJobAnswer::where('event_id', $e->id)->get()->filter(fn ($a) => $a->event_starts_at->equalTo($e->starts_at))->keyBy('resource_id');
            $status = fn ($id) => $id ? (['yes' => ['ok', 'Confirmó'], 'no' => ['bad', 'No puede'.(($n = $answers[(int) $id]->note ?? null) ? ': '.$n : '')]][$answers[(int) $id]->answer ?? ''] ?? ['wait', 'Sin confirmar']) : null;
            $seats = collect($need['people'])->map(fn ($s) => [...$s, 'options' => $sorted->filter(fn ($r) => in_array($s['skill'], $r->skills ?? [], true))->mapWithKeys(fn ($r) => [$r->id => $label($r, $e)])->all(),
                'status' => $status($p['people'][$s['key']] ?? null)])->all();
            $cos = collect($need['costumes'])->map(fn ($s) => [...$s, 'units' => $costumes->filter(fn ($u) => (int) $u->character_id === $s['character_id'] && TeamSlots::free($u, $e))->pluck('name', 'id')->all(),
                'wearers' => $sorted->filter(fn ($r) => in_array('performer', $r->skills ?? [], true))->mapWithKeys(fn ($r) => [$r->id => $label($r, $e)])->all(),
                'status' => $status($p['costumes'][$s['key']]['wearer_id'] ?? null)])->all();
            $team = array_filter([...array_values($p['people']), ...array_column($p['costumes'], 'wearer_id')]);
            $drivers = $staff->whereIn('id', $team)->filter(fn ($r) => MatchEventResources::driver($r))->pluck('name', 'id')->all();
            // Says what is missing, not just how many ("Recreador/a 1, Kuromi").
            $labels = collect($need['people'])->keyBy('key')->map(fn ($s) => $s['label'])->all() + collect($need['costumes'])->keyBy('key')->map(fn ($s) => $s['label'])->all();
            $gaps = [...array_map(fn ($k) => $labels[$k] ?? $k, array_keys(array_filter($p['people'], fn ($v) => ! $v))),
                ...array_map(fn ($k) => $labels[$k] ?? $k, array_keys(array_filter($p['costumes'], fn ($c) => ! $c['wearer_id'] || ! $c['unit_id']))),
                ...(($p['driver'] ?? null) || count($drivers) === 1 || $team === [] ? [] : ['Conductor']),
                // Owner 2026-11-04: Waoo — one of the team paints faces (any seat, the animador included).
                ...(in_array('face_painter', $need['team_skills'] ?? [], true) && $team !== [] && ! $staff->whereIn('id', $team)->contains(fn ($r) => in_array('face_painter', $r->skills ?? [], true)) ? ['Alguien que pinte caritas'] : [])];
            $missing = count($gaps);
            $local = $e->starts_at->setTimezone('America/Chicago');
            $days[$local->toDateString()]['label'] ??= ucfirst($local->locale('es')->translatedFormat('l j \d\e F'));
            $days[$local->toDateString()]['avail'] ??= ['yes' => $staff->filter(fn ($r) => $answerOf($r->id, $e) === 'yes')->count(),
                'no' => $staff->filter(fn ($r) => $answerOf($r->id, $e) === 'no')->count(), 'none' => $staff->filter(fn ($r) => $answerOf($r->id, $e) === null)->count()];
            $days[$local->toDateString()]['parties'][] = ['id' => $e->id, 'title' => $e->title, 'time' => $local->locale('es')->translatedFormat('g:i a').' – '.$e->ends_at->setTimezone('America/Chicago')->locale('es')->translatedFormat('g:i a'),
                'place' => Str::limit((string) (\App\Support\ClientAddress::forEvent($e)[0] ?? ''), 48), 'packages' => $e->services->map(fn ($s) => \App\Models\ServiceVariant::whereKey($s->service_variant_id)->value('name'))->filter()->implode(' + '),
                'seats' => $seats, 'costumes' => $cos, 'drivers' => $drivers, 'auto_driver' => count($drivers) === 1 ? reset($drivers) : null, 'missing' => $missing, 'gaps' => $gaps, 'team_empty' => $team === [],
                'url' => \App\Filament\Resources\Events\EventResource::getUrl('view', ['record' => $e])];
        }
        ksort($days);
        $f = CarbonImmutable::parse($this->friday);
        return ['days' => array_values($days), 'range' => $f->subDays(4)->locale('es')->translatedFormat('j M').' – '.$f->addDays(2)->locale('es')->translatedFormat('j M'),
            'count' => array_sum(array_map(fn ($d) => count($d['parties']), $days))];
    }
}
