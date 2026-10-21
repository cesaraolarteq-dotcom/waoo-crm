<?php

namespace App\Services\Records;

use App\Exceptions\RecordWriteRejected;
use App\Models\Character;
use App\Models\ServiceVariant;
use Illuminate\Validation\ValidationException;

final class CharacterRecipe
{
    public static function validateCatalog(ServiceVariant $variant): void
    {
        $quantity = (int) ($variant->character_quantity ?? 0);
        RecordWriteRejected::when($variant->status === 'active' && empty($variant->requirements) && $quantity === 0, 422, 'Define requisitos antes de activar el servicio.');
        $options = $variant->character_options ?? [];
        if ($variant->status === 'active' && $quantity > 0 && $options === []) {
            throw ValidationException::withMessages(['character_options' => 'Define los personajes permitidos y sus requisitos antes de activar el servicio.']);
        }
        $ids = [];
        foreach ($options as $index => $option) {
            $path = "character_options.{$index}";
            if (in_array((int) $option['character_id'], $ids, true)) {
                throw ValidationException::withMessages(["{$path}.character_id" => 'No repitas un personaje.']);
            }
            $ids[] = (int) $option['character_id'];
            $hasStaff = false;
            $hasPerformer = false;
            $hasCostume = false;
            foreach ($option['requirements'] as $r => $requirement) {
                $field = match ($requirement['kind']) {
                    'staff' => 'skill', 'costume' => 'character_id', 'equipment' => 'equipment_kind', default => null,
                };
                if ($field && ! ($requirement[$field] ?? null)) {
                    throw ValidationException::withMessages(["{$path}.requirements.{$r}.{$field}" => 'Completa el requisito de este personaje.']);
                }
                if ($requirement['kind'] === 'costume') {
                    $hasCostume = true;
                    if ((int) $requirement['character_id'] !== (int) $option['character_id']) {
                        throw ValidationException::withMessages(["{$path}.requirements.{$r}.character_id" => 'El traje debe corresponder al personaje elegido.']);
                    }
                }
                $hasStaff = $hasStaff || $requirement['kind'] === 'staff';
                $hasPerformer = $hasPerformer || ($requirement['kind'] === 'staff' && $requirement['skill'] === 'performer');
            }
            if (! $hasStaff || ($hasCostume && ! $hasPerformer)) {
                throw ValidationException::withMessages(["{$path}.requirements" => 'Define el personal del personaje; un traje requiere el rol performer.']);
            }
        }
    }

    /** @return array{requirements: array, characters: array, ready: bool, extra: int} */
    public static function compile(ServiceVariant $variant, array $characters, string $path): array
    {
        self::validateCatalog($variant);
        $included = (int) ($variant->character_quantity ?? 0);
        $options = collect($variant->character_options ?? [])->keyBy('character_id');
        $requirements = $variant->requirements ?? [];
        $rows = [];
        $seen = [];
        $total = 0;
        foreach ($characters as $index => $selection) {
            $id = (int) $selection['character_id'];
            $quantity = (int) $selection['quantity'];
            $outfit = $selection['outfit'] ?? null;
            $pair = json_encode([$id, $outfit], JSON_THROW_ON_ERROR);
            $character = Character::whereKey($id)->where('active', true)->lockForUpdate()->first();
            if ($included === 0 || ! $options->has($id) || ! $character || in_array($pair, $seen, true)) {
                throw ValidationException::withMessages(["{$path}.{$index}.character_id" => 'Selecciona un personaje permitido, activo y sin repetir.']);
            }
            if ($outfit !== null && (!in_array($outfit, $character->outfits ?? [], true) || !collect($options[$id]['requirements'])->contains('kind', 'costume'))) {
                throw ValidationException::withMessages(["{$path}.{$index}.outfit" => 'La versión ya no existe o falta definir su requisito de traje.']);
            }
            $seen[] = $pair;
            $total += $quantity;
            foreach ($options[$id]['requirements'] as $requirement) {
                $requirements[] = [...$requirement, ...($requirement['kind'] === 'costume' && $outfit !== null ? ['outfit' => $outfit] : []), 'quantity' => (int) $requirement['quantity'] * $quantity];
            }
            $rows[] = ['character_id' => $id, 'quantity' => $quantity, 'character_name_snapshot' => $character->name, ...($outfit !== null ? ['outfit' => $outfit] : []), ...(isset($selection['note']) ? ['note' => $selection['note']] : [])];
        }
        // Owner 2026-10-21: more characters than included is an add-on (priced per extra character), never an error.
        return ['requirements' => $requirements, 'characters' => $rows,
            'ready' => $variant->status === 'active' && $total >= $included, 'extra' => max(0, $total - $included)];
    }
}
