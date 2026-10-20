# C3 B3b — read-only original-agreement client portal

2026-10-19 Chicago · Codex · publish before code. Stacked on common-agreement/TIP B3a. No materialization/activation/transfer/reversal writer, real import, deployment, send, payment or HoneyBook mutation.

## Source-only grant, owner action

`IssueOpeningPortalGrant::execute(User $actor,int $eventId,array $input,string $key):array`, POST `/admin/api/events/{event}/opening-portal-grants`.

Closed input `{customer_id,expected_event_version,financial_revision_id,expected_snapshot_sha256,expires_at}` + UUID Idempotency-Key. Positive IDs/version,64hex snapshot, explicit-offset expiry after now and <=30days. Owner2FA + documents/finance/imports/cutover/bridge sandbox gates, secure staging transport/CSRF/no-store. Requires B3a current original effective agreement (not prepared-only or enum/flag flip), exact customer/event version/finance/snapshot. No amount/source balance/contract/signature/scope override. Fixed scopes **['agreement.read'] only**; cannot sign, submit client details, accept quotes, report Zelle or create checkout. Native IssuePortalGrant import guard remains closed.

Migration480000 adds nullable `portal_grants.cutover_financial_binding_id` FK (restrict delete) and nullable `opening_snapshot_sha256`; model guards/hides them by default. Both are set together only by this service. A separate immutable encrypted `opening_portal_bindings` registry (grant PK/FK, source binding FK, protected complete grant facts/hash) retains source classification even if clear grant markers or its recovery envelope are stripped. Current facts must equal the registry, not only a mutable marker. PortalTokenVault v3 authenticates these plus the complete existing grant/token envelope; native v1 and quote v2 bytes remain compatible. Populated down refuses destruction of source-grant evidence. No original money enters a new ledger/contract/CRM signature.

Lock protocol: current owner SH → request receipt → existing grant (on retry) → event → source evidence/customer. Use SH owner lock for fresh authorization without inverting the existing portal grant→event protocol or the finance owner→event protocol. Existing-grant replays lock that grant before event, never event→old grant. Complete outer transaction retries concurrency errors. Exact replay revalidates current grant/source/envelope and returns the same token/expiry; it never renews an old expiry. Audit/receipt contains IDs/hash only. No MAIL occurrence or outbound job. Response `{id,event_version,financial_revision_id,expires_at,scopes,link}`; link uses existing `/portal#token=...` exchange. Existing owner reveal/revoke routes remain compatible, but reveal checks source authority/envelope afresh. Active source-grant replay/reveal denies changed original binding/contact/PDF, wrong responsibility, expiry or revocation.

## Session and client scope

Existing POST `/portal/api/session` classifies source grants using both registry and markers, checks the presented token again under the grant lock, and validates the source/current sealed agreement/envelope transactionally (grant→event) before storing a session. Source sessions additionally pin binding ID and snapshot; native/quote exchanges clear those source pins. This prevents source metadata from being stripped or repurposed to downgrade an old source token into a native session. Existing native/quote exchange behavior stays compatible. Every source read independently revalidates grant/customer/event/binding/snapshot/scopes/envelope, effective authority and current original evidence under the same grant→event protocol. Source scopes are exactly agreement.read; reject all native contract/payment/details/quote scopes even if a row is tampered. No event/document ID from a client chooses a source record.

GET `/portal/api/original-agreement`, `ReadOpeningPortalViewModel::read(PortalContext $client,string $lang='es'):array`:

```
{
 schema_version:1,kind:imported_agreement,lang:es|en,test_mode:true,
 first_name,
 event:{title,starts_at,ends_at,timezone,address},
 agreement:{origin:honeybook,package_description,characters_description,currency,
   package_cents,travel_fee_cents,discount_cents,original_total_cents,
   original_difference_cents,
   document:{sha256,source_contract_signed,owner_attests_signed_original,pdf_url}},
 money:{state:known|review_required,recorded_paid_cents:null|integer,
   balance_cents:null|integer,credit_cents:null|integer,
   reviewed_due_at:null|ISO,source_due_date:null|YYYY-MM-DD,source_due_precision:date|unknown},
 actions:{can_sign:false,can_checkout:false,can_submit_details:false,can_report_zelle:false}
}
```

Allowlist projection only: own event/customer first name and original service descriptions, agreed components/total, original document attestations/hash and B2 recorded money. No source-project/internal finance/binding/ledger/staff/resource IDs, bank refs/methods/notes, owner notes, phone/email/full customer name, PDF path/ciphertext, signature IP/user-agent or token. Never current catalog repricing or fabricated source-fee classification. Original discrepancy is total-(package+travel-discount), explicitly labelled as an original difference. Source payments remain recorded source facts, not invented bank-verified transactions.

B2 unknown/unallocated/refund/Stripe/corrupt money produces null net/balance/credit and review_required. Known numbers stay known when a review exception exists. No new deposit target/demand, zero fallback, sign prompt or payment link. Nullable paid-full deadline remains null; original date-only precision and separately owner-reviewed exact CRM instant remain distinct. No synthetic CRM SignatureEvidence or native Contract.

Existing `/portal` dispatches an authenticated source grant to this projection and a minimal read-only EN/ES page using the shared layout (Claude can polish it). Existing landing exchange keeps the token in #fragment only and removes it from history. Source page exposes original PDF download and recorded amounts; no technical IDs or write forms. Invalid/revoked/stale source shows a generic unavailable page, not another customer's or a partial legal/money projection. Native and quote page behavior stays compatible.

## Exact original PDF

GET `/portal/api/original-agreement/pdf`, `ReadOpeningPortalDocument::read(PortalContext $client):string`. Same current source authorization, fixed original document from the sealed opening only; no browser-selected document/contract/path. Decrypt and verify size/hash/header under lock and return those **exact** bytes. Constant attachment filename original-agreement.pdf; application/pdf, no-store/private, no-referrer, nosniff, restrictive sandbox CSP; no public path, static link, ETag cache or regenerated dompdf copy. A missing/corrupt original refuses without leaking bytes. This read creates no native contract presentation or signature evidence and does not turn an owner attestation into cryptographic HoneyBook signature verification.

Claude: review backend, then refine the EN/ES page against this allowlist and wire owner issuance/re-copy using displayed common-agreement values and stable UUID. Keep read-only controls and unknown amounts explicit. Primary source checkout/Zelle write adapters, MAIL, Stripe/refunds, reports and actual whole-group switch remain pending. No client sends or activation before separate owner approval.
