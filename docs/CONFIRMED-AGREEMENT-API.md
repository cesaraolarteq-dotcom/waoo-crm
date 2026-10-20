# C3 B3a — common confirmed agreement and current TIP link

2026-10-19 Chicago · Codex · API published before code, above C3 B2. No real source access, transfer, provider activation or send. Native and source agreements have different version semantics; never manufacture capacity_payment_links or event.version-1 for an import.

## Internal common basis

`App\Services\Agreements\ConfirmedAgreement::lock(int $eventId):array` requires an existing transaction and acquires the current event row, then checks CRM responsibility. Trusted domain service, not an authorization entry point. Returns `{event:Event,finance:FinancialRevision,origin:crm|honeybook_opening,snapshot_sha256,opening:null|effective-context}`. All mutable proof reads are current. Caller lock order: owner (for owner writes) → event → immutable finance/evidence → grant. Client TIP sessions keep event → grant → session. Whole outer transaction retries MariaDB concurrency errors; never mask them as document corruption.

Native: confirmed event, latest same-event finance, event.version=finance.event_version+1, matching capacity_payment_links and a reservation at the exact event interval. Preserve the existing native TIP fingerprint bytes (event/version/customer/start/end/full financial attributes), so existing native links are not silently invalidated. This does not authorize draft finance, contract signing, portal access or principal checkout.

Imported: use B2 EffectiveOpeningAgreement/OpeningReceiptContext, not the native version rule. Complete effective sealed group and current original customer/source/operations/resources/PDF/financial binding required; actual event.version equals the original bound finance.event_version. Snapshot hash is domain-separated `honeybook_opening:v1` plus the B2 collection hash and exact finance/event identity. It excludes new bank receipts and TIP records; a genuine new payment alone must not invalidate an unchanged tip basis. A changed contact/resource/document/responsibility/finance denies new actions. There is still no activation/materialization/transfer/reversal writer or route.

## Owner read (not a client projection)

`ReadConfirmedAgreement::execute(User $actor,int $eventId):array`, GET `/admin/api/events/{event}/confirmed-agreement`.

Owner2FA, sandbox_finance, local/testing/staging, secure staging transport, no-store/private. Source additionally requires B2 imports+cutover+bridge flags. Lock current owner → event, reauthorize after the owner lock. Result:

```
{
 schema_version:1,event_id,event_version,origin,financial_revision_id,
 snapshot_sha256,currency,total_cents,deposit_target_cents,balance_due_at,
 source_balance_due_date:null|YYYY-MM-DD,
 source_due_precision:null|date|unknown,
 source_commercial_difference_cents:null|integer,
 original_document:null|{sha256,source_contract_signed,owner_attests_signed_original}
}
```

Only the agreed basis/deadline; no computed current balance is claimed by this read. No customer identity/contact/address, bank reference/note, source project ID, private source text, PDF bytes/path, credential, URL or ciphertext. UI pins event/version/finance/snapshot for subsequent TIP controls. Do not use as a client view-model or principal collection permission. Portal, MAIL, checkout, Stripe/refund allocation and forecast adapters remain separate pending deliveries.

## TIP adapter

Existing `TipGuard::source` adopts this common basis. Existing issue/session/read/checkout/revoke routes and request fields remain compatible. Native fingerprint and 14-day post-event window unchanged. Imported TIP requires valid effective source authority plus **known reconciled principal money** (B2 summary not blocked/unknown); unpaid but known balance is not an automatic veto. Original agreed total remains the percentage basis, including an explicitly accepted source difference; no current catalog repricing. TIP money is separate, never credited toward the booking balance. Source HoneyBook responsibility, prepared-only openings, unknown money and corrupt/changed source deny new links or checkout. Native behavior on existing payment exceptions is unchanged.

Issue and revoke owner writes align current owner → event order with manual finance; reauthorize the owner under the lock. Actual late provider-verified tips must still be retained in TipReceipt with reconciliation when current authority changes, never converted to booking ledger or discarded because new action authorization fails. No MAIL generator/IssueTipGrantForMail or automatic send is added here.

## Owner re-copy while current

`RevealTipGrantLink::execute(User $actor,int $grantId,array $input,string $key):array`, POST `/admin/api/tip-grants/{grant}/reveal`.

Closed body `{expected_version:positive-int,expected_snapshot_sha256:64hex}` plus UUID Idempotency-Key. Existing owner2FA/TIP/finance sandbox gates, secure staging transport/origin/CSRF, no-store/private. For imports all source gates/current authority apply too. Lock current owner → event → grant. Reauthorize current source, grant version/snapshot, customer/finance/total, expiry/revocation and encrypted token envelope **before replay**. Result `{id,version,expires_at,link}`; same token, no rotation, renewal, new grant, principal ledger or send. Exact retry only one metadata audit; changed body409; new key may explicitly copy again. Receipt/audit stores IDs/hashes, never the token or URL. Missing/broken envelope, stale source, wrong grant version, expired/revoked link and inactive/unconfirmed owner refuse without exposing the token. Audit failure rolls back receipt; same key can retry safely.

Claude: source TIP UI may use the new read/reveal API after review, but must not assume C3 B or actual switch-over is ready. Preserve primary portal/MAIL/checkout import guards and all source retirement/activation flags off. Test with fictional data only.
