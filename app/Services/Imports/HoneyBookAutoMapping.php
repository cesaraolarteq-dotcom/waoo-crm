<?php
namespace App\Services\Imports;

use App\Filament\Resources\Events\EventResource;
use App\Models\{Character, PriceVersion, ServiceVariant};

/**
 * Owner 2026-11-04: "el sistema debería comparar y traer todo de forma automática".
 * Guesses the CRM package and characters of a HoneyBook row: a remembered equivalence first, then the
 * name aliases (config/honeybook_aliases.php) and exact character names. Returns the form selection,
 * or null with a reason when a person must choose. Never touches money.
 */
final class HoneyBookAutoMapping
{
    /** @return array{selection: ?array, reason: ?string} */
    public static function guess(array $source): array
    {
        if ($m = HoneyBookMappingKey::suggestion($source)) {
            $sel = $m->selection ?? [];
            return ['selection' => ['price_version_ids' => array_map(fn ($s) => (int) $s['price_version_id'], $sel),
                'characters' => collect($sel)->flatMap(fn ($s) => collect($s['characters'] ?? [])->map(fn ($c) => ['price_version_id' => (int) $s['price_version_id'], ...$c]))->values()->all()], 'reason' => null];
        }
        $prices = [];
        foreach (preg_split('/\s*[;\n]\s*/u', (string) ($source['services'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $service) {
            $name = config('honeybook_aliases.packages')[self::norm(preg_replace('/\(.*\)/u', '', $service))] ?? null;
            $variant = $name ? ServiceVariant::where('name', $name)->where('status', 'active')->first() : null;
            $price = $variant ? PriceVersion::where('service_variant_id', $variant->id)->orderByDesc('id')->first() : null;
            if (! $price) return ['selection' => null, 'reason' => 'No reconozco el paquete «'.trim($service).'»: elígelo tú.'];
            $prices[] = $price->id;
        }
        if ($prices === []) return ['selection' => null, 'reason' => 'La fila no dice el paquete: elígelo tú.'];
        $configurable = array_keys(EventResource::configurablePrices($prices));
        $names = preg_split('/\s*,\s*/u', trim((string) ($source['characters'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $characters = [];
        if ($names !== [] && $configurable === []) return ['selection' => ['price_version_ids' => $prices, 'characters' => []],
            'reason' => 'HoneyBook trae personajes que este paquete no permite elegir. Revisa el paquete y los personajes.'];
        if (count($configurable) > 1) return ['selection' => ['price_version_ids' => $prices, 'characters' => []],
            'reason' => 'Hay varios paquetes con personajes. Elige los personajes de cada paquete.'];
        if ($configurable !== []) {
            if ($names === []) return ['selection' => ['price_version_ids' => $prices, 'characters' => []], 'reason' => 'HoneyBook no dice qué personaje: elígelo tú.'];
            $for = $configurable[0];
            $allowed = ServiceVariant::find(PriceVersion::whereKey($for)->value('service_variant_id'))?->character_options ?? [];
            $allowedIds = collect($allowed)->pluck('character_id')->map(fn ($id) => (int) $id)->all();
            foreach ($names as $n) {
                $c = self::character($n, $allowedIds);
                if (! $c) return ['selection' => ['price_version_ids' => $prices, 'characters' => $characters], 'reason' => 'No encuentro el personaje «'.$n.'» en este paquete: elígelo tú.'];
                $characters[] = ['price_version_id' => $for, 'character_id' => $c->id, 'quantity' => 1];
            }
        }

        return ['selection' => ['price_version_ids' => $prices, 'characters' => $characters], 'reason' => null];
    }

    /** Exact name, else the only allowed character whose name starts with it ("Minnie" → "Minnie Mouse"). */
    private static function character(string $name, array $allowedIds): ?Character
    {
        $pool = Character::whereIn('id', $allowedIds)->where('active', true)->get();
        $n = self::norm($name);
        $exact = $pool->first(fn ($c) => self::norm($c->name) === $n);
        if ($exact) return $exact;
        $prefix = $pool->filter(fn ($c) => str_starts_with(self::norm($c->name), $n.' '));

        return $prefix->count() === 1 ? $prefix->first() : null;
    }

    private static function norm(string $v): string
    {
        $v = \Illuminate\Support\Str::ascii(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v)), 'UTF-8'));

        return trim($v);
    }
}
