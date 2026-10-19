# Responsible system — shadow HoneyBook and explicit CRM cutover, API proposal v1

2026-10-19 · Owner decision in PHASE-8.md, backend Codex / UI Claude. Design first; **not implemented by this document**. STAFF backend is the current delivery. Next order: C1 responsibility guard → C2 reviewed resync/reconciliation → C3 cohort cutover + financial bridge; TIP stays gated behind C1. No live import, messages, money or deployment authorized here.

## C1 — one responsible system per event

`events.responsible_system` non-null closed enum honeybook|crm. New CRM-born events explicitly crm; IMPORT-01 commits explicitly honeybook. Migration backfills all linked imported events to honeybook, others crm, recording system audit IDs/transitions without PII; imported originals/ledger untouched. Owner CRUD, client details or a CSV status field cannot set/flip this field. Only the cutover service changes an existing assignment. Keep original source identity forever after the switch.

`RequireCrmResponsibility::forEvent(int $eventId, bool $lock=false): Event` rejects honeybook with409 and stable `responsible_system_honeybook`. The caller's authoritative event lock precedes all subsequent financial/mail/grant locks. Imported links without a completed financial bridge remain blocked even if an invalid/manual SQL edit says crm; never remove ImportedAgreement::blockCrmLifecycle just to pass a flag check.

Apply to manual/automatic MAIL planning, occurrence capture/materialization and dispatch freshness, payment-grant issue/reveal and payment checkout/return actions, client Zelle submissions, and manual/automatic TIP grant issue/read/checkout. No click-through override. A tip from a verified late provider event is still recorded and flagged, not discarded. Read-only owner/calendar/resources/report/source reconciliation remains available to honeybook; capacity still reserved with existing normal rules. Staff read-only page is allowed and cannot initiate money/messages.

Existing scheduled CRM mail is not sent on a honeybook event; mark blocked with safe reason, do not silently delete evidence. A payment link opened after responsibility changes cannot charge; external sessions already issued/unknown must be explicitly reconciled before reversal. Every provider boundary revalidates responsibility and frozen source; checks solely in UI are insufficient. Flags stay OFF; no provider selected/configured by this work.

Expose responsibility in explicit owner event/import projections and money views (badge “HoneyBook cobra y avisa” / “CRM cobra y avisa”); never imply an imported historical Zelle was bank-verified. No automatic controls enabled just from changing the badge.

## C2 — source updates and immutable reconciliation

Use IMPORT-01's existing project identity/diff/update path, not a second importer. Same source + mapping repeated is a no-op. A changed existing project produces a reviewed diff against `current_revision_id`; owner must pin batch/row hashes, link/event versions and every affected operation. Preserve prior ImportedProjectRevision/ImportedLedgerLine rows. No real file in git/logs/jobs. Payment deltas do not become new verified Zelle/Stripe receipts. Missing/negative/unknown source amounts remain unresolved.

For shadow events: payment-only source updates can append an immutable source revision after explicit owner review. Date/time/address/package/resource/status changes on confirmed events require the normal CHANGE/logistics transaction and revalidation of all affected neighbors; do not overwrite signed/confirmed event rows from CSV. If that path is not implemented, show blocked/manual resolution; don't label the whole source update supported. After cutover, a new HoneyBook export cannot overwrite CRM money or responsibility; report discrepancy and reconcile explicitly.

`cutover_reconciliations` records cohort identity, source received_at, owner review time, exact sorted membership + event/import/link versions and hashes, counts/totals (known only), document references/hashes, unresolved count, status and creator. Snapshot payload private/encrypted; owner projections aggregate only. Original imported party totals (including explicitly accepted fee differences) remain exact; no reconstructed amount from package arithmetic.

Recent = source reconciliation age below an explicitly configured policy (`waoo.cutover_reconciliation_max_age_minutes`, positive integer); **no default policy chosen here**. Require all cohort source revisions and event versions unchanged in addition to timestamp freshness. Refreshing a preview does not refresh source age. Missing freshness policy or unresolved values means not ready. Owner supplies/approves the operational cutoff policy before activation; does not block synthetic backend tests.

## C3 — preview and switch the whole cohort

Cohort = all IMPORT-01 future-import project links under HoneyBook responsibility, with a snapshot of exact membership; a late new import invalidates the old preview. Already-ended/cancelled events stay accounted for but schedule no catch-up. Archived IMPORT-HISTORY rows are not imported anew into this operating cohort. No browser-submitted partial event list can masquerade as “all”.

Proposed services:

```php
ReadResponsibilityTransfer::execute(User $actor): array;
PrepareCutoverReconciliation::execute(User $actor, array $input, string $key): array;
TransferCohortToCrm::execute(User $actor, int $reconciliationId, array $input, string $key): array;
ReverseResponsibilityTransfer::execute(User $actor, int $transferId, array $input, string $key): array;
```

Proposed owner auth+2FA+CSRF routes (not yet implemented):

- GET `/admin/api/cutover/honeybook`: `{cohort_sha256,counts,totals,unknowns,readiness:{ready,missing:[]},reconciliation:{id,version,source_received_at,expires_at}|null,planned_future_reminders:{counts}}`. No token, bodies, customer list or invented totals. Details, if later needed, via a separate owner-only paginated projection.
- POST `/admin/api/cutover/reconciliations`, Idempotency-Key, `{expected_cohort_sha256,source_batch_id,expected_batch_version,expected_preview_sha256,coverage_sha256,owner_note}`: freezes reviewed complete source coverage; does not flip or send. Difference/missing/version conflict409.
- POST `/admin/api/cutover/reconciliations/{id}/transfer`, Idempotency-Key, `{expected_version,expected_cohort_sha256,honeybook_automations_disabled:true,owner_note}`: owner attests actual HoneyBook automations are OFF; this is human evidence, not a claim that the CRM remotely disabled them. Locks events/resources in existing stable order, then source/reconciliation rows, revalidates every member/freshness/document/money prerequisite, records one transfer and per-event audit atomically. No partial flip. Returns `{transfer_id,status,event_count,future_reminder_count,sending_enabled:false}` in sandbox.
- POST `/admin/api/cutover/transfers/{id}/reverse`, Idempotency-Key, `{expected_version,owner_note}`: all-or-nothing only if **no CRM message/charge or uncertain external attempt** exists for the cohort and open external checkouts are demonstrably expired. sent/delivered/delivery_unknown/dispatching, verified receipts or unknown Stripe results block reversal. Otherwise individual reconciliation, never deletion of effects.

UUID/payload exact idempotence, no stale key reuse; same successful transfer cannot issue duplicate notices/payments. No provider calls while holding database locks. Cutover schedules **future only**, preserving immutable intent/source evidence; missed reminders are skipped with reason, not sent in a batch. Provider activation and client-visible sending remain separate explicit owner steps. A transfer never buys/transfers the SMS number, changes DNS or switches the website by itself.

## Financial bridge is mandatory, not merely an enum

Current code always chooses ImportedAgreement::summary for linked imported events and blocks CreateFinancialRevision/checkout/contract lifecycle. C3 must add a reviewed immutable cutover agreement: original source revision/hash, exact total/paid/refunds/credit/balance/due date, document originals and effective time. Keep source funds “HoneyBook, not independently bank verified”. Do not copy them into verified CRM receipts or count old sales as new CRM business. Use original agreed amounts, not today's catalog prices.

FinanceSummary after the bridge must combine frozen source opening amounts with **only post-cutover CRM** verified receipts/refunds, without double counting a resynced source payment. Original source contract/PDF evidence stays original; no forged CRM signature or automatic re-signing. Checkout/payment authorization must recognize the approved imported agreement explicitly, with original documents and reconciliation binding; leaving CreateContract::assertCurrent unchanged while merely flipping the enum does not implement takeover.

Bridge and cohort transfer must commit together; until then fail closed on imported lifecycle. Source original total discrepancies, missing original documents, unknown refunds/amounts, changed contact, open collection attempts, or unreconciled provider events block readiness; no invented cents or dates. REPORT-A separates source balances, new collections and staff tips; historical report snapshots remain immutable. Migration does not imply funds verification or a live readiness claim.

## Required evidence

Backfill/new-born classification, immutable source provenance, owner-only changes, honeybook MAIL/payment/TIP denial at service and route boundaries (even if old links exist), ordinary staff/calendar reads still work, two concurrent flips, late new cohort member, changed revision/stale reconciliation, exact payment updates with no duplicate source funds, confirmed logistics update refusal until validated, all-or-nothing rollback, no catch-up mail, source+CRM balance correctness, original signed PDF preservation, and reversal refusal on unknown/sent/paid provider effects. MariaDB locks/concurrency; all fixtures synthetic. Completion requires independent review, CI on the actual integration and owner's acceptance. Document earns0 implementation credit.
