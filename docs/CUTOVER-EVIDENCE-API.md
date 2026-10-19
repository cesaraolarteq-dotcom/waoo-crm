# C3 A — original documents, owner review and frozen readiness evidence

2026-10-19. API before implementation, following C2 (`HONEYBOOK-MONEY-RESYNC-API.md`). This delivery has **no transfer/reversal route, opening financial bridge, sending, charging or production access**. A prepared snapshot is evidence only; `readiness.ready` remains false until later reviewed implementations.

## Access and data

Owner active+2FA, `sandbox_imports` **and separate** `sandbox_cutover` (default OFF), local/testing/staging only, secure HTTPS/session on staging, CSRF for writes, private no-store for all responses. Use source importer singleton to serialize membership. Preflight event hints are not authority: lock existing portal grants before sorted events, then authoritative sorted import links/customer/source/document/review/collection rows; recheck exact membership. TIP rows keep event-first order. Staff grants are never changed. Technical cohort bound:2000 imported projects, matching one source project file limit; larger cohorts fail closed for a later bounded design, never truncate.

Add immutable `cutover_original_documents` (event/link, commercial hash, original SHA256/size, encrypted binary envelope, owner). Never store original filenames, public paths or fake signature rows. PDF upload maximum5MiB, real file, PDF MIME/header, raw bytes encrypted before persistence. No PDF generation/parser execution/download/inline route. Read/prepare verify encrypted artifact checksum; safe errors omit payload. Uploading an original is manual owner evidence, not cryptographic verification of HoneyBook signatures.

Immutable `honeybook_cutover_reviews` hold event/version, link/head/customer versions and complete current binding hash, original document ID/hash, actual source observation time, actor, exact reviewed deadline and owner attestations/note/source reference (encrypted payload). Append versions; never mutate prior evidence. Preserve CSV signed flag separately from owner attestation that the attached original is signed; no CSV field or CRM SignatureEvidence is fabricated. A signed PDF subsequently reviewed by the owner can be retained separately from an older source unsigned flag; presentation must show that distinction.

Immutable `cutover_reconciliations` freeze the complete server-selected IMPORT-01 cohort, member bindings/review versions/document hashes, exact integer source totals, oldest actual source observation, fixed approved240-minute policy and expiry. Payload encrypted+hashed. IMPORT-HISTORY archives never enter this cohort. The implementation's cohort is all imported_project_links, never a browser-selected subset or future-date filter that could hide a party.

## Owner per-event read/write

`ReadHoneyBookCutoverReview::execute(User $actor, int $eventId): array`, GET **`/admin/api/cutover/honeybook/events/{event}`**:

```text
event_id, binding_sha256, expected_version
source:{revision_id,link_version,event_version,customer_version,total_cents,
        gross_received_cents,refund_cents,net_paid_cents,balance_cents,
        balance_due_date,contract_signed,funds_verified:false}
document:null|{id,sha256,byte_count,owner_attests_signed_original:true}
review:null|{id,version,source_observed_at,expires_at,crm_balance_due_at,
             honeybook_automations_disabled,external_collections_retired,current}
missing:[code]                    # no customer names, notes, private source reference or file bytes
```

`SaveHoneyBookCutoverReview::execute(User $actor, int $eventId, array $input, string $key): HoneyBookCutoverReview`, POST same path, multipart or JSON with UUID Idempotency-Key:

```text
expected_version:0 for first, otherwise displayed review version
expected_binding_sha256:displayed current binding
file:UploadedFile PDF OR original_document_id:existing same-event/commercial original
owner_attests_signed_original:true
owner_money_reviewed:true
source_observed_at:explicit-offset timestamp <= now (the actual external review, not page load)
source_reference:5–255 chars, owner-supplied private evidence reference, never fetched
owner_note:5–1000 chars, private
crm_balance_due_at:explicit-offset timestamp|null (required for positive source balance to be ready)
honeybook_automations_disabled:boolean
external_collections_retired:boolean
```

No source amounts, arbitrary event/customer/source versions or responsibility fields accepted. Context comes from the current binding. False retirement flags may be saved as incomplete evidence; neither the CRM nor this checkbox disables HoneyBook. Existing document reuse must match event/link/non-money commercial source hash; new source money requires a fresh explicit review, not re-uploading an old file. Changing contact/event/head/assignment between read/save causes409. Replay restores its immutable review after fresh authorization and checks exact payload/file hashes; it never refreshes observation/expiry. Review audit uses IDs/hashes only. All document/review/audit/receipt writes roll back together.

## Whole-cohort read and preparation

`ReadHoneyBookCutoverReadiness::execute(User $actor): array`, GET **`/admin/api/cutover/honeybook`**:

```text
cohort_sha256, counts:{events,reviewed,documents}, totals:{total_cents,gross_received_cents,
refund_cents,net_paid_cents,balance_cents,credit_cents}, unknowns,
readiness:{ready:false,evidence_ready:boolean,missing:[code]},
reconciliation:null|{id,cohort_sha256,source_observed_at,expires_at,current},
sending_enabled:false,transfer_available:false
```

Source/ledger integrity, original commercial/operational/capacity binding, current review/contact/head/documents, explicit due time for positive balances, actual external collection+automation retirement and current local money/message outcomes are prerequisites. All source funds stay unverified. Expiry uses **oldest member source_observed_at +240min**, never created_at/upload/view/retry. Future observations refused; exactly expired is stale. Unrecognized provider outcomes fail closed. Existing CRM receipts/open payment exceptions, active/unknown booking/TIP attempts, TIP receipts/open reconciliation, sent/dispatching/delivery_unknown mail block evidence readiness; unknown mail statuses do too. No provider calls or cleanup actions.

`PrepareHoneyBookCutoverReconciliation::execute(User $actor, array $input, string $key): CutoverReconciliation`, POST **`/admin/api/cutover/reconciliations`** with `{expected_cohort_sha256,source_scope_reviewed:true}` + UUID. The unchecked owner attestation confirms the external source covers the complete intended HoneyBook operating group; it is not a claim that an unavailable connector queried the account. Require all evidence prerequisites, re-read current state under the same locks, validate private original checksums, append one encrypted immutable complete-cohort snapshot with metadata-only audit and receipt. Same binding under a new key reuses it, without changing expiry or observation. Stale expected hash409, missing prerequisites409. Prepared evidence is not a bank verification or an active financial agreement. Later C3 must revalidate every member/outcome and enforce bridge/transfer prerequisites again.

**Scope completeness amendment (before scope implementation):** also bind all non-discarded fixed-format staged source rows. Unknown non-skipped projects, invalid rows without a project key, or known projects with a staged source hash absent from their immutable revision history block preparation (`source_scope_incomplete`/`source_scope_unresolved`). This catches an uploaded23-row file with only17 committed parties; the other6 cannot disappear from readiness. Historical rows already represented by an immutable revision stay covered after C2 moves the head; unknown intentionally skipped past/cancelled rows stay excluded. Pending cancelled/changed source for an existing party still requires resolution. Capture private scope IDs/version/hash metadata in the encrypted snapshot and one opaque scope hash in the cohort binding, never raw cells. Discarding an unused source batch is explicit owner action, never an automatic readiness cleanup.

Missing codes include `cohort_empty`, `cohort_changed`, `source_integrity_invalid`, `capacity_review_required`, `responsibility_changed`, `original_document_required`, `document_integrity_invalid`, `owner_review_required`, `review_stale`, `source_reconciliation_stale`, `source_due_time_review_required`, `external_collection_not_retired`, `honeybook_automations_active`, `crm_collection_in_flight`, `crm_money_requires_reconciliation`, `tip_collection_or_reconciliation_open`, `message_outcome_unknown`, `freshness_policy_required`. `financial_bridge_not_implemented` and `transfer_not_implemented` always remain in overall readiness; they do not prevent freezing otherwise complete preparation evidence. No declaration that Phase8 is finished.

## Claude

Review contract/source before wiring the real cutover preview. Build event-level original-PDF/review controls and aggregate evidence-only preparation, with displayed versions/hash and stable retry UUID; show stale/incomplete/expired accurately and keep the switch button unavailable. Preserve the policy of240 minutes and current HoneyBook responsibility. Do not mistake the owner's manual attestations for provider automation/verification. No real uploads, reconciliation, transfer, messages or charges are authorized by this synthetic delivery.
