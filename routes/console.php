<?php
// Operational commands live in app/Console/Commands. No scheduled messages in Phase 1.
// Owner hosting runs `schedule:run` every minute (hPanel cron on the real CRM).

use Illuminate\Support\Facades\Schedule;

// Codex audit 2026-11-17 A2: card refunds Stripe left pending are re-read from Stripe if their webhook never arrives.
Schedule::command('waoo:refunds:recheck')->everyThirtyMinutes()->withoutOverlapping();

// Owner 2026-11-23 "Mensajes automáticos": instant messages every minute; planned reminder emails every five minutes.
Schedule::command('waoo:messages')->everyMinute()->withoutOverlapping();
Schedule::command('waoo:mail-due')->everyFiveMinutes()->withoutOverlapping()->when(fn () => config('waoo.sandbox_mail') === true);

// Owner 2026-11-23: clients who book are saved in his Google Contacts (iPhone).
Schedule::command('waoo:contacts')->everyTenMinutes()->withoutOverlapping();
// «Un año después» (owner 2026-11-25): codes every Monday, emails and day-6 reminders every day. Both only send with the
// campaign switches on (WAOO_ANNIVERSARY_MAIL_ENABLED + WAOO_ANNIVERSARY_CLIENTS); issuing alone never sends anything.
Schedule::command('waoo:anniversary-issue')->weeklyOn(1, '10:00')->timezone('America/Chicago')->withoutOverlapping()->when(fn () => config('waoo.sandbox_anniversary') === true);
Schedule::command('waoo:anniversary-send')->dailyAt('10:30')->timezone('America/Chicago')->withoutOverlapping()->when(fn () => config('waoo.sandbox_anniversary') === true);
