<?php

namespace App\Filament\Pages;

use App\Models\{Character, Resource};
use App\Services\Records\{LinkCostumeCompatibility, UnlinkCostumeCompatibility};
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-11-03 (70+ costumes × 20+ people is too much to mark): the normal rule stays (head → any Personaje,
 * wig → only Personaje con peluca, App\Support\CostumeWearers); here the owner marks only the costumes that just some
 * people can wear. One list, one form: pick the costume, tick the people, save.
 */
class CostumeExceptions extends Page
{
    protected static ?string $slug = 'trajes-especiales';
    protected static ?string $title = 'Trajes especiales';
    protected static bool $shouldRegisterNavigation = false; // reached through the Equipo tabs
    protected string $view = 'filament.pages.costume-exceptions';

    public ?int $costumeId = null;
    /** @var list<int> */
    public array $people = [];

    public function updatedCostumeId(): void
    {
        $this->people = $this->costumeId ? $this->linked($this->costumeId) : [];
    }

    public function pick(int $costumeId): void
    {
        $this->costumeId = $costumeId;
        $this->updatedCostumeId();
    }

    public function save(): void
    {
        $costume = Resource::where('kind', 'costume')->find($this->costumeId);
        if (! $costume) { Notification::make()->warning()->title('Elige un traje')->send(); return; }
        $allowed = array_keys($this->performers());
        $want = array_values(array_intersect(array_map('intval', $this->people), $allowed));
        $have = $this->linked($costume->id);
        $user = Filament::auth()->user();
        $failed = [];
        foreach (array_diff($want, $have) as $id) app(LinkCostumeCompatibility::class)->execute($user, $id, $costume->id);
        foreach (array_diff($have, $want) as $id) {
            try {
                app(UnlinkCostumeCompatibility::class)->execute($user, $id, $costume->id, 'Quitado en Trajes especiales.');
            } catch (\App\Exceptions\RecordWriteRejected $e) {
                $failed[] = Resource::find($id)?->name;
            }
        }
        $this->people = $this->linked($costume->id);
        if ($failed !== []) {
            Notification::make()->warning()->title('Casi listo')->body(implode(', ', $failed).' tiene una fiesta con este traje. Cámbialo en esa fiesta y vuelve a quitarlo.')->persistent()->send();
            return;
        }
        Notification::make()->success()->title($this->people === [] ? 'Vuelve a la regla normal' : 'Guardado')
            ->body($this->people === [] ? $costume->name.' lo puede usar cualquiera que cumpla la regla.' : 'Solo estas personas pueden usar '.$costume->name.'.')->send();
    }

    public function clear(): void
    {
        $this->people = [];
        $this->save();
    }

    /** Costumes that have an exception, with who can wear them. @return list<array{id:int,name:string,people:list<string>}> */
    public function exceptions(): array
    {
        $rows = DB::table('costume_compatibilities')->join('resources as s', 's.id', '=', 'costume_compatibilities.staff_id')
            ->join('resources as c', 'c.id', '=', 'costume_compatibilities.costume_id')
            ->orderBy('c.name')->orderBy('s.name')->get(['c.id as costume_id', 'c.name as costume', 's.name as person']);

        return $rows->groupBy('costume_id')->map(fn ($g, $id) => ['id' => (int) $id, 'name' => $g->first()->costume, 'people' => $g->pluck('person')->all()])->values()->all();
    }

    /** @return array<int,string> id => "name · character" */
    public function costumes(): array
    {
        $chars = Character::pluck('name', 'id');

        return Resource::where('kind', 'costume')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'character_id'])
            ->mapWithKeys(fn ($r) => [$r->id => $r->name.(($n = $chars[$r->character_id] ?? null) && ! str_contains(mb_strtolower($r->name), mb_strtolower($n)) ? ' · '.$n : '')])->all();
    }

    /** Active staff who can play characters. @return array<int,string> */
    public function performers(): array
    {
        return Resource::where('kind', 'staff')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'skills'])
            ->filter(fn ($r) => array_intersect((array) $r->skills, ['performer', 'performer_wig']) !== [])->pluck('name', 'id')->all();
    }

    /** The normal rule for the chosen costume, said plainly. */
    public function rule(): ?string
    {
        $costume = $this->costumeId ? Resource::find($this->costumeId) : null;
        if (! $costume) return null;

        return match (Character::find($costume->character_id)?->costume_style) {
            'head' => 'Regla normal: es de cabeza, lo puede usar cualquier Personaje.',
            'wig' => 'Regla normal: es de peluca, solo lo usa quien tiene "Personaje con peluca".',
            default => 'Este personaje no está marcado como cabeza o peluca. Márcalo en Personajes, o elige aquí quién lo puede usar.',
        };
    }

    /** @return list<int> */
    private function linked(int $costumeId): array
    {
        return DB::table('costume_compatibilities')->where('costume_id', $costumeId)->pluck('staff_id')->map(fn ($id) => (int) $id)->all();
    }
}
