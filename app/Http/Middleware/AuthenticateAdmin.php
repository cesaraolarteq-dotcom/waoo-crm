<?php
namespace App\Http\Middleware;

use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Exceptions\HttpResponseException;

// Filament's panel authentication, plus: an owner who signed in but has not confirmed 2FA is sent
// to enrollment instead of a bare 403. Runs inside the auth stage (Laravel sorts it before plain middleware).
final class AuthenticateAdmin extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $user = Filament::auth()->user();
        if ($user?->active && $user->role === 'owner_admin' && ! $user->two_factor_confirmed_at) {
            throw new HttpResponseException(redirect()->route('security.two-factor'));
        }
        parent::authenticate($request, $guards);
    }
}
