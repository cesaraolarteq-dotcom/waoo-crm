<?php

namespace App\Services\Records;

use App\Exceptions\RecordWriteRejected;
use Illuminate\Validation\Rule;

final class RecordRules
{
    public static function validate(string $entity, array $input, ?int $id): array
    {
        $rules = match ($entity) {
            'customers' => ['name' => 'required|string|max:255', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:40', 'locale' => ['required', Rule::in(['es', 'en'])]],
            'characters' => ['group' => 'sometimes|nullable|string|max:120', 'name' => 'required|string|max:255', 'active' => 'required|boolean', 'outfits' => 'sometimes|nullable|array|list|max:20', 'outfits.*' => 'required|string|min:1|max:60|distinct:ignore_case'],
            'resources' => ['code' => ['required', 'string', 'max:60', Rule::unique('resources')->ignore($id)], 'name' => 'required|string|max:255', 'kind' => ['required', Rule::in(['staff', 'costume', 'equipment', 'vehicle'])], 'status' => ['required', Rule::in(['active', 'maintenance', 'inactive'])], 'character_id' => 'nullable|required_if:kind,costume|integer|exists:characters,id', 'outfit' => 'sometimes|nullable|prohibited_unless:kind,costume|string|max:60', 'location' => 'nullable|string|max:255', 'skills' => 'present|array|max:30', 'skills.*' => 'string|max:60|distinct', 'equipment_kind' => 'nullable|required_if:kind,equipment|string|max:60', 'seats' => 'nullable|required_if:kind,vehicle|integer|min:1|max:100'],
            'variants' => ['name' => 'required|string|max:255', 'long_description' => 'sometimes|nullable|string|max:10000', 'status' => ['required', Rule::in(['draft', 'active', 'retired'])], 'duration_minutes' => 'required|integer|min:1|max:1440', 'requirements' => 'present|array|max:30', 'requirements.*' => 'array:kind,quantity,skill,character_id,equipment_kind', 'requirements.*.kind' => ['required', Rule::in(['staff', 'costume', 'equipment', 'vehicle'])], 'requirements.*.quantity' => 'required|integer|min:1|max:20', 'requirements.*.skill' => 'nullable|string|max:60', 'requirements.*.character_id' => 'nullable|integer|exists:characters,id', 'requirements.*.equipment_kind' => 'nullable|string|max:60'],
        };
        if ($id) {
            $rules['expected_version'] = 'required|integer|min:1';
        }
        if ($entity === 'variants') {
            $rules += [
                'character_quantity' => 'sometimes|integer|min:0|max:20',
                'character_options' => 'sometimes|nullable|array|max:100',
                'character_options.*' => 'array:character_id,requirements',
                'character_options.*.character_id' => 'required|integer|exists:characters,id',
                'character_options.*.requirements' => 'required|array|min:1|max:10',
                'character_options.*.requirements.*' => 'array:kind,quantity,skill,character_id,equipment_kind',
                'character_options.*.requirements.*.kind' => ['required', Rule::in(['staff', 'costume', 'equipment', 'vehicle'])],
                'character_options.*.requirements.*.quantity' => 'required|integer|min:1|max:20',
                'character_options.*.requirements.*.skill' => 'nullable|string|max:60',
                'character_options.*.requirements.*.character_id' => 'nullable|integer|exists:characters,id',
                'character_options.*.requirements.*.equipment_kind' => 'nullable|string|max:60',
            ];
        }
        $data = RecordInput::validate($input, $rules);
        if ($entity === 'variants') {
            foreach ($data['requirements'] as $req) {
                $field = match ($req['kind']) {
                    'staff' => 'skill',
                    'costume' => 'character_id',
                    'equipment' => 'equipment_kind',
                    default => null,
                };
                RecordWriteRejected::when($field && !($req[$field] ?? null), 422, 'Completa habilidad, personaje o tipo de equipo por requisito.');
            }
        }
        return $data;
    }
}
