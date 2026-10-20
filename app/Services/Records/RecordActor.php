<?php

namespace App\Services\Records;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class RecordActor
{
    public static function authorize(User $actor, bool $current = false): User
    {
        $query = User::whereKey($actor->getKey());
        $row = $actor->exists ? ($current ? $query->sharedLock()->first() : $query->first()) : null;
        if (! $row?->active || $row->role !== 'owner_admin') {
            throw new AuthorizationException('Acceso exclusivo de administración.');
        }
        if (! $row->two_factor_secret || ! $row->two_factor_confirmed_at) {
            throw new AuthorizationException('Activa y confirma la autenticación de dos factores.');
        }

        return $row;
    }
}
