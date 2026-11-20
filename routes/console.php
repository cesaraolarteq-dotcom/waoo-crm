<?php
// Operational commands live in app/Console/Commands. No scheduled messages in Phase 1.
// Owner hosting runs `schedule:run` every minute (hPanel cron on the real CRM).

use Illuminate\Support\Facades\Schedule;

// Codex audit 2026-11-17 A2: card refunds Stripe left pending are re-read from Stripe if their webhook never arrives.
Schedule::command('waoo:refunds:recheck')->everyThirtyMinutes()->withoutOverlapping();
