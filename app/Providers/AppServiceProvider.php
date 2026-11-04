<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Services\Mail\MailTransport::class, function ($app) {
            $resend = $app->make(\App\Services\Mail\ResendMailTransport::class);
            return $resend->configured() ? $resend : new \App\Services\Mail\DisabledMailTransport();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Audit 2026-11-04: Filament lists only 50 options before typing; the catalog has 70 characters, 78 costumes and 96 time slots.
        \Filament\Forms\Components\Select::configureUsing(fn (\Filament\Forms\Components\Select $select) => $select->optionsLimit(500));
        // Audit 2026-11-04: lists opened at 10 rows (25 staff, 78 costumes, 70 characters meant paging all the time).
        \Filament\Tables\Table::configureUsing(fn (\Filament\Tables\Table $table) => $table->paginated([25, 50, 100])->defaultPaginationPageOption(50));
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class,
            fn () => \App\Services\Mail\MailDeliveryGate::enabled() ? null : false);
        \App\Models\Event::observe(\App\Observers\EventGoogleSync::class); // owner 2026-10-21: Google Calendar mirror
        // Owner 2026-10-28: a website request with the SMS box checked records that number's consent to texts.
        \App\Models\Lead::created(fn (\App\Models\Lead $lead) => app(\App\Services\Sms\RecordLeadSmsConsent::class)->execute($lead));
    }
}
