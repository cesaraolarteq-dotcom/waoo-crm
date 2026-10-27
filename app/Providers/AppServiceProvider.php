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
    }
}
