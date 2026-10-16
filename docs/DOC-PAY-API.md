# DOC-01 / PAY-01 / PAY-02 — data and API contract for Claude

2026-10-15 America/Chicago · Codex · owner authorized this parallel increment in chat while Claude finishes Phase 1 and then QUICK-BOOKING/portal UI.

**Status: the contract was published before implementation. The sandbox backend routes/services below are now delivered; customer/admin UI, live activation and owner acceptance remain separate.** Codex owns backend/schema/integrations; Claude owns screens and reviews. Each delivery will mark which routes are implemented. No live deployment, live Stripe requests, actual charges or client messages are authorized. Catalog gaps do not justify invented prices, recipes or contract terms. This increment does not close Phase 1 or authorize the rest of Phase 4.

## Delivered increment: sandbox Zelle and finance (codex/sandbox-doc-pay)

Implemented after this contract was published: financial revision creation, event finance summary, payment list, verified Zelle registration, payment-exception list and ledger-backed confirm-paid-capacity routes. Sandbox flags default false and the backend rejects production regardless of flag. Eight finance tests plus the Phase 1 adapter tests bring the complete suite to **122 tests / 886 assertions on MariaDB**. The later document/portal/Stripe delivery below implements those routes; the original dependency installation alone was not implementation. A fixture-tested ledger is not a real bank/provider reconciliation proof.

Financial revision replacement is intentionally restricted to drafts in this increment; booked financial changes await CHANGE-A. No refunds/adjustments API or quote-acceptance workflow is claimed. The initial capacity linkage is immutable and reused after later balance receipts, so retry does not rewrite deposit evidence or count it as another payment. Current legacy confirm-capacity stays separate from the ledger adapter; no automatic import of its receipt evidence.

## Delivered increment: private contracts, portal acceptance and test Checkout

2026-10-15 America/Chicago · `codex/sandbox-doc-pay`. **Complete PHP 8.3.35 / MariaDB 11.8 suite: 146 tests / 1123 assertions passed.** The twelve document tests exercise real dompdf output, exact artifact hashes, scoped HTTP sessions, real staging CSRF/HTTPS requirements, signature concurrency and PDF recovery. Ten Stripe tests use a fake gateway plus the real SDK's webhook-signature verifier; they cover deduplication, binding/amount/mode/account rejection, unpaid/out-of-order events, request timeout recovery, retried worker processing and actual two-process Zelle/Stripe receipts. These are not an actual Stripe-account/Checkout end-to-end probe.

Compatibility follow-up: merged Claude admin-screens **a9f2996** (including corrected money UI 3279591) on integration **41d8260**. Complete merged suite: **179 tests / 1482 assertions pass**, plus Vite production build. The standalone source delivery remains 0cd7f5a; the integrated count does not claim a real provider or owner acceptance.

All listed template, contract, grant, portal, Checkout and webhook routes are implemented. The frontend `/portal` landing page and `/portal/payment-return` remain **Claude's UI routes**, not delivered HTML pages. Do not use copied links with a customer before those pages, legal approval, cross-agent review and owner-authorized staging acceptance are complete. No actual Waoo legal terms have been activated automatically. Tests activate fictitious terms only. This version renders the existing **English** print layout; Spanish portal explanations and approved legal translations are not implied by backend delivery.

Financial snapshots now also freeze service name, duration, resource recipe and selected character names/quantities for document rendering. Older financial revisions lacking these descriptions are refused for contract creation; create an explicit new financial revision through the owner service/API rather than backfilling or rewriting old agreements. Only drafts accept financial replacement; CHANGE-A, amendments, refunds/adjustments, provider countersignature, automated messages and scheduled provider reconciliation are not implemented here. `void` is a reserved contract state, not an available mutation endpoint. Drafts have JSON metadata; **no draft PDF preview endpoint** is delivered.

Required configuration (names only): `WAOO_SANDBOX_FINANCE=true` and `WAOO_SANDBOX_DOCUMENTS=true` in authorized local/testing/staging. Production is denied even with both true. Document creation/issue uses financial summaries and therefore needs both flags. Stripe additionally needs secure environment bindings for `STRIPE_TEST_SECRET`, `STRIPE_TEST_WEBHOOK_SECRET`, `STRIPE_TEST_ACCOUNT`; the adapter permits test keys only and verifies the authenticated account. Do not send secrets in chat or add them to Git. No real provider request was made in this delivery.

Workers must use the existing durable database queue/Hostinger bounded cron worker. Signature capture and verified webhook intake commit their durable record before dispatch-after-commit; final PDF generation and provider reconciliation retry separately. Configure HTTPS, `APP_URL` and `SESSION_SECURE_COOKIE=true` before portal staging acceptance. Personal links and return URLs use trusted APP_URL, never the browser Host header. Tests intentionally use fake queues and explicitly execute the relevant jobs; deployment/cron operation was not repeated.

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

POST `/webhooks/stripe` is CSRF-exempt **only for this exact route**, signature required over raw bytes using the dedicated test endpoint secret. Verify mode/account, persist unique webhook event id/type/raw payload hash and encrypted minimal allocation facts (event/object IDs and allowlisted linkage metadata) before responding 2xx, and queue durable processing. Invalid signature → 400; storage failure → retryable non-2xx. Repeated valid deliveries return 2xx without duplicate financial credit. Reconcile authoritative Checkout/PaymentIntent status, event/revision linkage, exact amount/currency/account/mode; deduplicate ledger receipts also by payment transaction ID (not just webhook event ID). Handle completed-but-unpaid/asynchronous methods as processing, and grant received status only after verified success. Failed/out-of-order/unsupported/refund/dispute events stay auditable and create reconciliation work rather than inferred payments or silent cancellations.

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

GET `/admin/api/contracts/{contract}` → state/version, pinned event/template/financial revisions, hashes, signer/evidence summary and available artifacts; GET `/admin/api/contracts/{contract}/pdf?artifact=presented|final` → authorized private PDF stream; GET `/admin/api/contracts/{contract}/evidence` → owner export manifest. No arbitrary path parameter or unauthenticated download. Draft JSON can be inspected by the owner; PDF download requires an issued or final artifact. Draft PDF preview is not implemented.

## Portal access contract — Claude's client screens

Owner POST `/admin/api/events/{event}/portal-grants` with `{customer_id, expires_at, scopes}` returns a high-entropy revocable link **once**, for manual copying only. Response is `{id, url, token, expiresAt}`; `url` uses `/portal#token=…`, not a query-string secret. `expires_at` must have an explicit offset, be future, and at most 30 days away. Store only token hash in `portal_grants`: event/customer scope, issuer, expiry, revocation, permitted operations and audit. Default scopes `contract.read`, `contract.sign`, `payments.read`, `checkout.create`; no admin/inventory/cost permissions. GET/list APIs never return raw tokens again. No client email/text is sent. Personal URLs are secrets: no logs, analytics, third-party resources or referrer leakage on portal screens.

POST `/portal/api/session` exchanges `{token}` for a limited HttpOnly same-site secure session and CSRF token. The URL landing page should extract the secret and exchange it, then remove it from the visible URL. All subsequent portal operations check grant expiry/revocation and event/customer/object binding on every request; CSRF applies to writes. Revoking/expiring the grant invalidates sessions immediately. A signed URL alone or a client-supplied customer ID is insufficient. This sandbox mechanism identifies the scoped capability's recipient record; **it does not claim email delivery/verification or stronger identity proof**. Any future email/OTP verification is a separate authorized integration.

Portal GET `/portal/api/event` requires `payments.read` and exposes `{event, services, finance, contract}`: only that customer's event, allowlisted service/character descriptions from the financial snapshot and the finance summary. `contract` is the newest issued/signed contract metadata only when the grant also permits `contract.read`, otherwise null; no resource recipes or artifact filesystem paths. Permitted scopes come from the session exchange; write services still decide eligibility. Portal GET `/portal/api/contracts/{contract}` + `/pdf` returns the scoped issued/signed revision and metadata. No staff pay, internal audit, resource roster, provider payload or other customer's contract. Frontend does not decide eligibility by hiding a button.

POST `/portal/api/contracts/{contract}/sign`, Idempotency-Key:

```json
{"expected_version":2,"presented_pdf_sha256":"<64 hex characters from issued metadata>","signer_name":"Fixture Client","consent":true,"intent_to_sign":true,"consent_version":"esign-consent-v1"}
```

D4 initial signature is typed full name plus explicit unchecked consent/intention controls and access to the complete presented PDF/download; no drawn image is required or treated as sufficient evidence. Backend requires scoped valid client session, current issued revision and matching **exact presented PDF hash**, accepted consent version and deliberate booleans. First GET the presented PDF through this same grant: the server records its contract/grant/hash presentation receipt. This is evidence of serving the PDF, not proof the person read it. The signature references that receipt; no browser-submitted IP, email or timestamp is trusted. Name, receipt and device/IP alone do not establish independent identity verification. Capture server UTC timestamp, grant/customer identity, asserted signer name/email, exact consent text/version/hash, source PDF/template hashes, request IP/User-Agent from trusted server request context, and audit. Do not trust browser timestamps/IP/email overrides.

`signature_evidence` is append-only and unique per contract/client signature: presented artifact hash + template version, acceptance fields, server timestamp and access provenance. Lock the contract for concurrent sign/supersede requests; duplicate same-idempotency signature returns its evidence, different acceptance/stale hash returns 409. Never let an admin session impersonate a customer signature.

Return signature receipt immediately after durable acceptance; final-PDF generation can be `pending`/retryable without accepting a second signature. A failure to render final evidence must not lose the accepted signature. Generate final PDF/evidence from the pinned original HTML/PDF and captured acceptance; preserve original presented PDF **and** final PDF with separate hashes. Evidence clearly states the client accepted the presented hash, not the different bytes of the final PDF. Do not embed a final PDF hash into itself. The template's evidence footer must describe the actual access method; manual copied link is not falsely described as email sent/verified. Legal clauses remain unchanged. Provider signoff is a separate explicit owner fact; never print a fabricated Provider signed_at.

Portal GET `/portal/api/contracts/{contract}/signature` returns receipt and final artifact readiness. No email/download delivery job is triggered automatically. Retention/export includes both artifacts, acceptance/evidence, template and commercial revision hashes. Do not expose IP/device evidence publicly to other customers.

## Shared service interfaces (implemented)

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

## Claude integration sequence and exact extra routes

1. **Owner templates:** GET `/admin/api/contract-templates` lists metadata; GET `/{template}` also returns `source_html` and `terms_html` for owner review. POST `/admin/api/contract-templates` with Idempotency-Key and `{terms_html, change_note, source_version_id?}` creates an immutable draft version from the repository print layout. Terms accept basic HTML only: no attributes, scripts, external assets, styles, links, template placeholders or entity declarations. POST `/{template}/activate` with `{expected_source_sha256, approval_note}` activates that exact version; previous active version retires, already-issued documents keep their pinned version. No autoactivation/import of the draft legal text.
2. **Owner event:** create the financial revision, then POST the contract draft with the explicitly entered adult identity and billing address. Contract email must match the event's customer email; event address and character selections must be complete. Issue with the contract's own `version` (initial draft 1), not the event version; issue returns version 2 and presented hashes. Read current values from the response, never assume the example numbers. Show payment, resources and contract as independent states. Signed replacement requires future CHANGE-A/amendments; no silent overwrite.
3. **Owner access:** create a scoped grant and show/copy the returned link once, no email/SMS. POST `/admin/api/portal-grants/{grant}/revoke` revokes immediately. Do not persist the raw secret in frontend analytics, logs or localStorage.
4. **Portal bootstrap:** Claude's `/portal` page extracts the token from the URL fragment and immediately calls `history.replaceState` to remove it before loading third-party resources. GET `/session/csrf` with same-origin cookies, then POST `/portal/api/session` with `{token}` and the CSRF header. Exchange regenerates the session and rotates CSRF; use the returned `csrf_token` for later writes and stop retaining the raw token. Do not exempt this exchange from CSRF. Staging requires HTTPS plus a secure session cookie. Admin sessions cannot submit a customer signature; test the customer path in a separate logged-out/private session.
5. **Portal display/sign:** GET `/portal/api/event` discovers current contract ID and amounts (default grant has all four scopes). GET `/portal/api/contracts/{id}` returns `{contract, consent, final_pdf_ready}`; show full presented PDF through `GET /pdf?artifact=presented` (default), then unchecked consent/intention and typed name. Use the returned consent text/version and PDF hash verbatim. POST `/sign` with a UUID Idempotency-Key; retain key and exact payload across a timeout. Stale/superseded document/hash or changed idempotency payload returns 409; reload instead of signing another version automatically. A read-only `contract.read` grant can open PDF but cannot sign or read money.
6. **Durable receipt:** successful sign returns `{signature, final_pdf_ready}`. GET `/signature` polls readiness without creating a new signature. Download `GET /pdf?artifact=final` only when ready; otherwise 404 is expected. Final PDF preserves the presented HTML content and appends a separate acceptance certificate; original PDF bytes/hash remain independently stored. Its different final hash is not claimed as the originally accepted hash. Provider acceptance stays pending; no countersignature or email delivery is fabricated. Owner POST `/admin/api/contracts/{id}/final-pdf/retry` with `{reason}` (10–2000 characters) requeues the worker without resigning; owner GET `/evidence` exports pinned metadata, hashes and evidence (IP/device stay owner-only).
7. **Optional test Checkout:** owner POST `/admin/api/events/{event}/checkout-intents`, or portal POST `/portal/api/checkout-intents`, with financial revision/purpose and Idempotency-Key. The portal event comes only from its grant. Responses are a flat intent DTO with `checkout_url` only while open. Poll owner GET `/admin/api/payment-intents/{id}` or scoped portal GET `/portal/api/payment-intents/{id}`; a Stripe return page is informational and never credits money. Initial allowlist is card only, no Affirm and no 3% surcharge. Before any real operation, the credit/debit/prepaid and fee rules remain owner/configuration prerequisites.
8. **Owner reconciliation:** GET `/admin/api/stripe-webhooks` lists state/attempts/last_error, not stored payload; POST `/{receipt}/retry` with `{reason}` requeues the verified receipt for authoritative reconciliation and audit. This does not bypass provider validation or manufacture a receipt. GET `/admin/api/payment-exceptions` lists pending capacity/payment issues; refunds, adjustments, manual exception-resolution workflow and a daily provider sweep are future work. Unsupported/unlinked provider events are retained as reconciliation/ignored records, never inferred payments. A Zelle superseding Checkout schedules expiration after commit; any late provider success still records the actual money and exposes overpayment.

The existing owner Finance* APIs remain compatible with Claude's d3fab7b money page. Carry money-screen correction `3279591` from `codex/review-admin-money` before merging screens. That correction pins the displayed event version and allows a new financial revision after draft edits, without changing receipts or old revisions. Preserve migration 140000's index-before-column rollback. Migrations 160000/170000 add documents/portal/Stripe tables; 160000 widens polymorphic audit subject IDs to retain UUID subjects. Its down migration deliberately leaves audit IDs wide rather than destroy history.

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


## Approved DOC/PAY follow-ups (2026-10-16)

See [DOC-PAY-FOLLOWUPS.md](DOC-PAY-FOLLOWUPS.md): closed three-field legal merge allowlist, audited nullable catalog long_description copied into new financial snapshots, friendly English PDF labels, and explicit-owner `Portal\RevokePortalGrant::execute(User, int): PortalGrant`. Existing revoke endpoint/response is unchanged. QuickBook and SubmitClientDetails are delivered separately per [BOOK-QUICK-API.md](BOOK-QUICK-API.md); real details/review screen wiring remains pending. Legal text edits/activation still need owner approval.
