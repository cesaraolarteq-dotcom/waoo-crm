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
        $this->app->bind(\App\Services\Mail\MailTransport::class, \App\Services\Mail\DisabledMailTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Models\Event::observe(\App\Observers\EventGoogleSync::class); // owner 2026-10-21: Google Calendar mirror
    }
}
