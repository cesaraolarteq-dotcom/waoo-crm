# C2/C3 — concrete source update and financial bridge contract (draft v2)

2026-10-19 · API/data design before implementation. Supplements the approved `RESPONSIBLE-SYSTEM-API.md`; **no C2/C3 code, migration, switch or implementation credit**. Prepared during TIP core verification. Claude may review this shape before building the cutover screen. César approved a 240-minute maximum reconciliation age on 2026-10-19; C3 enforcement remains unimplemented and no transfer is authorized by that policy choice.

## C2: use the existing importer and preserve confirmed operations

Existing `CommitHoneyBookEvent` updates only unreserved drafts and rejects past starts before reaching its update branch. A complete C2 implementation must move the future-start restriction to **new imports**; a reviewed update of a known project may contain money received after the party. No creation of a past operating event. Keep source project identity, link/customer/event IDs, all original revisions and source payment positions. HoneyBookFutureSource also marks past rows skipped during profiling: extend that staging classification only for server-identified existing project keys, retaining the original past reason. A client cannot unskip an unknown past row or create a past event by submitting resync=true. Revalidate the known link under the importer lock at commit.

Proposed read: `App\Services\Imports\ReadHoneyBookProjectDiff::execute(User $actor, int $batchId, int $rowId): array`, GET `/admin/api/imports/honeybook/{batch}/rows/{row}/diff`. Owner/2FA/import sandbox/HTTPS/private no-store as the importer. No source file paths, tokens or private notes in the response. Only explicitly allowed owner-visible money/source-date values; other changed fields are codes requiring separate review.

```text
batch:{id,version}, row:{id,version,preview_sha256}
current:{link_id,link_version,revision_id,event_id,event_version,customer_id,customer_version,
         source_sha256,operations_sha256,responsible_system}
kind:new|unchanged|money_only|blocked
changes:[{field,from,to}]                  # only paid, balance, payments summary, due-date precision
blocked:[{field,code}]
source_difference:{can_retain,revision_id,commercial_sha256}|null
diff_sha256                               # binds every displayed selection/version/decision
```

POST continues to use the **existing row commit route and service**, adding a closed `resync` object for the confirmed-event update branch: `{expected_link_version,expected_revision_id,expected_event_version,expected_customer_version,expected_diff_sha256,owner_money_reviewed:true,owner_note,retain_source_difference_revision_id?}`. All displayed batch/row/preview versions remain mandatory. No bulk guessed mapping or browser-chosen new event/customer identity. If both legacy top-level and nested versions are supplied they must agree. On success the UI must distinguish review/update of an existing confirmed event from creation of a new draft; it must not ask to reserve resources again just because the shared commit route succeeded. Existing UIs without this object must receive a review-required conflict, not a silent confirmed-event update. Ordinary initial/draft import behavior remains separate inside the same entry point.

`money_only` permits reviewed source `amount_paid`, `balance`, full `payments` snapshot and `balance_due_date` changes, provided all contract/commercial/schedule/status/contact/source-service fields and the frozen CRM operational selection are unchanged. Changed date, time, address, duration, package, character/outfit, amount agreed, discount, source status or contact returns a precise blocked field code. CHANGE-A handles actual operational/contract changes. Raw source notes never become staff instructions. Source decreases/refunds are reviewed facts, not fabricated negative bank receipts.

Compare the original mapping and current immutable service/assignment snapshot; do not rebuild requirements, prices or labels from today's catalog. A retired/edited catalog entry does not invalidate an otherwise unchanged historical agreement solely for a money update. Do not call SaveEventDraft, SaveCustomer, ConfirmCapacity or re-reserve resources in the confirmed money branch. A changed CRM customer version requires review, not copying old CSV contacts over it.

A repeated identical source/mapping is a no-op, even with a new request key: no head/version/audit-money duplication. A changed source appends one complete ImportedProjectRevision and its complete ImportedLedgerLine snapshot, then moves the link head/version. Finance reads sum **only that head's** source lines, not all historical revisions. Keep prior BookingEvidence, reservations and operating event version unchanged; the original capacity evidence continues to reference its original source revision. New source evidence is not a new sale or a new verified CRM receipt.

Retaining one of the explicitly accepted invoice-total differences is allowed only when the exact commercial tuple (package, travel, discount, total and original agreement/service identity) is unchanged. Reference the immutable original acceptance/owner note by revision and commercial hash; record that provenance in the new mapping. A changed total/difference cannot inherit the old acceptance. No recalculated total and no invented card-fee rate. Mapping suggestions remain suggestions; original `cash` remains original evidence with the approved unverified-Zelle display label.

Lock order keeps the importer protocol: current actor → import singleton → batch → authoritative event → link/source rows; current reads for request receipts and head/version checks. TIP's real REPEATABLE READ race demonstrates why a pre-lock hint cannot justify a snapshot-based idempotency lookup. Concurrent source updates serialize, stale previews return409, and an audit failure rolls back the entire append/head/receipt transaction. After effective CRM takeover, an export is discrepancy evidence only; it cannot overwrite the opening agreement, change responsibility back or double-count new collections.

## C3: readiness is more than a recent CSV

The operating cohort is the server-selected IMPORT-01 group; IMPORT-HISTORY archives never enter it. Snapshot exact membership, each event/responsibility, import link/head/version/hash, CRM customer ID/version, current operational fingerprint, originals and every local collection/message state. A late new member or changed contact/head/assignment invalidates the preview. Reuse the source importer singleton for membership serialization; lock relevant portal/quote grants in stable order before events, then current event/source/intent rows. TIP grants/intents use their event-first protocol; acquire their event before touching those rows, never a TIP grant before its event. Staff access grants are unrelated and must not be rotated or disabled by cutover.

Readiness missing codes include `freshness_policy_required`, `source_reconciliation_stale`, `cohort_changed`, `source_changed`, `contact_changed`, `source_money_unresolved`, `original_document_required`, `source_due_time_review_required`, `external_collection_not_retired`, `crm_collection_in_flight`, `tip_collection_or_reconciliation_open`, and `message_outcome_unknown`. Codes describe the actual missing evidence; no ready badge just because enum=crm.

Recency must be anchored to an explicit reviewed source observation/export, not refreshed by page loads or re-uploading an old file. If a fresh external review returns identical data, an owner attestation may record that **new review** with its source reference/hash/time and exact membership; it does not fabricate a new source-money revision. This is owner evidence, not a claim that an unavailable HoneyBook connector verified it. Server enforces bounded timestamps and the configured positive max-age policy; missing policy fails closed. César chose 240 minutes: the last actual owner-reviewed source payments must be at most 4 hours old at transfer; older review requires a new review. This does not schedule periodic HoneyBook polling/sync.

The owner's transfer attestation must cover both HoneyBook **automations** and its still-usable payment/invoice links/known collection attempts. Turning off reminder automation alone does not prove an old payment link cannot still collect. Do not claim the CRM remotely disabled HoneyBook. Any unretired/unknown external collection blocks readiness; owner supplies evidence through the private review flow. Late externally observed money after cutover is held for reconciliation, never automatically pasted onto the opening balance or represented as bank-verified CRM cash.

Proposed private reconciliation rows pin original document reference + SHA-256, source signed/unsigned status and owner review. An imported CSV signed flag is not a forged CRM e-signature record. Keep original externally signed PDF/evidence as such; never synthesize a signer/IP/timestamp or copy it into SignatureEvidence. Unsigned/missing/changed originals require resolution before a whole-cohort switch. No automatic new signature request.

## Financial bridge: explicit opening agreement, exact cents

Proposed immutable `cutover_opening_agreements` fields: transfer/event/import-link/source-revision IDs; source/commercial/operations/customer-review hashes; original total; source gross receipts, source refunds and net paid; raw balance/credit; original source due date and precision; reviewed CRM collection deadline; source document references/hashes/status; applicable deposit-review evidence; effective UTC time; author and version. `funds_verified` remains false unless separate actual bank evidence is attached. Private evidence stays private/encrypted where appropriate; audit contains IDs, versions and hashes only.

The source payment parser supports signed negative lines. Preserve source gross receipts/refunds/net rather than presenting net as gross with an invented zero refund. The source opening net is not inserted into LedgerEntry. The financial calculation after takeover is:

```text
net_received = opening_source_net + post_cutover_verified_CRM_receipts - post_cutover_verified_CRM_refunds
raw_balance  = original_source_total - net_received
balance      = max(raw_balance,0)
credit       = max(-raw_balance,0)
```

Tips and tip refunds remain separate in every term. A source re-sync after the effective transfer cannot move the frozen opening net. Unknown/refund/duplicate allocation states block new collection until reviewed. A manual owner attempt to bank-verify an old source payment must link evidence to that opening amount, not add a second receipt. Ordinary post-cutover receipt input requires actual received-at/allocation evidence; an old/date-only source line cannot silently become a new CRM receipt. Authenticated late real provider money is always retained and flagged if allocation is unclear.

Do not call the current CreateFinancialRevision on a confirmed imported event: it is a draft/catalog snapshot service. A source opening financial snapshot must retain original totals/labels/accepted discrepancy provenance, not today's prices. Mark its origin `honeybook_opening` and keep its actual event version. Never backdate a sale or falsify `financial_revision.event_version=event.version-1` just to satisfy an existing guard.

Current TIP and MAIL source guards expect the native confirmation relation `event.version=finance.event_version+1`. The bridge must add one explicit validated agreement/capacity-binding seam that recognizes a reviewed source opening at its **actual** operating version and original capacity evidence. Keep native logic intact. CreateContract::assertCurrent and collection authorization must use the correct agreement kind; merely removing ImportedAgreement::blockCrmLifecycle is insufficient. Initial catalog repricing and duplicate original-contract creation remain forbidden for imports; only the reviewed portal/collection paths gain opening-agreement authority.

Portal source documents need a scoped private original-agreement projection/artifact read, not a fabricated signed Contract record. The client sees original agreed amounts, bank-unverified source funds distinguished from new CRM collections, and the actual remaining balance. Imported already-reserved parties do not get a new deposit demand at cutover; original deposit/owner exception evidence remains historical. No collection-purpose change silently overrides the original agreement.

`balance_due_date` in IMPORT-01 is day-only and may be empty when fully paid. Preserve that precision. A timestamp required by CRM reminder/checkout fields must be an explicitly reviewed CRM operational deadline, separately labelled from the original source date. Use an existing exact approved document deadline where present; otherwise require review. Do not invent midnight, copy a timestamp from a note or assert that a derived24h policy was the original HoneyBook timestamp. No new deadline policy is selected by this design.

REPORT-A must classify a source opening financial revision separately from a new CRM sale: creating it today is not new business today. Opening source funds do not enter this week's CRM cash receipts. Actual later verified CRM receipts use their actual receipt time and may enter collections normally. Scheduled party revenue/balance forecasts may use the preserved original agreement. Previously prepared report snapshots remain immutable; tips stay separate.

## Atomic switch, reversal and implementation slices

Prepare one reconciliation snapshot, revalidate every member, then create opening agreements/bindings and update responsibility in **one transaction** with one immutable transfer record and per-event audit. Do not change the operating event version or reservations merely to update financial responsibility. No grants/messages/charges are created as a hidden side effect of the flag update. Scheduling future reminders is a separate bounded plan after the committed bridge and approved source checks; missed reminders are skipped, never caught up in a burst.

Reversal keeps all source/transfer/opening records. It disables the effective bridge only if the complete current cohort is unchanged and no CRM collection, TIP collection, sent/dispatching/delivery_unknown message or unresolved external attempt exists. TIP's `active_event_id`, paid receipts and open reconciliation rows must be included. Unknown is not equivalent to not sent/not paid. A fresh view cannot erase an effect; partial reverse is refused. No deletion/rewrite of receipts, original PDFs, evidence or previous owner reports.

Proposed delivery slices for peer review:

1. C2 diff read + same-route immutable money update (including confirmed/past known projects), strict operational refusal, no-op, source-difference provenance, real MariaDB races and rollback.
2. C3 private original-document/deadline/source review + current cohort snapshot/readiness (no transfer).
3. C3 opening-agreement model/common authorization/finance and report-origin adapters, using synthetic source funds; old import guards remain until each path recognizes a valid bridge.
4. Atomic cohort switch + reversal over those adapters, unknown-outcome blockers, no external provider calls under DB locks, sandbox-only. Nothing live without the separate owner activation.

All public cutover route/service names from RESPONSIBLE-SYSTEM-API.md remain proposals until their slice is implemented and reviewed. This design earns0 implementation; it does not close Phase8 or approve any source file/import, provider, deadline or live transfer. The separate owner 240-minute freshness decision is recorded above.
