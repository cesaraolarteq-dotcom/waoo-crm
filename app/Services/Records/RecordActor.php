<?php

namespace App\Services\Records;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class RecordActor
{
    public static function authorize(User $actor): User
    {
        $current = $actor->exists ? User::find($actor->getKey()) : null;
        if (! $current?->active || $current->role !== 'owner_admin') {
            throw new AuthorizationException('Acceso exclusivo de administración.');
        }
        if (! $current->two_factor_secret || ! $current->two_factor_confirmed_at) {
            throw new AuthorizationException('Activa y confirma la autenticación de dos factores.');
        }

        return $current;
    }
}
