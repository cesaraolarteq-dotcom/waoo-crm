# C3 B — refund evidence and allocation S2 (next delivery)

2026-10-20 · Codex · contract before implementation. Depends on OPENING-STRIPE-API.md S1. **Design only: no refund service/route/transport or balance effect delivered here.** S1 keeps every refund pending. No refund initiation, source activation or deployment authorized.

## Evidence, not permission to return money

Reconcile an **already succeeded** test-mode Stripe refund of an allocated post-cutover CRM receipt. Original HoneyBook payments have no independently verified CRM parent receipt: refunds against them stay unknown pending a separate source-bank bridge. TIP has its own ledger and cannot be a parent. No negative receipt, browser return, owner-entered gross or fabricated historic receipt can replace provider evidence. This creates no new commercial refund policy.

A separate read-only `StripeRefundGateway::retrieveRefund(string $refundId): array` will return a verified account, refund and charge/payment binding. Extend the existing signed webhook allowlist/router for refund.created/refund.updated, without an unsigned endpoint or retaining card/billing data. `CaptureOpeningStripeRefund::execute(string $webhookReceiptId): void` retrieves provider state and verifies account using test credentials, never trusting metadata alone.

Require succeeded status, USD, positive actual amount, nonfuture creation time >= parent payment/effective transfer; paid/captured charge bound to the same payment/account/S1 receipt. Pending/failed/canceled/unlinked events retain private observations/review reasons, never a successful refund ledger row. A success appends immutable encrypted evidence and one positive kind=refund/method=stripe ledger movement; unique account/refund identity deduplicates callbacks. Actual verified late refunds survive changed authority, without automatic balance allocation or message. No provider write capability.

Freeze explicit V1 fields for refund, sanitized webhook, parent S1 evidence, amount/time/account/currency/status and canonical ledger. Unique ledger FK and parent receipt FK; encrypted payload/hash, no unrelated ORM columns. Unknown schemas, corrupt/transplanted proof, ambiguous source, wrong parent/event/finance/account or gross above paid amount remain pending. Populated destructive rollback refused. Existing successful evidence is not rewritten by later callbacks; conflicting observations stay auditable.

## Owner API (proposed, not registered)

`ReadOpeningRefundCandidates::execute(User $actor,int $eventId,array $filters=[]): array`

GET `/admin/api/events/{event}/opening-refunds`: owner2FA, secure sandbox finance/imports/cutover/bridge gates; closed page/per_page1..50, clamped, volume refusal. One displayed snapshot; no provider call/write from GET.

Output `{opening_id,expected_collection_sha256,collection_current,missing,page:{number,last,total},rows:[{ledger_entry_id,parent_ledger_entry_id,gross_refund_cents,received_at,allocated,eligible,issue_codes,refund_sha256,parent_remaining_principal_cents,parent_remaining_customer_fee_cents}]}`. Unknown values null; no provider/bank reference, contact/source private detail or ciphertext.

`AllocateOpeningRefund::execute(User $actor,int $eventId,int $ledgerId,array $input,string $key): array`

POST `/admin/api/events/{event}/opening-refunds/{ledger}/allocate`, closed `{opening_id,expected_collection_sha256,expected_refund_sha256,parent_ledger_entry_id,principal_refund_cents,customer_fee_refund_cents,refund_classification_reviewed:true}` + stable UUID. Amounts nonnegative integers summing exactly to **verified actual gross refund**: classify a known refund, never replace its gross/time/account/parent. Fee-free parent requires all principal, fee0. Otherwise explicit owner classification; never infer3% or proportional split. This is not authorization to issue a refund.

Fresh complete effective authority/current collection pin and parent binding required, including retries. Existing owner→event→parent/receipt lock order, cumulative principal and fee caps independently, and total succeeded gross refunds <= parent paid gross. Unique allocation per ledger, same key/body reuses after fresh authorization, changed body/pins409. Audit+allocation+mutation receipt atomic; failure leaves the existing real refund unallocated. Stale remaining-budget snapshot requires review rather than silently adjusting the split. No reassignment to another event/revision/parent, destructive evidence replacement or override of uncertain source money.

Response `{allocation_id,ledger_entry_id,parent_ledger_entry_id,opening_id,gross_refund_cents,principal_refund_cents,customer_fee_refund_cents,received_at}`. Safe reason codes only, not provider bodies.

## Balance and required verification

Subtract allocated principal refunds from new CRM principal; report customer-fee refunds separately, never as sales/deposit. Preserve source gross/refund/net separately and bank-unverified. Unallocated/invalid/unsupported refunds keep combined balance/credit unknown and collection blocked; known allocated components remain labelled. Never erase real money to obtain a convenient numeric balance. Native finance/TIP unchanged.

Synthetic tests required: signed callback + retrieved success; pending/failed/account/parent/currency/live/amount/time failures; duplicate callbacks and real independent concurrent partial-refund cap races; principal/fee attribution and fee-free path; replay/current authority/stale pins; corrupt/transplanted/future schema; atomic failure/retry retaining actual refund; unrelated schema columns; old source-parent refusal; native/TIP routing regressions; no provider writes/sends/real import/activation. Claude can review the contract now; UI implementation waits for frozen services and actual output tests. Design earns no implementation progress.
