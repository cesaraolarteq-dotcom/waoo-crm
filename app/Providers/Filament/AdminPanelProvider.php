<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Middleware\RequireOwnerAdmin;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Admin shell (Phase 1 step 4). No Filament login: sign-in and 2FA are Fortify only (docs/AUTH-01.md).
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Waoo CRM')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('img/favicon.png'))
            ->colors([
                'primary' => Color::hex('#122F94'),
                'danger' => Color::hex('#B4233A'),
                'warning' => Color::hex('#F9C930'),
                'success' => Color::hex('#11734A'),
                'info' => Color::hex('#1E4DD8'),
                'gray' => Color::Slate,
            ])
            ->font('Bricolage Grotesque')
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Día a día'),
                NavigationGroup::make('Vender'),
                NavigationGroup::make('Operación'),
            ])
            ->pages([Dashboard::class])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.head'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Persistent: the same gate runs on every Livewire update, not only on page loads.
            ->authMiddleware([
                AuthenticateAdmin::class,
                RequireOwnerAdmin::class,
            ], isPersistent: true);
    }
}
