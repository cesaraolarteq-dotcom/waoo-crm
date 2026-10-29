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
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class,
            fn () => \App\Services\Mail\MailDeliveryGate::enabled() ? null : false);
        \App\Models\Event::observe(\App\Observers\EventGoogleSync::class); // owner 2026-10-21: Google Calendar mirror
        // Owner 2026-10-28: a website request with the SMS box checked records that number's consent to texts.
        \App\Models\Lead::created(fn (\App\Models\Lead $lead) => app(\App\Services\Sms\RecordLeadSmsConsent::class)->execute($lead));
    }
}
