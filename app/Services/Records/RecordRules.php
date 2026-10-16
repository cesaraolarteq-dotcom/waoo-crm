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
            'characters' => ['name' => 'required|string|max:255', 'active' => 'required|boolean'],
            'resources' => ['code' => ['required', 'string', 'max:60', Rule::unique('resources')->ignore($id)], 'name' => 'required|string|max:255', 'kind' => ['required', Rule::in(['staff', 'costume', 'equipment', 'vehicle'])], 'status' => ['required', Rule::in(['active', 'maintenance', 'inactive'])], 'character_id' => 'nullable|required_if:kind,costume|integer|exists:characters,id', 'location' => 'nullable|string|max:255', 'skills' => 'present|array|max:30', 'skills.*' => 'string|max:60|distinct', 'equipment_kind' => 'nullable|required_if:kind,equipment|string|max:60', 'seats' => 'nullable|required_if:kind,vehicle|integer|min:1|max:100'],
            'variants' => ['name' => 'required|string|max:255', 'status' => ['required', Rule::in(['draft', 'active', 'retired'])], 'duration_minutes' => 'required|integer|min:1|max:1440', 'requirements' => 'present|array|max:30', 'requirements.*' => 'array:kind,quantity,skill,character_id,equipment_kind', 'requirements.*.kind' => ['required', Rule::in(['staff', 'costume', 'equipment', 'vehicle'])], 'requirements.*.quantity' => 'required|integer|min:1|max:20', 'requirements.*.skill' => 'nullable|string|max:60', 'requirements.*.character_id' => 'nullable|integer|exists:characters,id', 'requirements.*.equipment_kind' => 'nullable|string|max:60'],
        };
        if ($id && in_array($entity, ['resources', 'variants'])) {
            $rules['expected_version'] = 'required|integer|min:1';
        }
        $data = RecordInput::validate($input, $rules);
        if ($entity === 'variants' && $data['status'] === 'active') {
            RecordWriteRejected::when(empty($data['requirements']), 422, 'Define requisitos antes de activar el servicio.');
        }
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
