# REPORT-A — read-only operational report, first slice

Contract written before implementation. Owner-only, finance sandbox only. No cron, mail, external requests or writes.

`App\Services\Reports\OperationalReport::execute(User $actor, array $input): array`

`GET /admin/api/reports/operations?period=week&anchor=2026-10-12`

Both fields required. `period` is `week` or `month`; `anchor` is a real `YYYY-MM-DD` date in 2000–2100. Week means Monday 00:00 through next Monday, month means first through next first, **America/Chicago**, converted to UTC with an exclusive upper bound. An anchor anywhere in the period selects that entire period; this does not default to “last completed week”. UI must label the dates. All amounts integer USD cents; response private/no-store.

Response: `environment=test`, `timezone`, `generated_at`, `period` (`kind`, `starts_on`, `ends_before`, `starts_at_utc`, `ends_before_utc`), `cash` (`received_cents`, `refunded_cents`, `net_received_cents`, `basis=ledger.received_at`), `scheduled` (`basis=events.starts_at`, `event_count`, `events`, `pending_balance_cents`, `pending_balance_unknown_events`, `estimate`, `actual`). Each event exposes only ID/status/start, latest financial ID, balance, latest cost revision/current/complete flags, known direct costs and nullable direct/staff costs. No client contacts, bank references, cost-line names or internal notes.

Each estimate/actual aggregate has `known_direct_cost_cents`, `pending_events`, nullable `direct_cost_cents`, nullable `staff_cost_cents`. Staff = staff_labor + staff_transport; no hourly-rate inference. Any missing, incomplete or stale latest snapshot makes the aggregate total **null**, never zero. Empty event set is a known zero. Currentness uses the same event/financial rules as COST-A. `pending_balance_cents` is the known subtotal; display the unknown-event count beside it. Balances are as of generation, not historical period-end balances. Cash is period received-date cash and includes refunds separately, regardless of event date. Drafts are included in the scheduled-event list and their status is explicit: do not call them confirmed bookings or sales.

This slice deliberately does not subtract period cash from event-period costs: those are different cohorts. It does not claim net profit, staff payment dates, packages sold, booked counts, the upcoming weekend forecast, scheduled sending or email delivery. Those require the next REPORT-A slices and MAIL/JOBS review. Claude can build a read-only admin view against this exact contract now; label scheduled costs and cash separately. All report data must remain owner-only.

Implementation note: queries load the selected period's events and associated immutable finance/cost revisions in batches; events are locked by ascending ID during collection to coordinate with the existing writers. This is appropriate for the current ~40-events/month target, not an unbounded export API. Cash remains a separate query/cohort; this is not a historical accounting close or an atomic snapshot of the entire company's ledger. Period cash currently filters USD/test only. Known direct-cost subtotals may include stale snapshots and are informational; show their current flags and never label them complete totals.

Additive weekend projection is now documented separately in `docs/REPORT-A-WEEKEND-API.md`; the existing `/reports/operations` response and period definitions are unchanged.
