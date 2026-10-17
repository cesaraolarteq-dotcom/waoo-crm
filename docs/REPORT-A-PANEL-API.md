# REPORT-A consolidated owner panel and email preview — v1

Owner authorized Phase6 on2026-10-17, docs/plans/PHASE-6.md (#75). API before integration/new read code. Base is the #73/#74 stack with all9 pages and inactive MAIL, not a claim that pending PRs already reached main. No report email, schedule, deployment or actual data import.

## Integrating existing work

Merge prepared-owner-reports00d600f; its history already contains operational-reports3a1cb6f, booking-sales-reportc8fc191 and weekend-forecastc82982d (ancestry verified). Keep newer booking/row locks, imported money, outfit validation, driver matcher and snapshot; never take an older ConfirmBooking wholesale. Preserve routes, all9 pages, MAIL350000 and modern schema-default cost tests. Any inherited performance helper must preserve current locking reads before wiring it into confirmation.

Existing operations/bookings/weekend/snapshot contracts remain: REPORT-A-API, REPORT-A-SALES-API, REPORT-A-WEEKEND-API and REPORT-A-SNAPSHOTS-API. Only owner admins, active+2FA, finance sandbox, HTTPS/secure cookie in staging; no client/staff access. All responses private/no-store. Monetary fields are integer cents; missing/incomplete/stale costs and unknown package coverage are null plus explicit counts, never0 or a net-profit claim.

## Panel read

GET /admin/api/reports/owner?kind=weekly|monthly&as_of=YYYY-MM-DD

ReadOwnerReport::execute(User $actor,array $input):array. Real date2000–2100, not future in Houston. Weekly = last completed Monday–Monday week before as_of's week; monthly = last completed calendar month. Return schema_version1, environment=test, timezone America/Chicago, generated_at, kind, as_of, due_on, operations, bookings, upcoming_weekend (weekly; monthly null), delivery={recipient:sales@waooparty.com,status:not_sent,enabled:false}. This GET does not prepare a snapshot, audit a write, enqueue or contact a provider. Reuse each existing section's documented basis; live balances are observed now, not historical accounting at period cutoff. Include each section's dates; never label ongoing weekend as completed.

Cash = verified CRM test ledger receipts/refunds by received_at; scheduled events = event start in the period; package sales = first CRM capacity confirmation, independent of cash timing. Imported HoneyBook confirmations are excluded from new-sales counts and separately counted: import time is not the original sale date. Imported source money is not promoted to bank-verified cash; missing CRM financial coverage remains explicitly unknown. Sales/admission, forecast cost estimate, actual owner-entered costs and actual staff payments are distinct. Weekend staff costs sum only declared staff_labor/staff_transport lines from current complete cost snapshots. No guessed wages/transport formulas.

## Frozen preparation and email read

POST /admin/api/reports/snapshots {kind,as_of} and GET /admin/api/reports/snapshots/{id} remain unchanged. First completed company period snapshot wins across both admins; same period retry returns same id/hash, not another report. Stored JSON/hash remains immutable; historical unknowns are not quietly recalculated.

GET /admin/api/reports/snapshots/{id}/email-preview returns schema_version1, snapshot_id, payload_sha256, recipient fixed sales@waooparty.com, locale es, subject, text, sections, approved=false, sending_enabled=false. RenderOwnerReportEmail::execute(User $actor,int $snapshotId):array verifies hash of exact stored payload_json before formatting. Renderer uses that frozen payload, never queries today's money or substitutes a new weekend. Subject contains date-only range, bounded≤60; amount formatter preserves null as Pendiente. No private client names/phones, bank references, notes, individual staff pay or portal links. Owner email preview may contain company financial aggregates and event IDs, never expose to client/staff APIs.

Report transport is separate from client transactional email: sales@ is the owner's explicitly approved destination, not silently inserted into the client sandbox allow-list. MAIL provider readiness, approved report copy and explicit activation are prerequisites for future Monday/monthly delivery. No scheduler or send endpoint in this delivery; email preview says Preparado, sin enviar. A later sender must bind snapshot id/hash and claim once before transport, with delivery evidence and no blind retry.

Claude UI: Inicio reads the dynamic endpoint; email modal prepares/reads frozen snapshot then email-preview. Show completed dates, known subtotals + unknown counts and estimate vs actual separately. No client availability promise, fake staff-pay total or button saying Enviado. API/merge/review/owner acceptance are separate progress stages.
