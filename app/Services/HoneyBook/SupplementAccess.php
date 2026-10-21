<?php
namespace App\Services\HoneyBook;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
final class SupplementAccess
{
    public static function check(?User $actor): void {
        if (! config('waoo.sandbox_honeybook_supplement') || ! app()->environment(['local', 'testing', 'staging']) ||
            ! $actor?->active || $actor->role !== 'owner_admin' || ! $actor->two_factor_secret || ! $actor->two_factor_confirmed_at) {
            throw new AuthorizationException('Acceso no disponible.');
        }
    }
}
