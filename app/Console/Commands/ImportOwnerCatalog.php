<?php
namespace App\Console\Commands;

use App\Models\{Character, PriceVersion, Resource, ServiceVariant, User};
use App\Services\Records\{AddPriceVersion, SaveCharacter, SaveResource, SaveVariant};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

// Copies the owner's catalog page export (packages, characters, staff) into the CRM through the audited record services.
// Only what the owner typed: prices, durations, who goes, descriptions. Nothing is invented: equipment units, rates and
// extra-character pricing are reported, not modelled. Re-runnable: matches by name/code and only writes what changed.
class ImportOwnerCatalog extends Command
{
    protected $signature = 'waoo:import-catalog {file : JSON export of the owner catalog} {--actor= : owner email} {--dry-run}';
    protected $description = 'Importar el catálogo del dueño (paquetes, personajes, trajes, staff) con los servicios auditados.';

    /** Catalog crew role → staff skill. "character" is the performer of a character option; "driver" is a staff skill too. */
    public const SKILLS = ['entertainer' => 'entertainer', 'host' => 'host', 'dj' => 'dj', 'face_painter' => 'face_painter',
        'operator' => 'operator', 'spa' => 'spa', 'driver' => 'driver', 'character' => 'performer',
        'character_head' => 'performer', 'character_wig' => 'performer_wig'];

    /** Catalog equipment keys (owner's catalog page). Declared units become equipment requirements; anything else keeps the package in draft. */
    public const EQUIPMENT = ['speaker', 'mic', 'bubbles', 'robot_tall', 'robot_low', 'robot_suit', 'smoke', 'foam', 'photo_booth', 'vanity', 'disco_ball', 'sparkler', 'table', 'face_kit'];

    /** Owner: the same 2 robot suits work as tall or low, so both packages compete for one pool of suits. */
    public const EQUIPMENT_ALIAS = ['robot_tall' => 'robot_suit', 'robot_low' => 'robot_suit'];

    /** Staff card extra skills (catalog page) → staff skills; a wig performer can also play head characters. */
    public const EXTRA_SKILLS = ['glitter' => 'glitter', 'hair' => 'hair', 'balloons' => 'balloons', 'games' => 'games', 'show' => 'show',
        'stilts' => 'stilts', 'bilingual' => 'bilingual', 'car' => 'car', 'first_aid' => 'first_aid'];

    private array $report = [];

    public function handle(): int
    {
        $data = json_decode((string) @file_get_contents($this->argument('file')), true);
        if (! is_array($data) || ! isset($data['packages'])) { $this->error('Archivo inválido: falta "packages".'); return self::FAILURE; }
        $actor = User::where('email', mb_strtolower((string) $this->option('actor')))->first();
        if (! $actor) { $this->error('Indica --actor con el correo de una dueña/dueño existente.'); return self::FAILURE; }
        $dry = (bool) $this->option('dry-run');

        $characters = $this->characters($actor, $data['characters'] ?? [], $dry);
        $this->inventory($actor, $data['inventory'] ?? [], $dry);
        $this->staff($actor, $data['staff'] ?? [], $dry);
        foreach (collect($data['packages'])->sortBy(fn ($p) => [$p['group'] ?? '', $p['order'] ?? 99]) as $key => $p) {
            try {
                $this->package($actor, (string) $key, $p, $characters, $dry);
            } catch (Throwable $e) {
                $this->report[] = ['Paquete', $p['name'] ?? $key, 'NO importado', $this->reason($e)];
            }
        }
        $this->table(['Tipo', 'Nombre', 'Resultado', 'Detalle'], $this->report);
        $this->line($dry ? 'Prueba en seco: no se guardó nada.' : 'Listo. Revisa cada paquete en Catálogo antes de usarlo con clientas.');

        return self::SUCCESS;
    }

    /** "Clásico 1, Disneyland 1, Carreras 1" → ['Clásico' => 1, 'Disneyland' => 1, 'Carreras' => 1]. A name without a number counts as 1. */
    public static function parseOutfits(?string $text): array
    {
        $out = [];
        foreach (preg_split('/[,;\n]+/', (string) $text) as $part) {
            if (! preg_match('/^\s*(.*?)\s*(\d+)?\s*$/u', $part, $m) || trim($m[1]) === '') continue;
            $name = mb_substr(trim($m[1]), 0, 60);
            $out[$name] = ($out[$name] ?? 0) + max(1, (int) ($m[2] ?? 1));
        }

        return $out;
    }

    /** @return array<string, array{id:int|null, wig:bool}> by lower-case name */
    private function characters(User $actor, array $rows, bool $dry): array
    {
        $out = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') continue;
            $versions = self::parseOutfits($row['outfits'] ?? null);
            $versionNames = array_map('strval', array_keys($versions));
            $character = Character::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
            $existed = (bool) $character;
            if (! $dry) {
                if (! $character) {
                    $character = app(SaveCharacter::class)->execute($actor, ['name' => $name, 'active' => true, 'outfits' => $versions ? $versionNames : null]);
                } elseif ($versions && array_values((array) $character->outfits) !== $versionNames) {
                    // Keep existing versions (a costume may still use them) and add the owner's new ones.
                    $merged = array_values(array_unique([...((array) $character->outfits), ...$versionNames]));
                    $character = app(SaveCharacter::class)->execute($actor, ['name' => $character->name, 'active' => (bool) $character->active, 'outfits' => $merged, 'expected_version' => $character->version], $character->id);
                }
            }
            $this->report[] = ['Personaje', $name, $existed ? 'ya existía' : ($dry ? 'se crearía' : 'creado'), (! empty($row['wig']) ? 'peluca' : 'cabeza').($versions ? ' · versiones: '.implode(', ', array_keys($versions)) : '')];
            // Units: one per version when the owner listed versions; otherwise generic units from "costumes".
            $plan = $versions ?: [null => max(0, (int) ($row['costumes'] ?? 0))];
            $made = 0;
            foreach ($plan as $version => $count) {
                for ($n = 1; $n <= $count; $n++) {
                    $code = 'TRAJE-'.Str::upper(Str::slug($name)).($version !== null && $version !== '' ? '-'.Str::upper(Str::slug((string) $version)) : '').'-'.$n;
                    if (Resource::where('code', $code)->exists()) continue;
                    if (! $dry && $character) app(SaveResource::class)->execute($actor, ['code' => $code, 'name' => "Traje {$name}".($version !== null && $version !== '' ? " {$version}" : '')." {$n}", 'kind' => 'costume', 'status' => 'active',
                        'character_id' => $character->id, 'outfit' => $version !== null && $version !== '' ? (string) $version : null, 'location' => null, 'skills' => [], 'equipment_kind' => null, 'seats' => null]);
                    $made++;
                }
            }
            if ($made) $this->report[] = ['Trajes', $name, ($dry ? 'se crearían ' : 'creados ').$made, $versions ? 'por versión' : ''];
            $out[mb_strtolower($name)] = ['id' => $character?->id, 'wig' => ! empty($row['wig'])];
        }

        return $out;
    }

    /** Owner's equipment counts → one equipment unit per item (EQ-<KIND>-n). Only adds missing units; never removes. */
    private function inventory(User $actor, array $rows, bool $dry): void
    {
        foreach ($rows as $row) {
            $kind = self::EQUIPMENT_ALIAS[$row['kind'] ?? ''] ?? ($row['kind'] ?? '');
            if (! in_array($kind, self::EQUIPMENT, true)) { $this->report[] = ['Equipo', (string) ($row['label'] ?? $kind), 'no importado', 'tipo desconocido']; continue; }
            $made = 0;
            for ($n = 1; $n <= max(0, (int) ($row['count'] ?? 0)); $n++) {
                $code = 'EQ-'.Str::upper(str_replace('_', '-', $kind)).'-'.$n;
                if (Resource::where('code', $code)->exists()) continue;
                if (! $dry) app(SaveResource::class)->execute($actor, ['code' => $code, 'name' => trim((string) ($row['label'] ?? $kind)).' '.$n, 'kind' => 'equipment', 'status' => 'active',
                    'character_id' => null, 'location' => null, 'skills' => [], 'equipment_kind' => $kind, 'seats' => null]);
                $made++;
            }
            $this->report[] = ['Equipo', (string) ($row['label'] ?? $kind), ($dry ? 'se crearían ' : 'creados ').$made, $made ? '' : 'ya existían'];
        }
    }

    private function staff(User $actor, array $rows, bool $dry): void
    {
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') continue;
            $code = 'STAFF-'.Str::upper(Str::slug($name));
            $skills = collect($row['roles'] ?? [])->map(fn ($r) => self::SKILLS[$r] ?? null)
                ->merge(in_array('character_wig', $row['roles'] ?? [], true) ? ['performer'] : [])
                ->merge(collect($row['skills'] ?? [])->map(fn ($k) => self::EXTRA_SKILLS[$k] ?? null))
                ->filter()->unique()->values()->all();
            if (($row['active'] ?? true) === false) { $this->report[] = ['Staff', $name, 'no importado', 'inactiva/o en el catálogo']; continue; }
            if (Resource::where('code', $code)->exists()) { $this->report[] = ['Staff', $name, 'ya existía', '']; continue; }
            if (! $dry) app(SaveResource::class)->execute($actor, ['code' => $code, 'name' => $name, 'kind' => 'staff', 'status' => 'active',
                'character_id' => null, 'location' => null, 'skills' => $skills, 'equipment_kind' => null, 'seats' => null]);
            $this->report[] = ['Staff', $name, $dry ? 'se crearía' : 'creado', implode(', ', $skills).(! empty($row['rates']) || isset($row['rate']) ? ' · tarifas: se usan en Costos, no se importan aún' : '')];
        }
    }

    private function package(User $actor, string $key, array $p, array $characters, bool $dry): void
    {
        $name = trim((string) ($p['name'] ?? ''));
        $crew = collect($p['crew'] ?? [])->map(fn ($n) => max(0, (int) $n));
        $isHoraLoca = ($p['group'] ?? '') === 'Hora loca';
        // A robot/smoke show is equipment + an operator, not a CRM character: its "character" person is staff.
        $included = $isHoraLoca ? 0 : max(0, (int) ($p['characters_included'] ?? 0));
        $base = $crew->except($included > 0 ? ['character'] : [])->all();
        if ($isHoraLoca && ($base['character'] ?? 0) > 0) { $base['operator'] = ($base['operator'] ?? 0) + $base['character']; unset($base['character']); }
        $headSharesHost = $included > 0 && ($crew['character'] ?? 0) === 0;
        // Owner rule: when a host dresses as a head character, one host slot becomes the performer; a wig character needs one extra person.
        if ($headSharesHost && ($base['host'] ?? 0) > 0) $base['host']--;
        // Owner rule (Waoo): face painting is done by one of the same team, so one slot needs that skill.
        if (str_contains(mb_strtolower($p['gear_other'] ?? '').json_encode($p['gear'] ?? []), 'face_kit') && ($base['face_painter'] ?? 0) === 0 && ($base['host'] ?? 0) > 0) {
            $base['host']--; $base['face_painter'] = 1;
        }
        $unknownCrew = array_keys(array_filter($base, fn ($count, $role) => $count > 0 && ! isset(self::SKILLS[$role]), ARRAY_FILTER_USE_BOTH));
        $requirements = [];
        foreach ($base as $role => $count) {
            if ($count > 0 && isset(self::SKILLS[$role])) $requirements[] = ['kind' => 'staff', 'quantity' => $count, 'skill' => self::SKILLS[$role]];
        }
        $unknownGear = [];
        foreach ((array) ($p['gear'] ?? []) as $kind => $count) {
            if ((int) $count <= 0) continue;
            if (in_array($kind, self::EQUIPMENT, true)) $requirements[] = ['kind' => 'equipment', 'quantity' => (int) $count, 'equipment_kind' => self::EQUIPMENT_ALIAS[$kind] ?? $kind];
            else $unknownGear[] = $kind;
        }
        $options = [];
        if ($included > 0) {
            foreach ($characters as $c) {
                if (! $c['id']) continue;
                $req = [['kind' => 'costume', 'quantity' => 1, 'character_id' => $c['id']], ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']];
                if ($headSharesHost && $c['wig']) $req[] = ['kind' => 'staff', 'quantity' => 1, 'skill' => 'host'];
                $options[] = ['character_id' => $c['id'], 'requirements' => $req];
            }
        }
        $description = trim((string) ($p['description'] ?? ''));
        $english = preg_match_all('/\b(the|and|with|for|our|each)\b/i', $description) >= 3;
        $input = ['name' => $name, 'duration_minutes' => (int) ($p['duration_min'] ?? 0), 'requirements' => $requirements,
            'character_quantity' => $included, 'character_options' => $options === [] ? null : $options,
            'long_description' => $english ? $description : null,
            // Active only when everything declared is represented (staff + equipment); otherwise the owner finishes it in the CRM.
            'status' => ! empty($p['ready']) && ($requirements !== [] || $options !== []) && $unknownGear === [] && $unknownCrew === [] && ($included === 0 || $options !== []) ? 'active' : 'draft'];
        $variant = ServiceVariant::where('name', $name)->first();
        $notes = array_filter([
            $included > 0 && $options === [] ? 'sin personajes cargados' : null,
            $unknownCrew ? 'personal desconocido (queda en borrador): '.implode(', ', $unknownCrew) : null,
            $unknownGear ? 'equipo desconocido (queda en borrador): '.implode(', ', $unknownGear) : null,
            array_filter((array) ($p['gear'] ?? [])) ? 'reserva equipo: '.implode(', ', array_keys(array_filter((array) $p['gear']))).' (carga las unidades en Recursos)' : null,
            ! empty($p['extra_character_price']) ? 'personaje extra $'.$p['extra_character_price'].' (no modelado)' : null,
            $english ? null : 'descripción en español: escribe la del contrato en inglés',
            $isHoraLoca && ($p['characters_included'] ?? 0) > 0 ? 'robot tratado como equipo + operador' : null,
        ]);
        if ($dry) { $this->report[] = ['Paquete', $name, ($variant ? 'se actualizaría' : 'se crearía').' ('.$input['status'].')', implode(' · ', $notes)]; return; }

        $existed = (bool) $variant;
        $cents = isset($p['price']) && $p['price'] !== null ? (int) round($p['price'] * 100) : null;
        $unchanged = $existed && self::sameVariant($variant, $input);
        // Variant + price together: a bad price never leaves a half-imported package.
        $variant = DB::transaction(function () use ($actor, $variant, $input, $cents, $unchanged) {
            $saved = $unchanged ? $variant : ($variant
                ? app(SaveVariant::class)->execute($actor, [...$input, 'expected_version' => $variant->version], $variant->id)
                : app(SaveVariant::class)->execute($actor, $input));
            $latest = PriceVersion::where('service_variant_id', $saved->id)->orderByDesc('id')->value('amount_cents');
            if ($cents !== null && $latest !== $cents) app(AddPriceVersion::class)->execute($actor, $saved->id, ['amount_cents' => $cents, 'currency' => 'USD']);

            return $saved;
        });
        $this->report[] = ['Paquete', $name, ($unchanged ? 'sin cambios' : ($existed ? 'actualizado' : 'creado')).' ('.$variant->status.')'.($cents === null ? ' sin precio' : ' $'.($cents / 100)), implode(' · ', $notes)];
    }

    /** Stored variant already equals what the import would write (normalized): no save, no new version or audit. */
    private static function sameVariant(ServiceVariant $v, array $input): bool
    {
        $norm = fn ($x) => json_encode($x === null ? null : (is_array($x) ? self::sortDeep($x) : $x));
        foreach (['name', 'duration_minutes', 'requirements', 'character_quantity', 'character_options', 'long_description', 'status'] as $k) {
            if ($norm($v->{$k} ?? null) !== $norm($input[$k] ?? null)) return false;
        }

        return true;
    }

    private static function sortDeep(array $a): array
    {
        foreach ($a as $k => $v) if (is_array($v)) $a[$k] = self::sortDeep($v);
        if (array_keys($a) !== range(0, count($a) - 1)) ksort($a);

        return $a;
    }

    private function reason(Throwable $e): string
    {
        if ($e instanceof \Illuminate\Validation\ValidationException) return collect($e->errors())->flatten()->first();

        return $e->getMessage();
    }
}
