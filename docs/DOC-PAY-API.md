# DOC-01 / PAY-01 / PAY-02 — data and API contract for Claude

2026-10-15 America/Chicago · Codex · owner authorized this parallel increment in chat while Claude finishes Phase 1 and then QUICK-BOOKING/portal UI.

**Status: contract published before implementation. Routes, tables and services below are not delivered yet.** Codex owns backend/schema/integrations; Claude owns screens and reviews. Each delivery will mark which routes are implemented. No live deployment, live Stripe requests, actual charges or client messages are authorized. Catalog gaps do not justify invented prices, recipes or contract terms. This increment does not close Phase 1 or authorize the rest of Phase 4.

## Delivered increment: sandbox Zelle and finance (codex/sandbox-doc-pay)

Implemented after this contract was published: financial revision creation, event finance summary, payment list, verified Zelle registration, payment-exception list and ledger-backed confirm-paid-capacity routes. Sandbox flags default false and the backend rejects production regardless of flag. Eight finance tests plus the Phase 1 adapter tests bring the complete suite to **122 tests / 886 assertions on MariaDB**. Contract/signature/portal/checkout/webhook routes below remain planned until their own delivery; installing dompdf/Mustache/Stripe SDK does not count as those modules being implemented. A fixture-tested ledger is not a real bank/provider reconciliation proof.

Financial revision replacement is intentionally restricted to drafts in this increment; booked financial changes await CHANGE-A. No refunds/adjustments API or quote-acceptance workflow is claimed. The initial capacity linkage is immutable and reused after later balance receipts, so retry does not rewrite deposit evidence or count it as another payment. Current legacy confirm-capacity stays separate from the ledger adapter; no automatic import of its receipt evidence.

## Screen boundaries and shared conventions

- Owner admin session + confirmed 2FA + CSRF for `/admin/api/*`. Use existing error shapes: 401/403 authorization, 404 inaccessible object, 409 stale version/idempotency conflict, 422 field/business errors, 429 throttling. Integers in USD cents; no floating-point money inputs. Dates are ISO 8601 with explicit offset; store UTC, display America/Chicago.
- Mutations take `Idempotency-Key` (UUID) where listed. Same actor/scope/key + same canonical payload returns the existing result; different payload returns 409. IDs and financial facts come from server lookups, not client totals. Sensitive responses/downloads use `Cache-Control: no-store, private`.
- Finance, capacity, contract signature and workflow status remain separate. UI must show e.g. "Pago recibido · Recursos pendientes · Firma pendiente". A payment or signature alone never marks a party fully confirmed. A draft/quote/signature reserves nothing (D7).
- Current Phase 1 `confirm-capacity` records external receipt evidence; it is not the new ledger. Never count `booking_evidence` plus ledger entries as two payments. New ledger-backed confirmation gets a separate adapter below; do not migrate existing evidence silently.
- All features start disabled outside local/testing or an explicitly configured sandbox. Stripe adapter rejects live keys and `livemode=true` events. No automatic email/SMS/WhatsApp, PDF delivery, or actual customer activity. Tests use fictitious records. Zelle is a recorded external payment, not a bank integration or charge.

## Financial revisions: the amount the customer agreed

New immutable `financial_revisions`: id, event_id, revision, currency USD, event_version, lines_snapshot, subtotal_cents, travel_fee_cents, tax_cents, customer_fee_cents, total_cents, deposit_target_cents, balance_due_at, created_by, created_at. Line snapshots identify event-service/price versions and amounts; unknown travel fee prevents creation. Initial revision derives amounts from the saved event's selected price versions plus its known travel fee. No invented catalog amount, tax or method surcharge; taxes/customer fees default to zero unless backed by a separately approved/configured rule. Deposit target = ceiling(total_cents / 2), balance due = event start minus 24 hours.

POST `/admin/api/events/{event}/financial-revisions`, body `{expected_version}` + Idempotency-Key → 201 financial revision. Actor explicitly chooses snapshot creation; quote acceptance/booking workflow remains a separate screen/step. Reject stale event, missing services/prices/travel fee and unsupported currency. GET `/admin/api/events/{event}/finance` returns:

```json
{
  "environment": "test",
  "financial_revision": {"id": 1, "revision": 1, "currency": "USD", "total_cents": 45500, "deposit_target_cents": 22750, "balance_due_at": "2027-06-12T20:00:00Z"},
  "received_cents": 22705,
  "refunded_cents": 0,
  "net_received_cents": 22705,
  "balance_cents": 22795,
  "credit_cents": 0,
  "deposit_shortfall_cents": 45,
  "balance_label": "Remaining balance due",
  "capacity_status": "draft",
  "contract_status": "not_issued",
  "exceptions": []
}
```

Example amounts are fictitious fixtures, not Waoo prices. `balance_cents=max(total-net_received,0)` and `credit_cents=max(net_received-total,0)`; never hide overpayment as zero or a negative balance. Use "Remaining 50% balance due" only when mathematically exactly half, otherwise "Remaining balance due". Returns null financial revision/amount targets before one exists, not fabricated zero totals. Changing agreed price/date after issue requires a new revision and the appropriate quote/change acceptance; this module never rewrites a signed document or booked snapshot. Subsequent accepted financial revisions carry the same event-level ledger payments once, not copied payments per revision.

## PAY-02: verified external Zelle ledger

`ledger_entries` are append-only monetary facts: id, event_id, financial_revision_id at receipt, environment (`test` initially), kind (`receipt`, later linked `refund`/`adjustment`), method (`zelle`, `stripe`), amount_cents positive, currency, received_at, external_reference, external_identity_key unique, verified_by (owner or verified webhook source), source_type/id, note, created_at. A provider fee is a separate fact/cost, not a reduction in customer principal. Reversal/correction is a linked new entry with reason; no editing/deleting a receipt. Refunds/disputes do not automatically cancel/release capacity.

POST `/admin/api/events/{event}/payments/zelle`, Idempotency-Key, body:

```json
{"financial_revision_id":1,"amount_cents":22705,"currency":"USD","reference":"FIXTURE-ZELLE-001","received_at":"2027-06-01T14:30:00-05:00","verified_received":true,"note":"Verified external receipt; fixture"}
```

Owner enters the **actual** received amount and explicitly confirms bank verification, not the theoretical deposit. Server requires amount > 0, past/present explicit timestamp, nonempty reference and an existing current financial revision for that event. Globally deduplicate normalized Zelle reference in the sandbox; same reference with different event/amount/time is a conflict. Exact idempotent retries return the original receipt/finance summary. A pending client declaration or screenshot is not a receipt. Do not expose an endpoint that lets the portal mark Zelle received.

GET `/admin/api/events/{event}/payments` returns paginated ledger facts with `received_at`, `amount_cents`, `method`, `reference`, verifier and correction links. UI uses "Registrar Zelle verificado", lists actual receipts, shortfall, balance and any overpayment for owner resolution. Registering an external receipt commits even if capacity cannot be secured later; never roll real money back because resources conflicted.

## PAY-01: Stripe Checkout, strictly test mode

`payment_intents` are local collection intents (not raw card data): id UUID, event_id, financial_revision_id, purpose (`deposit`, `balance`, `installment`), principal_cents, customer_fee_cents (0 for now), payable_cents, currency, status (`creating`, `open`, `processing`, `paid`, `expired`, `failed`, `superseded`, `reconciliation_required`), stripe_checkout_session_id, stripe_payment_intent_id, expires_at, idempotency key/request hash, environment test. One active collection intent per event prevents two active collections against the same balance. A provider timeout leaves a recoverable intent; retry the same Stripe idempotency key, never create a new charge blindly.

POST `/admin/api/events/{event}/checkout-intents`, Idempotency-Key, body `{financial_revision_id, purpose, amount_cents?}` → intent + `checkout_url`, `expires_at`, `environment:"test"`. Portal equivalent uses its scoped event grant and cannot pass event/customer totals. Server calculates deposit shortfall or remaining balance; installment requires an explicit positive amount no greater than balance. No checkout for zero due/unknown total; reject stale financial revision. Use Stripe-hosted Checkout; CRM never sees PAN/CVC. Success/cancel return URLs are server-configured same-origin routes, not submitted URLs. A success return is informational and **does not record payment**.

**Affirm is excluded.** Use an explicit enabled test payment-method allowlist, never uncontrolled automatic methods: card initially; add Klarna/Afterpay/Zip only when separately configured and verified with the test account/currency/amount. Never forward an arbitrary method list from the browser. This restriction preserves the owner-confirmed supported-method direction without assuming every method works for every test amount. Stripe's card method alone cannot guarantee credit-only cards; no 3% surcharge is enabled in this increment. Debit/prepaid restrictions and per-method/BNPL surcharge approval remain unresolved configuration prerequisites, not renamed fees.

POST `/webhooks/stripe` is CSRF-exempt **only for this exact route**, signature required over raw bytes using the dedicated test endpoint secret. Verify mode/account, persist unique webhook event id/type/raw payload hash and protected payload before responding 2xx, and queue durable processing. Invalid signature → 400; storage failure → retryable non-2xx. Repeated valid deliveries return 2xx without duplicate financial credit. Reconcile authoritative Checkout/PaymentIntent status, event/revision linkage, exact amount/currency/account/mode; deduplicate ledger receipts also by payment transaction ID (not just webhook event ID). Handle completed-but-unpaid/asynchronous methods as processing, and grant received status only after verified success. Failed/out-of-order/unsupported/refund/dispute events stay auditable and create reconciliation work rather than inferred payments or silent cancellations.

A Zelle receipt or new financial revision supersedes any stale checkout collection locally and schedules provider expiration; do not perform a remote HTTP request while holding DB locks. If the old checkout has already paid, record the money anyway, show overpayment/reconciliation and stop further collection. The webhook receipt is durable independently of subsequent capacity assignment. GET `/admin/api/payment-exceptions` exposes owner-only pending reconciliation; no silent background refund or customer message.

## Money → capacity bridge for Claude's Phase 1 screen

POST `/admin/api/events/{event}/confirm-paid-capacity`, body `{expected_version, financial_revision_id, resource_ids, review}`. `review` uses the existing RESV-A fields/transfers and explicit short-notice/deposit-exception reasons. Read verified net ledger receipts for the event, assert current accepted financial revision/version, and invoke the existing transactional resource confirmation path under the event/resource locking protocol. Record the ledger entries/amount used in an immutable capacity-payment linkage. No direct client invocation and no separate synthetic receipt counted as money.

Insufficient deposit requires the recorded owner exception; unknown travel fee, missing recipes, impossible staff/costume/driver/speaker/travel combinations still reject. Return `{capacity_status, finance, contract_status}`; on assignment conflict retain all received ledger money and create an owner exception. Retrying a successful assignment uses the original linkage even if later balance payments arrive; no duplicate reservation/evidence or changed initial payment fact. Full confirmation still also needs the current contract signature and accepted commercial revision (D2).

## DOC-01: immutable template and contract revisions

`contract_template_versions`: id, version unique, state (`draft`,`active`,`retired`), locale en (D9), source_html, source_sha256, terms_html, terms_sha256, change_note, created_by, approved_by/approved_at, created_at. An active/used version's source and terms never mutate; edits create a new numbered draft. Activation is an explicit owner action and audit, not an effect of deployment. Import `docs/templates/html/contract-print.html` as the print layout and `docs/contract/contract-template-v1.md` sections 1–10/Entertainment Flow as the legal source; no automatic rewriting/translation of terms or inferred owner text approval. Draft templates cannot issue a signable contract. Tests activate fictitious fixture versions, not a live customer template.

- GET `/admin/api/contract-templates`: versions/state/hash/change note (not arbitrary executable HTML).
- POST `/admin/api/contract-templates`, Idempotency-Key: `{source_version_id?, terms_html, change_note}` creates new draft after controlled HTML/merge-field validation; fixed trusted print layout.
- POST `/admin/api/contract-templates/{version}/activate`, `{expected_source_sha256, approval_note}`: explicit active-owner approval, immutable version, retire previous active choice for **new** contracts only. Issued documents keep their pinned version.

`contracts`: id UUID, event_id, financial_revision_id, event_version, template_version_id, revision, state (`draft`,`issued`,`signed`,`superseded`,`void`), signer_customer_id/email, commercial_snapshot, presented_html_path/hash, presented_pdf_path/hash, final_pdf_path/hash nullable, version, issued_by/at, signed_at, superseded_by, created_at. Artifacts are private immutable bytes with unique paths, never public/storage URLs. Contract changes generate a new revision; no in-place edits to signed/issued content. An issued but unsigned obsolete revision cannot sign after supersession. Signature never changes event dates, prices, ledger or capacity.

POST `/admin/api/events/{event}/contracts`, Idempotency-Key:

```json
{"expected_version":1,"financial_revision_id":1,"template_version_id":1,"client":{"first_name":"Fixture","last_name":"Client","address":"Fixture billing address","email":"client@example.test","phone":"+15555550123"}}
```

Creates a draft snapshot from server event/services/characters, immutable financial revision, actual ledger receipts as of issue and the explicitly supplied adult contracting identity/contact address. Do not split a single customer name automatically or confuse billing and event addresses. Missing/unknown required event, money, included-character or legal fields produce field errors; no `{{placeholder}}`, fake price or guessed terms in a signable PDF. No caller can submit trusted totals, services, received amounts, terms version hashes or timestamps as a substitute for server snapshots.

POST `/admin/api/contracts/{contract}/issue`, Idempotency-Key, `{expected_version}` → immutable presented document hashes/metadata. Current template must be active/approved; re-check event/commercial revision before issuing. Dompdf renders the trusted version of `docs/templates/html/contract-print.html` with escaped merge values and approved terms. Disable remote fetching, JavaScript/PHP execution and arbitrary filesystem access; package local fonts/assets under a controlled chroot. Reject template HTML with unapproved active content/URIs. Keep full document bytes that were actually presented; renderer/model changes do not regenerate an old artifact in place.

GET `/admin/api/contracts/{contract}` → state/version, pinned event/template/financial revisions, hashes, signer/evidence summary and available artifacts; GET `/admin/api/contracts/{contract}/pdf?artifact=presented|final` → authorized private PDF stream; GET `/admin/api/contracts/{contract}/evidence` → owner export manifest. No arbitrary path parameter or unauthenticated download. Owner can preview a draft with a clear draft mark, never report it signed.

## Portal access contract — Claude's client screens

Owner POST `/admin/api/events/{event}/portal-grants` with `{customer_id, expires_at, scopes}` returns a high-entropy revocable link **once**, for manual copying only. Store only token hash in `portal_grants`: event/customer scope, issuer, expiry, revocation, permitted operations and audit. Default scopes `contract.read`, `contract.sign`, `payments.read`, `checkout.create`; no admin/inventory/cost permissions. GET/list APIs never return raw tokens again. No client email/text is sent. Personal URLs are secrets: no logs, analytics, third-party resources or referrer leakage on portal screens.

POST `/portal/api/session` exchanges `{token}` for a limited HttpOnly same-site secure session and CSRF token. The URL landing page should extract the secret and exchange it, then remove it from the visible URL. All subsequent portal operations check grant expiry/revocation and event/customer/object binding on every request; CSRF applies to writes. Revoking/expiring the grant invalidates sessions immediately. A signed URL alone or a client-supplied customer ID is insufficient. This sandbox mechanism identifies the scoped capability's recipient record; **it does not claim email delivery/verification or stronger identity proof**. Any future email/OTP verification is a separate authorized integration.

Portal GET `/portal/api/event` exposes only that customer's event, public service/character description, finance summary, current contract and allowed actions. Portal GET `/portal/api/contracts/{contract}` + `/pdf` returns the scoped issued/signed revision and metadata. No staff pay, internal audit, resource roster, provider payload or other customer's contract. Frontend does not decide eligibility by hiding a button.

POST `/portal/api/contracts/{contract}/sign`, Idempotency-Key:

```json
{"expected_version":1,"presented_pdf_sha256":"<64 hex characters from issued metadata>","signer_name":"Fixture Client","consent":true,"intent_to_sign":true,"consent_version":"esign-consent-v1"}
```

D4 initial signature is typed full name plus explicit unchecked consent/intention controls and access to the complete presented PDF/download; no drawn image is required or treated as sufficient evidence. Backend requires scoped valid client session, current issued revision and matching **exact presented PDF hash**, accepted consent version and deliberate booleans. Name, receipt and device/IP alone do not establish independent identity verification. Capture server UTC timestamp, grant/customer identity, asserted signer name/email, exact consent text/version/hash, source PDF/template hashes, request IP/User-Agent from trusted server request context, and audit. Do not trust browser timestamps/IP/email overrides.

`signature_evidence` is append-only and unique per contract/client signature: presented artifact hash + template version, acceptance fields, server timestamp and access provenance. Lock the contract for concurrent sign/supersede requests; duplicate same-idempotency signature returns its evidence, different acceptance/stale hash returns 409. Never let an admin session impersonate a customer signature.

Return signature receipt immediately after durable acceptance; final-PDF generation can be `pending`/retryable without accepting a second signature. A failure to render final evidence must not lose the accepted signature. Generate final PDF/evidence from the pinned original HTML/PDF and captured acceptance; preserve original presented PDF **and** final PDF with separate hashes. Evidence clearly states the client accepted the presented hash, not the different bytes of the final PDF. Do not embed a final PDF hash into itself. The template's evidence footer must describe the actual access method; manual copied link is not falsely described as email sent/verified. Legal clauses remain unchanged. Provider signoff is a separate explicit owner fact; never print a fabricated Provider signed_at.

Portal GET `/portal/api/contracts/{contract}/signature` returns receipt and final artifact readiness. No email/download delivery job is triggered automatically. Retention/export includes both artifacts, acceptance/evidence, template and commercial revision hashes. Do not expose IP/device evidence publicly to other customers.

## Shared service interfaces (planned)

Resolve via Laravel container; APIs and Filament use the same audited services. `User $actor` is a fresh active confirmed owner; `PortalContext $client` is server-resolved, never hydrated from submitted IDs.

- `Finance\CreateFinancialRevision::execute(User $actor, int $eventId, array $input, string $key): FinancialRevision`
- `Finance\RecordZellePayment::execute(User $actor, int $eventId, array $input, string $key): LedgerEntry`
- `Finance\CreateCheckoutIntent::execute(User|PortalContext $actor, int $eventId, array $input, string $key): PaymentIntent`
- `Finance\ConfirmPaidCapacity::execute(User $actor, int $eventId, array $input): Event`
- `Documents\CreateTemplateVersion::execute(User $actor, array $input, string $key): ContractTemplateVersion`
- `Documents\ActivateTemplateVersion::execute(User $actor, int $templateId, array $input): ContractTemplateVersion`
- `Documents\CreateContract::execute(User $actor, int $eventId, array $input, string $key): Contract`
- `Documents\IssueContract::execute(User $actor, string $contractId, array $input, string $key): Contract`
- `Documents\AcceptSignature::execute(PortalContext $client, string $contractId, array $input, SignatureRequestContext $request, string $key): SignatureEvidence`
- `Portal\IssuePortalGrant::execute(User $actor, int $eventId, array $input): IssuedPortalGrant` (raw secret returned once)

All namespaces are `App\Services\…`; model names under `App\Models` except explicit context/value objects. Exact defaults/errors and implemented routes are updated with each delivery; Claude should not mock an implemented route into production or save these models directly through Eloquent.

## Delivery and verification order

1. Publish this contract/HANDOFF so Claude can prepare screens. No DOC/PAY source code precedes it.
2. Immutable financial revisions and verified external ledger with amount/overpayment/idempotency/concurrency tests; ledger-backed capacity bridge.
3. Versioned template/rendering/private artifact flow; fixture DOMPDF output inspected for legal text, pagination, overflow and source fidelity. Never modify legal terms to make tests pass.
4. Scoped sandbox portal context + consent/hash-bound signature + immutable artifacts/evidence and negative authorization tests.
5. Stripe test adapter/webhook persistence, unique financial credit, timeout recovery/out-of-order reconciliation, no Affirm/live mode. Use fake provider adapter for ordinary tests; a real test-mode endpoint probe only with authorized test credentials and no actual charge/client action.
6. Cross-agent review and sandbox UI acceptance. No deployment, provider live settings or outbound messages by implication. Catalog completion and owner acceptance remain separate prerequisites for real operational use.

Acceptance must cover duplicate/out-of-order webhooks, forged signatures/hash/mode/currency/amount/linkage, two channels paying concurrently, actual partial/over/under payments, Zelle duplicate references, durable money on capacity conflict, stale financial/contracts versions, revoked/expired/cross-client portal access, signature retries/races and presented/final artifact hashes. A database test is not an end-to-end provider proof; record any network/credential/visual limitation explicitly.

## Owner update: parallel work and visible progress

The owner authorizes crossing phase boundaries where dependencies permit (chat, 2026-10-15 America/Chicago). Claude finishes Phase 1 assignment/capacity/calendar and then quick-booking/portal UI; Codex advances the document/payment backend above. This does not authorize live deployment, real charges/messages or invented catalog inputs. An incomplete catalog may block actual package acceptance while skeletons, security, tests and unrelated modules continue.

**Request for Claude:** add a temporary owner-only "Avance del proyecto" tab, without blocking the product work. Use the canonical phases in PROJECT_PLAN §18 (not the older PLAN.md summary), an explicit list of deliverables per phase and documented weights. Show development %, review/merge state, owner acceptance, evidence commit/date, responsible agent, blockers and next task separately. A tested backend does not count as a completed UI or accepted deployment. A contract/design doc alone does not count as an implemented module. Do not enter an arbitrary overall percentage or mark sandbox checks as production readiness. Add/remove scope transparently with a dated explanation; completed/total verified deliverables and the calculation must be visible. Keep the backing progress manifest in Git so both agents update their own evidence and Claude renders the view. No public/customer access or external tracking service needed.
