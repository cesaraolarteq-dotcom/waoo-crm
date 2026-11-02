# C3 B — verified Stripe principal allocation, delivery S1

Status: sandbox contract, before implementation. Parent: CUTOVER-RECEIPTS-API.md and INTEGRITY-SCHEMAS-API.md. No activation, source checkout permission, provider operation, fee activation, refund operation or outbound message is added.

## Capture and evidence

After the existing signature + provider Checkout/payment verification succeeds, the processor records an immutable encrypted `opening_stripe_evidence` row for a **new** ledger receipt belonging to an imported project. This capture does not require valid/current cutover authority: real authenticated late money must survive corrupt/missing authority and still require reconciliation. Capture is atomic with ledger/audit/processed webhook. Native receipts are unchanged. No backfill from a duplicate webhook, owner checkbox, payment-return URL or browser totals.

Schema V1 freezes explicit fields: ledger id and canonical EvidenceV1 ledger hash; local intent id/event/finance/environment/purpose/currency/principal/customer fee/payable and provider session/payment ids; authenticated webhook id/type/raw hash/account/verified UTC time and a hash of its sanitized stored payload; verified provider account/session/payment, captured receipt time and gross/principal/customer fee. No card/billing/contact/raw provider object is stored. The envelope uses authenticated encryption and a payload SHA-256; references are private and omitted from owner/client projections. Unrelated future columns and mutable intent status/collection slot/expiry do not enter proof. The processor never manufactures original HoneyBook funds or original receipt evidence.

Migration 500000 creates the table, unique ledger FK and encrypted payload. No old receipt/amount/token/authority is changed. Empty rollback supported; populated rollback refuses evidence destruction. Legacy source Stripe receipts without this capture remain pending explicit reconciliation; no synthetic proof is minted.

## Existing owner API, additive output

Use the existing ReadOpeningReceiptCandidates / AllocateOpeningReceipt and their routes, owner+2FA+finance/import/cutover/bridge gates, stable Idempotency-Key, displayed opening/collection/receipt SHA pins and explicit post_cutover_received decision. There is no new input, amount, fee, refund or proof override.

A Stripe candidate is eligible only with a valid immutable capture bound to the same ledger, verified webhook and local intent; test/USD/verified account; paid principal receipt, exact gross = principal + customer fee; positive principal and nonnegative fee within existing limits; received_at at/after effective transfer and no later than now; event/finance/source identity match. Missing/corrupt/retargeted evidence stays ineligible. Inspecting or retrying never retrieves Stripe or changes evidence. New proof failures retain `stripe_allocation_pending` and add `stripe_evidence_invalid` or `stripe_evidence_required` (no private provider data). No refund is made eligible by this delivery.

Candidate rows add nullable `principal_cents`, `customer_fee_cents` (Zelle = amount/0; invalid Stripe = null/null). Existing amount_cents remains actual gross. Newly allocated Stripe payloads have explicit schema_version=2, gross amount_cents, principal_cents, customer_fee_cents and stripe evidence hash, alongside existing fields. Existing implicit-V1 Zelle bytes/hash/results remain unchanged. Unknown versions and changed protected evidence fail closed; retry never re-seals. Allocation response adds principal/customer fee only for V2.

Opening summary counts allocated **principal** against the original balance, never the card fee. `crm_received_cents` and existing received/net/balance remain principal values; additive `crm_customer_fee_cents` reports known allocated customer charges separately, never sales or a second deposit. Source gross/refund/net remain separate and bank-unverified. Any unallocated/invalid receipt/refund leaves combined totals unknown and collection blocked. Existing native checkout still has zero fee; this adapter does not configure a 3% charge or approve surcharge disclosure/card-network compliance.

## Refund boundary / next contract

Refund rows remain ineligible and keep combined balance unknown. S2 must publish an authenticated refund capture, unique account/refund identity, parent receipt allocation, succeeded status, explicit principal-versus-fee attribution and cumulative partial-refund caps before changing balances. Refund of old HoneyBook funds needs separate reviewed source evidence and must not invent a new CRM parent receipt. No refund inference from a negative amount, note or owner checkbox. No provider refund initiation in S1/S2 evidence adapters.

## Verification / Claude

Synthetic signed webhooks through the real controller + fake provider, no actual provider calls. Test explicit allocation/replay, fee separation, duplicates, integrity/authority/time/account/amount failures, evidence rollback, legacy Zelle compatibility and native Stripe regressions. No real import, provider operation, send, activation or deployment.

Request for Claude: review S1 when frozen; candidate UI must distinguish gross from balance credit and never expose provider references. Keep activation/payment controls off. This contract is one partial C3 B delivery, not completed refunds or migration.
