# Responsible system — shadow HoneyBook and explicit CRM cutover, API proposal v1

2026-10-19 · Owner decision in PHASE-8.md, backend Codex / UI Claude. Design published before code and approved by Claude. C1 is implemented for review on `codex/responsibility-c1` (ead6e67 +5415421); C2/C3 are not implemented. Current order: C1 → versioned owner staff instructions read → TIP behind C1 → C2 reviewed resync/reconciliation → C3 cohort cutover + financial bridge. Publication of this API does not activate any operation. No live import, messages, money or deployment authorized here.

## C1 — one responsible system per event

`events.responsible_system` non-null closed enum honeybook|crm. New CRM-born events explicitly crm; IMPORT-01 commits explicitly honeybook. Migration backfills all linked imported events to honeybook, others crm, recording system audit IDs/transitions without PII; imported originals/ledger untouched. Owner CRUD, client details or a CSV status field cannot set/flip this field. Only the cutover service changes an existing assignment. Keep original source identity forever after the switch.

`RequireCrmResponsibility::forEvent(int $eventId, bool $lock=false): Event` rejects honeybook with409 and stable `responsible_system_honeybook`. Call only after the caller's authoritative event lock, **preserving the existing protocol**: portal/MAIL paths already lock grant → event → source/state. Do not move an event lock ahead of an existing grant lock. New-grant creation without an existing grant can lock the event directly. Cohort operations must acquire any relevant existing grants in ascending ID before events, then use the existing event/resource/source order and revalidate membership after locks. Imported links without a completed financial bridge remain blocked even if an invalid/manual SQL edit says crm; never remove ImportedAgreement::blockCrmLifecycle just to pass a flag check.

Apply to manual/automatic MAIL planning, occurrence capture/materialization and dispatch freshness, payment-grant issue/reveal and payment checkout/return actions, client Zelle submissions, and manual/automatic TIP grant issue/read/checkout. No click-through override. A tip from a verified late provider event is still recorded and flagged, not discarded. Read-only owner/calendar/resources/report/source reconciliation remains available to honeybook; capacity still reserved with existing normal rules. Staff read-only page is allowed and cannot initiate money/messages.

Existing scheduled CRM mail is not sent on a honeybook event; mark blocked with safe reason, do not silently delete evidence. A payment link opened after responsibility changes cannot charge; external sessions already issued/unknown must be explicitly reconciled before reversal. Every provider boundary revalidates responsibility and frozen source; checks solely in UI are insufficient. Flags stay OFF; no provider selected/configured by this work.

Expose responsibility in explicit owner event/import projections and money views (badge “HoneyBook cobra y avisa” / “CRM cobra y avisa”); never imply an imported historical Zelle was bank-verified. No automatic controls enabled just from changing the badge.

## C2 — source updates and immutable reconciliation

Use IMPORT-01's existing project identity/diff/update path, not a second importer. Same source + mapping repeated is a no-op. A changed existing project produces a reviewed diff against `current_revision_id`; owner must pin batch/row hashes, link/event versions and every affected operation. Preserve prior ImportedProjectRevision/ImportedLedgerLine rows. No real file in git/logs/jobs. Payment deltas do not become new verified Zelle/Stripe receipts. Missing/negative/unknown source amounts remain unresolved.

For shadow events: payment-only source updates can append an immutable source revision after explicit owner review. Date/time/address/package/resource/status changes on confirmed events require the normal CHANGE/logistics transaction and revalidation of all affected neighbors; do not overwrite signed/confirmed event rows from CSV. If that path is not implemented, show blocked/manual resolution; don't label the whole source update supported. After cutover, a new HoneyBook export cannot overwrite CRM money or responsibility; report discrepancy and reconcile explicitly.

`cutover_reconciliations` records cohort identity, source received_at, owner review time, exact sorted membership + event/import/link versions and hashes, counts/totals (known only), document references/hashes, unresolved count, status and creator. Snapshot payload private/encrypted; owner projections aggregate only. Original imported party totals (including explicitly accepted fee differences) remain exact; no reconstructed amount from package arithmetic.

César approved **240 minutes (4 hours)** on 2026-10-19: at transfer, the last actual reviewed source-payment reconciliation must be no older than 240 minutes. C3 will enforce `waoo.cutover_reconciliation_max_age_minutes=240` (positive integer). Require all cohort source revisions and event versions unchanged in addition to timestamp freshness. Refreshing a preview does not refresh source age. Missing freshness policy or unresolved values means not ready. Refreshing a page or re-uploading an old file does not count as a new payment review. This is a transfer prerequisite, not an automatic four-hour polling/sync schedule. Policy is approved; C3 enforcement is not implemented or activated here.

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

## Concrete integration seams identified on main #84 + MAIL A (design inventory)

| Boundary | Current source | C1 behavior |
|---|---|---|
| MAIL planning/render freshness | PlanEventMail + EventMailSource::lock | Check current event responsibility inside existing grant→event transaction; retain imported-agreement guard until C3 bridge exists |
| MAIL source occurrence | CaptureMailOccurrence::record / CaptureEventLinkMail | Check event-bound occurrences before capture/replay; lead-only inquiry has no event and must retain its separate CRM-origin provenance, never create a fake event |
| MAIL external claim | DispatchOutboundMail::execute | Block HoneyBook before scheduled→dispatching; cutover reversal must reject dispatching/delivery_unknown, so an in-flight call cannot be flipped underneath |
| Payment access | IssuePortalGrant / RevealPortalGrantLink / PortalContext authorization | Deny issuing/revealing checkout-capable grants and payment endpoints for HoneyBook; do not turn an owner read/calendar into a payment action |
| Payment creation / Zelle notice | CreateCheckoutIntent / SubmitZelleNotice | Gate before idempotent result reuse and before any new provider call; a former link is not authorization after a responsibility change |
| Provider evidence / cleanup | StripeReceiptProcessor / RecordMailDelivery / SupersedeCollections | Keep authenticated evidence and cleanup accepted; never discard a verified late receipt, complaint or reversal just because responsibility changed. Flag inconsistent source for reconciliation; do not initiate sending/charging |
| Staff weekly read | ReadStaffWeek / StaffContext | No financial responsibility gate: imported confirmed assignments still appear, money-free |
| TIP access | Proposed IssueTipGrant / IssueTipGrantForMail / ReadTipViewModel / CreateTipCheckout | Both manual and email issuance, projection and checkout require CRM responsibility; no real TIP service exists yet |

This inventory does not implement C1. Tests must prove the actual final call paths/lock order instead of trusting a UI badge or this table. In particular, RecordMailDelivery handles inbound provider evidence and must not accidentally become a disabled outbound sender.

### IMPORT-01 update path: verified current limit

On source `CommitHoneyBookEvent::execute`, the existing `expected_import_version` / `expected_event_version` update path intentionally permits only drafts with no reservations/contracts/CRM ledger. Therefore PHASE-8's confirmed-event re-sync is **not implemented today** and must not be inferred from idempotent re-upload.

C2 extends that same reviewed row-commit path. If the operational selection, total/agreement and schedule are unchanged, a reviewed payment/source-date update may append ImportedProjectRevision/ImportedLedgerLine and move only the source head after locking event→link in the importer protocol. It must not call SaveEventDraft on a confirmed event, replace original BookingEvidence, regenerate service recipes from today's catalog, re-reserve resources or turn historical source funds into verified bank money. Existing versions/rows remain readable; source decreases/refunds/credits need explicit reviewed provenance, not a fabricated negative Stripe/Zelle receipt. Source total discrepancy acceptance cannot be silently widened to a changed total; retain the original scoped evidence or require a new explicit decision.

Any service/date/address/resource/status change still needs the dedicated logistics/change path and affected-neighbor validation; until that exists, return a precise blocked reason. A raw re-import is not an administrative override. After CRM takeover, conflicting HoneyBook updates are reconciliation evidence, never an automatic rollback of new CRM receipts.

Internal capture is not an owner send command: when a legitimate domain operation records an authenticated late payment on a HoneyBook event, the mail hook must skip the occurrence (return null / safe suppression reason), **not throw and roll back the verified receipt**. Public mail planning/payment/TIP initiation still rejects responsibility conflicts. Test both halves: genuine provider evidence survives, and no message/checkout is created. A thank-you for a HoneyBook event is blocked as a whole; the “tip unavailable → review button only” fallback is for an otherwise permitted CRM email, never a way around the responsibility gate.

## C1 implementation boundary amendment — 2026-10-19

Current delivery branch is based on PR #85, which remains untouched. C1 precedes owner instructions read, TIP, then C2/C3. Responsibility rejection is HTTP409 `{code:"responsible_system_honeybook",message:...}`. Event-born CRM defaults are explicit in the model and database; importer marks new events HoneyBook inside its existing metadata-audited transaction without changing financial/event versions. Migration records metadata-only system classification audit for preexisting events. Owner/import detail projections include the closed enum. There is no toggle/transfer endpoint in C1.

Payment-scoped grant reveal/authorization and checkout return are denied for HoneyBook. Internal occurrence capture checks before dedupe and returns null for HoneyBook, preserving legitimate late provider receipt transactions. Inbound Stripe/mail evidence and collection cleanup retain their existing paths. Dispatch marks scheduled HoneyBook mail blocked with the stable code before pause/source/render/transport; terminal receipts remain unchanged. Public payment/mail commands reject, never silently succeed. Existing imported-agreement blockers stay in place even if the flag is incorrectly changed. In-flight external checkout intents and dispatch states remain reconciliation obligations for C3; no unsafe manual flip is added here.
