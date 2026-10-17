# REPORT-A — current/upcoming weekend forecast

Additive contract before code; separate from the existing receipt-date report. No messages, scheduler, rates or database writes.

`App\Services\Reports\WeekendForecast::execute(User $actor, array $input): array`

`GET /admin/api/reports/weekend?anchor=2026-11-02`

Required real `YYYY-MM-DD` anchor, same 2000–2100 range as OperationalReport. Select the Saturday 00:00 through Monday 00:00 of the anchor's Monday-based week in **America/Chicago**. Monday–Friday anchors select the upcoming weekend; Saturday/Sunday select the ongoing weekend. Show both returned dates. Friday is excluded explicitly; this is a reporting window, never an availability policy.

Only `capacity_reserved` events whose **start** falls within that window count as confirmed commitments. Drafts are excluded and counted separately as `draft_event_count`; other statuses are not projected as commitments. Costs are owner-entered snapshots, never assumed wages. Reuses OperationalReport's owner/2FA/sandbox authorization, event locks, current financial/cost revisions and current balance semantics.

Response: environment=test, timezone, generated_at, window (`starts_on`, `ends_before`, `starts_at_utc`, `ends_before_utc`), basis=confirmed_events.starts_at, event_count, draft_event_count, events (same privacy-limited rows as OperationalReport, chronological), pending_balance_cents (known subtotal), pending_balance_unknown_events, and separate estimate/actual aggregates with known_direct_cost_cents, pending_events, nullable direct_cost_cents and staff_cost_cents. Any missing/incomplete/stale cost snapshot makes its aggregate total null. Empty confirmed set is known zero. Balances are current as of generation, not historical balances. This does not report actual staff payments or cash available, and it does not subtract unrelated period cash from weekend costs.

Claude can show “Próximo fin de semana” before Saturday and “Este fin de semana” on Saturday/Sunday, date range, confirmed count, draft count separately, balance plus unknown count, and staff estimate vs actual in separate cards. Missing/stale costs must say “Pendiente”. No client contact details, cost-line labels/notes or bank references. Owner-only private/no-store response. Upcoming weekend delivery by mail and package sales remain later slices.
