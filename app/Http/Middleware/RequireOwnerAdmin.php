<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class RequireOwnerAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->active && $request->user()?->role === 'owner_admin', 403, 'Acceso exclusivo de administración.');
        abort_unless($request->user()->two_factor_secret && $request->user()->two_factor_confirmed_at, 403, 'Activa y confirma la autenticación de dos factores.');
        return $next($request);
    }
}
