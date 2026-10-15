<?php

// Minimal Spanish messages for the rules used in Phase 1 forms; extend as forms grow.
return [
    'required' => 'Falta :attribute.',
    'email' => ':attribute no es un correo válido.',
    'string' => ':attribute debe ser texto.',
    'numeric' => ':attribute debe ser un número.',
    'integer' => ':attribute debe ser un número entero.',
    'date' => ':attribute no es una fecha válida.',
    'max' => ['string' => ':attribute no puede tener más de :max caracteres.', 'numeric' => ':attribute no puede ser mayor que :max.'],
    'min' => ['string' => ':attribute debe tener al menos :min caracteres.', 'numeric' => ':attribute debe ser al menos :min.'],
    'unique' => 'Ya existe un registro con ese :attribute.',
    'attributes' => [
        'email' => 'el correo',
        'password' => 'la contraseña',
        'code' => 'el código',
        'recovery_code' => 'el código de recuperación',
        'name' => 'el nombre',
    ],
];
