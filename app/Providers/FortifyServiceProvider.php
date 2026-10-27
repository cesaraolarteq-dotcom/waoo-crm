<?php
namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Actions\Fortify\{LockedDisableTwoFactorAuthentication, LockedEnableTwoFactorAuthentication, LockedGenerateNewRecoveryCodes};
use Illuminate\Support\Facades\{Hash, RateLimiter};
use Laravel\Fortify\Actions\{DisableTwoFactorAuthentication, EnableTwoFactorAuthentication, GenerateNewRecoveryCodes};
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Code-changing 2FA actions share the delivery lock (App\Services\RecoveryCodeDelivery). A new generation may be
        // shown once; re-confirming 2FA or using a recovery code is not a new generation.
        $this->app->bind(GenerateNewRecoveryCodes::class, LockedGenerateNewRecoveryCodes::class);
        $this->app->bind(EnableTwoFactorAuthentication::class, LockedEnableTwoFactorAuthentication::class);
        $this->app->bind(DisableTwoFactorAuthentication::class, LockedDisableTwoFactorAuthentication::class);
    }

    public function boot(): void
    {
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', mb_strtolower(trim((string) $request->email)))->first();
            return $user && $user->active && $user->role === 'owner_admin' && Hash::check((string) $request->password, $user->password) ? $user : null;
        });
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(mb_strtolower((string) $request->email).'|'.$request->ip()));
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id').'|'.$request->ip()));
        // Reading the owner's API must not consume the allowance needed to save a record.
        // Keep both caps bounded at the existing value until measured usage calls for a higher read cap.
        RateLimiter::for('admin-read', fn (Request $request) => Limit::perMinute(60)->by('read:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('admin-write', fn (Request $request) => Limit::perMinute(60)->by('write:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('admin-api', function (Request $request) {
            $name = in_array($request->method(), ['GET', 'HEAD'], true) ? 'admin-read' : 'admin-write';

            return RateLimiter::limiter($name)($request);
        });
    }
}
