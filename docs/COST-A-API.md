# COST-A first slice: owner-entered event costs and contribution margin

2026-10-16. API contract before implementation. Backend sandbox foundation; not automatic payroll, complete COST-A, net profit, or provider access. No rates, preparation pay, transport formula or allocation invented. Owner inputs explicit USD cents and confirms coverage. Catalog/package rate versions and automatic staff-hour/transport formulas remain pending owner data and a later increment.

## Write contract

`App\Services\Costs\RecordCostSnapshot::execute(User $actor, int $eventId, array $input, string $key): EventCostSnapshot`.

POST `/admin/api/events/{event}/cost-snapshots`, UUID Idempotency-Key. Fresh active owner + confirmed 2FA; finance sandbox flag/environment required (production denied). Body:

- `kind`: estimate|actual; independent numbered histories.
- `expected_event_version` >=1, `financial_revision_id` current for this event, `expected_revision` >=0 (0 first cost snapshot of this kind).
- `coverage_confirmed`: boolean. Only the owner can assert all direct costs are represented; this never defaults true. Zero costs require an explicit empty list plus coverage confirmation and note.
- `note`: required 10–2000 chars, records assumptions/correction/zero-cost explanation.
- `lines`: required array, 0–100 entries, each `label` (1–120), `category`: staff_labor|staff_transport|materials|card_processing|other, `amount_cents`: present nullable integer0..100000000, optional nullable `event_service_id` (must belong to this financial snapshot). Null means unknown, explicit0 is known zero. Staff preparation and shared transport costs may be separate labelled owner-entered lines. No calculated hourly rates or default wages here.

Event row lock, optimistic event/financial/cost-kind versions and canonical UUID idempotency protect two editors. Snapshot + audit + idempotency commit atomically; same-key retries return the original immutable record after later edits. Changes/corrections create a new snapshot rather than altering history. These are cost reports, never payroll disbursements, ledger receipts, capacity changes or contract edits.

## Read contract

`App\Services\Costs\EventMarginSummary::forEvent(User $actor, int $eventId): array`.

GET `/admin/api/events/{event}/costs`: fresh-owner authorization even for direct service callers; same sandbox gate. Returns current financial revision/event version, `agreement_current`, revenue_cents (subtotal + customer travel fee + customer fees; excludes taxes), separately labelled actual cash received and customer balance, and latest `estimate`/`actual` summaries (null if absent). Each includes snapshot id/revision/note, lines, known_cost_cents, unknown_line_count, coverage_confirmed, complete, current, total_cost_cents and contribution_margin_cents. Total/margin are null unless coverage is confirmed, no amount is unknown, financial revision is current and event version still matches. Known costs alone never imply full costs. No net-profit or per-package margin claim; unallocated shared costs are not divided automatically. Client-paid travel fee is revenue; internal staff transport is a cost.

GET `/admin/api/events/{event}/cost-snapshots`: bounded page/per_page1..50, optional kind estimate|actual; immutable historical versions including stale ones. Event-wide cost snapshots must not be added to portal/contract/API projections. Owner-only costs can contain staff pay information. Invoice/portal totals and financial snapshots remain unchanged.

## Claude UI handoff

After current portal/payment work, add owner-only cost estimate/actual forms and margin summary. Show unknown costs and missing coverage clearly; use null margin as pending, never $0 profit. Preserve displayed versions and a stable key per attempt; after409 reload rather than silently replacing another edit. Show estimate vs actual separately, stale snapshots/history and owner note. No customer/staff-facing cost screens or automated pay/rates in this increment.
