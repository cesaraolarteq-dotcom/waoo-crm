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
                // Explicit Waoo navy scale: Color::hex() generates a lighter palette, which made primary buttons pale blue.
                'primary' => [
                    50 => '#EEF2FF', 100 => '#DCE3FB', 200 => '#B9C6F5', 300 => '#8DA2EC', 400 => '#5C77DD',
                    500 => '#3352C4', 600 => '#1F3FB0', 700 => '#122F94', 800 => '#0F2778', 900 => '#0E1F5C', 950 => '#0A1540',
                ],
                'danger' => Color::hex('#B4233A'),
                'warning' => Color::hex('#F9C930'),
                'success' => Color::hex('#11734A'),
                'info' => Color::hex('#1E4DD8'),
                'gray' => Color::Slate,
            ])
            ->font('Bricolage Grotesque')
            // Record services own their transactions (docs/RECORD-SERVICES.md); Filament must not wrap them.
            ->databaseTransactions(false)
            ->sidebarCollapsibleOnDesktop()
            // Owner 2026-10-29: a place to get new 2-step recovery codes.
            ->userMenuItems([
                'security' => \Filament\Actions\Action::make('security')->label('Seguridad')
                    ->icon('heroicon-o-shield-check')->url(fn () => route('security')),
            ])
            // Owner 2026-11-02: six sections in the menu; each section's pages become tabs (App\Filament\Support\Sections).
            ->navigation(fn (\Filament\Navigation\NavigationBuilder $builder) => \App\Filament\Support\Sections::navigation($builder))
            ->pages([\App\Filament\Pages\HoneyBookInformation::class, \App\Filament\Pages\HoneyBookArchiveReview::class, \App\Filament\Pages\ImportHistory::class, Dashboard::class, \App\Filament\Pages\Calendar::class, \App\Filament\Pages\WeekendPlan::class, \App\Filament\Pages\Reports::class, \App\Filament\Pages\QuickBooking::class, \App\Filament\Pages\Leads::class, \App\Filament\Pages\Quotes::class, \App\Filament\Pages\QuoteDetail::class, \App\Filament\Pages\ContractTemplates::class, \App\Filament\Pages\EmailTemplates::class, \App\Filament\Pages\ImportHoneyBook::class, \App\Filament\Pages\StaffLinks::class, \App\Filament\Pages\StaffPlanPreview::class, \App\Filament\Pages\ChangePreview::class, \App\Filament\Pages\CutoverPreview::class, \App\Filament\Pages\HoneyBookCutover::class])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.head'))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => auth()->check() ? view('filament.bottom-bar') : '')
            ->renderHook(PanelsRenderHook::PAGE_START, fn () => view('filament.section-tabs', ['rows' => \App\Filament\Support\Sections::rows()]))
            ->renderHook(PanelsRenderHook::PAGE_HEADER_WIDGETS_BEFORE, fn () => ($e = \App\Filament\Support\Sections::eventPage()) ? view('filament.party-bar', ['chips' => \App\Filament\Support\PartyHeader::chips($e), 'tabs' => \App\Filament\Support\Sections::eventTabs() ?? []]) : '')
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
