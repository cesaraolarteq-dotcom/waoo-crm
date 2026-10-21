<?php

namespace App\Observers;

use App\Models\Event;
use App\Services\Google\GoogleCalendar;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

/** After a party is saved or removed (and only once the transaction committed), mirror it in Google Calendar. Never blocks a save. */
final class EventGoogleSync implements ShouldHandleEventsAfterCommit
{
    public function saved(Event $event): void
    {
        if (! GoogleCalendar::connection()) return;
        try { app(GoogleCalendar::class)->push($event->fresh() ?? $event); }
        catch (\Throwable $e) { Log::warning('google_calendar.push_failed', ['event_id' => $event->id]); }
    }

    public function deleted(Event $event): void
    {
        if (! GoogleCalendar::connection()) return;
        try { app(GoogleCalendar::class)->remove($event->id); }
        catch (\Throwable $e) { Log::warning('google_calendar.remove_failed', ['event_id' => $event->id]); }
    }
}
