<?php

namespace App\Console\Commands;

use App\Services\Google\GoogleCalendar;
use Illuminate\Console\Command;

/** Re-send upcoming parties to Google Calendar (safe to run anytime: unchanged parties are skipped). */
final class SyncGoogleCalendar extends Command
{
    protected $signature = 'waoo:google-calendar-sync';
    protected $description = 'Envía a Google Calendar las fiestas desde hoy (omite las que no cambiaron).';

    public function handle(GoogleCalendar $google): int
    {
        if (! GoogleCalendar::connection()) { $this->warn('Google Calendar no está conectado.'); return self::SUCCESS; }
        [$ok, $failed] = $google->syncUpcoming();
        $this->info("Al día: {$ok}. Con error: {$failed}.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
