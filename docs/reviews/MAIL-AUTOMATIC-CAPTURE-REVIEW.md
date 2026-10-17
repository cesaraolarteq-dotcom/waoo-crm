# Automatic mail A: transactional capture and authenticated bounces

2026-10-17 — Codex — codex/mail-automatic-backend. Contract641cef0 published before code; carried onto this integrated base in79f36ed. Sourcea786e98. Base includes reviewed #73/#74, report integration and QA; this is a stacked branch, not a main merge. César authorized parallel work while away; Claude's report/staff UI assignment is the top HANDOFF entry on codex/phase-6-reports e2a091c.

## Delivered and deliberately separate

Six real transactional hooks capture inquiry_received, quote_sent, details_request, contract_ready, payment_received and zelle_notice_pending. Capture shares the domain transaction and disappears on rollback; mail OFF produces nothing and activation does not backfill. Domain-source identity deduplicates independently of browser retry keys. A replacement portal link does not retarget the original occurrence. Issuing a contract without a suitable link does not create a fake link; contract-ready captures when the owner later issues one. Both before/after issue link orders tested. QuickBook details links bind the actual quick booking, original finance and issued grant. Zelle declaration is still a claim; only actual verified owner/Stripe receipts emit payment_received. No imported money promoted to bank-verified credit.

Occurrence locators are encrypted IDs, with hidden serialization fields and no email/token/note. Immutable source row, mutable pending state, unique domain hash and one state. Losing concurrent insert uses a savepoint/current read; normal three-attempt transaction retry handles real deadlocks. The two-process test starts with no occurrence, so it proves insertion contention rather than just reading an existing row. Owner-only paginated `/admin/api/mail/occurrences` exposes metadata only; active owner+2FA/sandbox/TLS required. Existing message DTO adds nullable lead/source IDs for the later rendering slice.

Migration370000 allows actual lead-only/quote envelopes later without fabricating domain or money rows. Current slice creates **no new automatic OutboundMessage**. It does not implement MaterializeMailOccurrence, source-specific rendering, the polling command, generic dispatch freshness or six automatic sends. Existing three reminder paths remain unchanged. Pending captures are not approved copy, scheduled messages or delivery proof. A materializer must bind/revalidate immutable source and contact/event versions before any envelope can dispatch; old/incomplete locators must be blocked rather than inferred. Separate review needed for that next slice.

## Concrete inbound Postmark adapter

Stateless exact route `/webhooks/postmark/bounces`, independently OFF flag, HTTPS, dedicated >=32-character native HTTP Basic secret, server+stream binding, max64KiB JSON. Raw-body normalization bypass prevents global JSON transformations before authentication. No web session/CSRF exception needed; no email/header/body logging. Native Basic Auth only: no invented signature claim.

Records must bind an existing postmark send id/hash and the encrypted intended recipient. Unknown/early mapping503 allows retry; cross-provider does not map. Unsupported/malformed/wrong-server/stream/recipient/future/non-offset timestamps rejected. Exact record replay200, changed record409, complaint outranks later bounce/delivery. Normalized append-only evidence contains hashes and timestamps only; no provider dump or financial mutation. Receiver can process while outgoing mail remains OFF. DisabledMailTransport binding unchanged and no Postmark sender installed/configured.

No live provider request or delivery. Expected ServerID/MessageStream/RecordType/field shapes and real Basic Auth provisioning must be checked against the selected provider's actual sandbox before enabling. Official provider documentation could not be fetched earlier (403); this is synthetic protocol verification, not a claim that the account/provider is ready. Domain/DNS/from-address and owner-approved copy remain separate prerequisites.

## Verification

- Before capture integration, Postmark + existing outbox32 tests/158 assertions passed (52.453s).
- Final capture/Postmark/Stripe-hook/SQLite migration18 tests/112 assertions passed,30.105s, PHP8.3.35/MariaDB11.8; includes real two-process race and both contract/link orders.
- New source remains fixed at a786e98 during full verification: FULL_RESULT_PENDING.
- PHP lint across app/bootstrap/config/database/routes/tests, strict Composer and diff checks pass. Vite result recorded with final evidence.

An intermediate test exposed a real repeatable-read losing-insert problem; fixed with current locking read and bounded outer transaction retry. Earlier exploratory run also exposed a too-broad migration rollback guard: it now blocks only envelopes whose nullable links cannot be represented by the old schema. SQLite test proves fresh up/down/up, old populated row preservation and safe refusal before altering unrepresentable data. No real data imported or processed; all fixtures synthetic. No browser/provider/deployment approval implied.

## Request for Claude

Review capture uniqueness/atomicity and provider authentication/binding. Preserve9 pages, MAIL and report changes. Do not wire a Send button or label pending as sent. For now concentrate on REPORT-A/STAFF UI as assigned. Later warnings with lead_id and no event_id must link to Leads, never an event/null URL. Text/template approval, materializer, generic freshness/dispatch and delivery activation remain pending. Update progress for only reviewed code/UI, not whole-ticket completion.
