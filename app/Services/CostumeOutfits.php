<?php

namespace App\Services;

use App\Exceptions\RecordWriteRejected;
use App\Models\Resource;
use App\Services\Records\RecordInput;
use Illuminate\Support\Collection;

/** Physical costume units and operational wardrobe choices are separate. */
final class CostumeOutfits
{
    public static function rules(): array
    {
        return [
            'costume_outfits' => 'sometimes|array|list|max:100',
            'costume_outfits.*' => 'array:resource_id,outfit,expected_character_version',
            'costume_outfits.*.resource_id' => 'required|integer|min:1|distinct',
            'costume_outfits.*.outfit' => 'required|string|min:1|max:60',
            'costume_outfits.*.expected_character_version' => 'required|integer|min:1',
        ];
    }

    public static function normalize(array $choices): array
    {
        $data = RecordInput::validate(['costume_outfits' => $choices], self::rules());
        return collect($data['costume_outfits'])->map(fn ($c) => [
            'resource_id' => (int) $c['resource_id'], 'outfit' => $c['outfit'],
            'expected_character_version' => (int) $c['expected_character_version'],
        ])->sortBy('resource_id')->values()->all();
    }

    public static function validate(array $choices, Collection $resources, Collection $characters): void
    {
        foreach ($choices as $id => $choice) {
            $unit = $resources->firstWhere('id', $id);
            RecordWriteRejected::unless($unit && $unit->kind === 'costume' && $unit->outfit === null, 422, 'Elige vestuario solo para una unidad genérica seleccionada.');
            $character = $characters->get((int) $unit->character_id);
            RecordWriteRejected::unless($character && $character->version === $choice['expected_character_version'], 409, 'El personaje cambió. Revisa sus vestuarios.');
            RecordWriteRejected::unless(in_array($choice['outfit'], $character->outfits ?? [], true), 409, 'El vestuario ya no está disponible para este personaje.');
        }
    }

    public static function fits(array $slot, Resource $unit, Collection $characters, array $choices): bool
    {
        if ($unit->kind !== 'costume' || (isset($slot['character_id']) && (int) $slot['character_id'] !== (int) $unit->character_id)) return false;
        $requested = $slot['outfit'] ?? null;
        if ($requested === null) return true;
        if ($unit->outfit !== null) return $unit->outfit === $requested;
        $chosen = $choices[$unit->id]['outfit'] ?? null;
        return ($chosen === null || $chosen === $requested)
            && in_array($requested, $characters->get((int) $unit->character_id)?->outfits ?? [], true);
    }

    public static function snapshot(Resource $unit, ?array $slot, Collection $characters, array $choices): array
    {
        return [
            'resource_id' => $unit->id, 'character_id' => $unit->character_id,
            'character_name' => $characters->get((int) $unit->character_id)?->name,
            'outfit' => $unit->outfit ?? ($choices[$unit->id]['outfit'] ?? ($slot['outfit'] ?? null)),
            'event_service_id' => $slot['event_service_id'] ?? null,
            'requirement_index' => $slot['requirement_index'] ?? null, 'occurrence' => $slot['occurrence'] ?? null,
        ];
    }
}
