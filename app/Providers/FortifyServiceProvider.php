<?php
namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Services\RecoveryCodeDelivery;
use Illuminate\Support\Facades\{Event, Hash, RateLimiter};
use Laravel\Fortify\Events\{RecoveryCodesGenerated, TwoFactorAuthenticationDisabled, TwoFactorAuthenticationEnabled};
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
        // A new recovery-code generation may be shown once more; re-confirming 2FA is not a new generation.
        Event::listen([TwoFactorAuthenticationEnabled::class, RecoveryCodesGenerated::class, TwoFactorAuthenticationDisabled::class], fn ($event) => RecoveryCodeDelivery::reset($event->user));
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(mb_strtolower((string) $request->email).'|'.$request->ip()));
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id').'|'.$request->ip()));
        RateLimiter::for('admin-write', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?? $request->ip()));
    }
}
