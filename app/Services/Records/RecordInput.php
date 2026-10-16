<?php

namespace App\Services\Records;

use Illuminate\Support\Facades\Validator;

final class RecordInput
{
    public static function validate(array $input, array $rules): array
    {
        return Validator::make(self::normalize($input), $rules, [
            'required' => 'Este campo es obligatorio.',
            'present' => 'Este campo debe estar presente.',
            'required_if' => 'Este campo es obligatorio para el tipo seleccionado.',
            'string' => 'Este campo debe ser texto.',
            'integer' => 'Este campo debe ser un número entero.',
            'boolean' => 'Este campo debe ser verdadero o falso.',
            'array' => 'Este campo debe contener una lista con los campos permitidos.',
            'email' => 'Introduce un correo electrónico válido.',
            'in' => 'Selecciona una opción válida.',
            'exists' => 'La ficha seleccionada no existe.',
            'unique' => 'Este valor ya está en uso.',
            'distinct' => 'No repitas este valor.',
            'date' => 'Introduce una fecha válida.',
            'after' => 'La fecha final debe ser posterior a la inicial.',
            'regex' => 'Introduce un formato válido.',
            'min.numeric' => 'El valor mínimo es :min.',
            'max.numeric' => 'El valor máximo es :max.',
            'min.string' => 'Escribe al menos :min caracteres.',
            'max.string' => 'Escribe como máximo :max caracteres.',
            'max.array' => 'Selecciona como máximo :max elementos.',
        ])->validate();
    }

    private static function normalize(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = self::normalize($value);
            } elseif (is_string($value)) {
                $input[$key] = trim($value) === '' ? null : trim($value);
            }
        }

        return $input;
    }
}
