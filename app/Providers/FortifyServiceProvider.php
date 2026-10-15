<?php
namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash, RateLimiter};
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', mb_strtolower(trim((string) $request->email)))->first();
            return $user && $user->active && $user->role === 'owner_admin' && Hash::check((string) $request->password, $user->password) ? $user : null;
        });
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(mb_strtolower((string) $request->email).'|'.$request->ip()));
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id').'|'.$request->ip()));
        RateLimiter::for('admin-write', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?? $request->ip()));
    }
}
