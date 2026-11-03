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
        $from = CarbonImmutable::parse($this->friday, 'America/Chicago')->startOfDay()->utc();
        return Event::where('status', 'capacity_reserved')->where('starts_at', '>=', $from)->where('starts_at', '<', $from->addDays(3))
            ->orderBy('starts_at')->with('services')->get();
    }

    private function load(): void
    {
        $this->picks = []; $this->versions = []; $this->keys = [];
        foreach ($this->events() as $e) {
            $c = TeamSlots::current($e);
            $need = TeamSlots::for($e);
            $this->picks[$e->id] = ['people' => collect($need['people'])->mapWithKeys(fn ($s) => [$s['key'] => $c['people'][$s['key']] ?? null])->all(),
                'costumes' => collect($need['costumes'])->mapWithKeys(fn ($s) => [$s['key'] => ['unit_id' => $c['costumes'][$s['key']]['unit_id'] ?? null, 'wearer_id' => $c['costumes'][$s['key']]['wearer_id'] ?? null]])->all(),
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
                app(AssignEventTeam::class)->execute(Filament::auth()->user(), $e->id, ['expected_plan_version' => $this->versions[$e->id] ?? 0,
                    'people' => $this->picks[$e->id]['people'], 'costumes' => $this->picks[$e->id]['costumes'], 'driver' => $this->picks[$e->id]['driver'] ?: null], $this->keys[$e->id]);
                $ok++;
            } catch (RecordWriteRejected|ValidationException|AuthorizationException $x) {
                $errors[] = $e->title.': '.($x instanceof ValidationException ? collect($x->errors())->flatten()->first() : $x->getMessage());
            }
        }
        $this->load();
        if ($errors) Notification::make()->warning()->title($ok.' guardadas · '.count($errors).' con problema')->body(implode("\n", $errors))->persistent()->send();
        else Notification::make()->success()->title('Equipo guardado')->body($ok.' fiestas. Cada persona ya lo ve en su enlace del staff.')->send();
    }

    /** View model: day groups → parties → seats with only the people who fit that seat and are free then. */
    public function board(): array
    {
        $staff = Resource::where('kind', 'staff')->where('status', 'active')->orderBy('name')->get();
        $costumes = Resource::where('kind', 'costume')->where('status', 'active')->orderBy('name')->get();
        $days = [];
        foreach ($this->events() as $e) {
            $need = TeamSlots::for($e); $p = $this->picks[$e->id] ?? ['people' => [], 'costumes' => [], 'driver' => null];
            $free = $staff->filter(fn ($s) => TeamSlots::free($s, $e))->values();
            $seats = collect($need['people'])->map(fn ($s) => [...$s, 'options' => $free->filter(fn ($r) => in_array($s['skill'], $r->skills ?? [], true))->pluck('name', 'id')->all()])->all();
            $cos = collect($need['costumes'])->map(fn ($s) => [...$s, 'units' => $costumes->filter(fn ($u) => (int) $u->character_id === $s['character_id'] && TeamSlots::free($u, $e))->pluck('name', 'id')->all(),
                'wearers' => $free->filter(fn ($r) => in_array('performer', $r->skills ?? [], true))->pluck('name', 'id')->all()])->all();
            $team = array_filter([...array_values($p['people']), ...array_column($p['costumes'], 'wearer_id')]);
            $drivers = $staff->whereIn('id', $team)->filter(fn ($r) => MatchEventResources::driver($r))->pluck('name', 'id')->all();
            $missing = count(array_filter($p['people'], fn ($v) => ! $v)) + count(array_filter($p['costumes'], fn ($c) => ! $c['wearer_id'] || ! $c['unit_id']))
                + (($p['driver'] ?? null) || count($drivers) === 1 || $team === [] ? 0 : 1);
            $local = $e->starts_at->setTimezone('America/Chicago');
            $days[$local->toDateString()]['label'] ??= ucfirst($local->locale('es')->translatedFormat('l j \d\e F'));
            $days[$local->toDateString()]['parties'][] = ['id' => $e->id, 'title' => $e->title, 'time' => $local->format('g:i a').' – '.$e->ends_at->setTimezone('America/Chicago')->format('g:i a'),
                'place' => Str::limit((string) $e->address, 48), 'packages' => $e->services->map(fn ($s) => \App\Models\ServiceVariant::whereKey($s->service_variant_id)->value('name'))->filter()->implode(' + '),
                'seats' => $seats, 'costumes' => $cos, 'drivers' => $drivers, 'auto_driver' => count($drivers) === 1 ? reset($drivers) : null, 'missing' => $missing,
                'url' => \App\Filament\Resources\Events\EventResource::getUrl('view', ['record' => $e])];
        }
        ksort($days);
        $f = CarbonImmutable::parse($this->friday);
        return ['days' => array_values($days), 'range' => $f->locale('es')->translatedFormat('j M').' – '.$f->addDays(2)->locale('es')->translatedFormat('j M'),
            'count' => array_sum(array_map(fn ($d) => count($d['parties']), $days))];
    }
}
