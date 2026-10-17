# QUOTE-01 API v1 — publish before implementation

2026-10-17 · Codex. Source ticket: docs/tickets/QUOTE-01.md on main98b3435. This is the shared backend/UI contract; it does not approve legal/email copy, providers, prices or deployment.

## Delivery boundaries

**A (first implementation):** owner create/read/revise from an existing event draft, immutable monetary snapshots with explicit discounts, optimistic concurrency/idempotency and bounded expiry command. No client link, acceptance, PDF, payment, financial revision, capacity hold or automatic message in A. A was delivered separately. B/C backend is now implemented on `codex/quote-portal`; see docs/QUOTE-PORTAL-API.md for the detailed contract and HANDOFF for verification/review status. UI activation still requires independent review and approved consent configuration.

**B (backend delivered for review):** scoped quote portal grant/recovery, send-as-presentation, private PDF, accept/reject evidence. **C:** explicit accepted-quote-to-finance/booking bridge preserving the entire accepted snapshot, including discounts; real existing payment/capacity checks still apply. QUOTE-01 remains pending independent review, UI integration and owner acceptance. IMPORT-01 follows QUOTE-01, then MAIL-01/MSG-01, then AVAIL-WEB-01.

Create from scratch/lead: the owner first chooses/creates a customer explicitly and saves an event draft through the existing audited services (never auto-merge duplicate hints). Then call CreateQuote with that event and displayed version. A does not invent a provisional customer, create a paid QuickBooking, mutate the lead or reserve resources. One quote aggregate per source event; use revisions for alternatives rather than competing active quotes for one draft. Optional lead_id records provenance only and never changes lead state in A.

## Gates, transport and write semantics

- Owner with active confirmed2FA, fresh actor authorization on direct services and HTTP. New `WAOO_SANDBOX_QUOTES=false`; local/testing/staging only, production denied. Existing finance sandbox gate also required. No provider keys needed for A.
- Owner endpoints under existing web/CSRF/admin authorization/throttle. Responses private/no-store. Errors use422 validation fields,409 stale/state/idempotency conflict,404 not found,403 permissions/feature disabled,419 missing staging CSRF.
- Required UUID `Idempotency-Key` on create/revise; service receives it explicitly. Same key + normalized validated input returns the original immutable revision BEFORE current version/expiry/catalog checks. Same key + different input409. No duplicate quote/revision/audit on retry. Never generate a new key on an uncertain response.
- Lock order: owner serialization row → event → quote (event → quote for system expiry). Compare both displayed versions; never substitute current DB version for what the owner saw. Transactions include quote/header/revision/audit/receipt. No money/document/collection side effects in A.

## Data and prices

- `quotes`: id, number `Q-<Houston year>-<global zero-padded id>`, event_id (unique), customer_id, optional lead_id, status, version, current_revision, valid_until UTC, locale es|en, private owner_note, created_by. Status enum: draft|sent|accepted|expired|superseded|rejected. A only creates draft and expires draft/sent; it does not expose arbitrary status writes.
- `quote_revisions`: immutable unique(quote_id,revision), source event version, currency USD, customer snapshot, event date/time/address/ZIP snapshot, service lines, private travel evidence, explicit discount snapshots, integer totals, valid_until, created_by. Updating/deleting evidence via Eloquent is prohibited. Older revisions remain unchanged; supersession is derived from the aggregate's current revision, not a mutation of historical rows.
- Service lines come from the draft's exact immutable PriceVersions. V1 uses one unit per distinct selected service (same limit10 as SaveEventDraft); quantity is1, not client-entered. Character quantities/outfits follow the source event and private character notes are excluded. Name, description, duration, recipe and character labels are captured so later catalog edits cannot rewrite a revision. Quote requires active catalog variants, complete character selections, at least one service and an explicit travel fee; unknown never becomes zero.
- `subtotal_cents = sum(lines.amount_cents)`; `gross = subtotal + travel`; `discount_cents = sum(explicit discounts)`; `total = gross - discount`; total must be positive. `deposit_target_cents = intdiv(total,2)+(total%2)`. All server-derived; request totals/currency/fee-policy/approval IDs are prohibited. Tax/customer card fee are not introduced into these totals by A.
- Discount input list (max5): `{label, amount_cents, reason, expires_at?}`. Amount positive integer; reason10–1000chars; approval actor/time recorded by the server, not accepted from the browser. Discounts cannot exceed gross or expire before quote validity. No implicit promotion, percent calculation or hidden base-price change. No discount is applied to any ledger/financial agreement in A.
- valid_until defaults to7 days from creation/revision if omitted, owner-editable with explicit UTC offset; must be future and no later than the event start. Short-notice drafts therefore need an explicit shorter validity. Revision changes must submit the complete discount list; empty list explicitly clears discounts for that new snapshot. It must not silently carry an old discount into a new offer.
- Locale defaults to the current customer's locale; private note is owner-only. Source event/customer versions do not advance when writing quotes. Accepted revisions cannot be revised; later changes belong to CHANGE-A, not silent repricing.

## Implemented in A: owner API and service signatures

`App\Services\Quotes\CreateQuote::execute(User $actor, array $input, string $key): QuoteRevision`

`POST /admin/api/quotes` →201 `{quote, revision}` (same shape on replay).

```json
{
  "event_id": 12,
  "expected_event_version": 3,
  "lead_id": 4,
  "locale": "es",
  "valid_until": "2026-11-05T15:00:00-06:00",
  "owner_note": "Private owner context",
  "discounts": [{"label":"Owner-approved adjustment","amount_cents":1000,"reason":"Explicit adjustment approved for this event."}]
}
```

Only event_id/expected_event_version are required on create; discounts defaults to empty. Quote creation does not update customer data, lead status, financial revisions or the event draft.

`App\Services\Quotes\ReviseQuote::execute(User $actor, int $quoteId, array $input, string $key): QuoteRevision`

`POST /admin/api/quotes/{quote}/revisions` →201 `{quote, revision}`. Input: required `expected_version`, `expected_event_version`, `discounts` (possibly empty); optional `locale`, `valid_until`, `owner_note`. Snapshot is recaptured from the current event version. A allowed only draft quotes; B now also revises sent/rejected/expired offers with atomic old-quote-grant invalidation, never by writing status directly. Accepted revisions are immutable regardless of future UI.

`App\Services\Quotes\ReadQuotes::index(User $actor,array $input): LengthAwarePaginator` → `GET /admin/api/quotes`, status optional from enum, page>=1, per_page1–50 (default25), newest first. UI must retain pagination controls and reset page on filter change (lesson from #59).

`ReadQuotes::show(User $actor,int $quoteId): array` → `GET /admin/api/quotes/{quote}` → `{quote, revisions, decisions, presentations, conversion}` (B adds owner-only decision evidence and safe presentation/conversion metadata); revisions newest first. Owner-only full projection; NOT a client/public serializer. `effective_status` reports expired if unaccepted validity elapsed even before the expiry command runs. Header current revision/version must remain distinct from a historical returned revision on an idempotent replay.

`App\Services\Quotes\ExpireQuotes::execute(int $limit=100): int` and `php artisan waoo:expire-quotes --limit=100`: system audit, event→quote locks, bounded1–500, recheck at mutation time; only draft/sent with valid_until<=now, version increments once. Accepted rows never expire. No automatic Hostinger cron installation or messages in A. B acceptance checks time directly and never depends on command punctuality.

## B/C — implemented backend; detailed contract and activation gates

- `SendQuote::execute(User,int,array,string)` / `POST /admin/api/quotes/{quote}/send`: expected_version + expected_revision, present snapshot, issue grant bound to quote AND exact revision/customer/event using only quote.read/quote.accept. Status sent means link prepared, not email delivery; reuse encrypted recovery of COMMS-01. Portal still authenticates via fragment-token → POST session. Existing event grants must not gain quote access implicitly. A quote-specific grant must not gain payment/contract/details access. Revise/revoke invalidates the old quote grant; an old link never silently presents a replacement offer.
- Client/PDF projection must whitelist quote number, exact revision, validity, client-visible services/character labels, event details, discount labels+amounts and totals. Never serialize the owner aggregate/revision wholesale: owner_note, internal discount reasons/approval IDs, route evidence, recipes and audit metadata remain private.
- `GET /portal/api/quote`, `GET /portal/api/quote/pdf`, `POST /portal/api/quote/accept|reject`: derive quote/customer/event from the authenticated scoped grant, not arbitrary client IDs. Expected revision, exact configured consent text/version+hash, name/email, IP/UA/time evidence. Accept twice gives one acceptance; stale/superseded/expired/revoked/cross-client links reject. No money or capacity mutation. Acceptance is not a contract signature and does not substitute legal e-signature evidence. Copy wording needs its own approved configuration, not inferred approval of draft email templates.
- `ConvertAcceptedQuote` must reference immutable accepted revision/hash and explicitly displayed event version, create or bind matching finance, preserve catalog names/descriptions/characters/travel/discount/total/deposit, and then use existing recorded-payment/capacity services. Never use browser amounts or recompute at current catalog prices. C now adapts CreateFinancialRevision/ConfirmBooking and contract/PDF/payment targets together; only an actual matching ledger receipt can confirm quote-bound capacity. QuickBook's post-core grant recovery/idempotency remains intact; do not wrap QuickBook in an incompatible outer transaction.
- Card3% disclosure is separate from the quote's base+travel-discount total; current test Checkout policy remains. No Affirm/pay-later is enabled by this ticket's wording. Short-notice/15min exception and45min transfer rules remain capacity decisions, not acceptance guarantees.

## Other new ticket review notes for Claude

- IMPORT-01: proceed second after quote foundation/bridge. Private CSV staging, explicit mapping, source IDs + content hash/revision, preview before commit, external money source HoneyBook (not newly verified Zelle/Stripe), capacity remains draft until normal checks. Pilot cutover must be blocked while future confirmed HoneyBook events are unmapped/unreserved; importing only money/events is not capacity reconciliation. No real export needed for synthetic parser/backend work.
- MAIL-01/MSG-01: proceed third; supplied v1 texts are **draft/unapproved** and must remain inactive. Delivery statuses need append-only provider events plus an evolving projection, not updates to an immutable log. Verify actual provider webhook authentication scheme (do not invent a signature header); postpone provider/DNS/send activation. Stable idempotency must include event/quote revision, template key/version, locale and channel; retry rechecks cancellation/reschedule/paid balance and consent/suppression.
- AVAIL-WEB-01: proceed fourth. Public output may expose only coarse levels/alts, never imply a confirmed booking from ZIP-only routing. Missing full inventory, exact address/traffic evidence, short-notice owner approval or incomplete HoneyBook import must produce unknown/review rather than open. Weekend demand wording is marketing demand, not fabricated occupied capacity. Keep iCal fallback and no form hard-block. No public endpoint or live website configuration is added in QUOTE A.

## Verification required

Integer/odd-cent totals; discount authorization/limits/expiry; immutable previous snapshots after catalog/event changes; exact idempotency on later versions/expired defaults; same-key/different-body rejection; two actual owner/create and revise races; event/quote version drift; audit failure rolls back header+revision+receipt; fresh-owner2FA/direct/HTTP/CSRF/privacy/gate tests; populated SQLite/MariaDB migration round trips; accepted never expires. B/C regression tests cover scoped grant/PDF/consent/replay/cross-client boundaries, real decision/revision/revoke/conversion races, and exact accepted-to-booked totals with verified fake receipts. Independent review and UI integration still precede activation.
