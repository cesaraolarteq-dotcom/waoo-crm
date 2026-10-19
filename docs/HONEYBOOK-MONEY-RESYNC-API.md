# C2 — reviewed HoneyBook money updates, delivery contract v1

2026-10-19. Published before implementation. Implements slice1 of `RESPONSIBLE-SYSTEM-C2-C3-DETAILS.md`, not the financial bridge or cutover. Owner/2FA, sandbox_imports, HTTPS on staging, private no-store and CSRF unchanged. No real imports, provider calls or sends.

## Read and decision

`ReadHoneyBookProjectDiff::execute(User $actor, int $batchId, int $rowId): array`, **GET `/admin/api/imports/honeybook/{batch}/rows/{row}/diff`**. One serialized current read binds:

- `batch:{id,version}`, `row:{id,version,preview_sha256}`;
- `current:null|{link_id,link_version,revision_id,event_id,event_version,customer_id,customer_version,source_sha256,operations_sha256,responsible_system}`;
- `kind:new|unchanged|money_only|blocked`, `changes:[{field,from,to}]`, `blocked:[{field,code}]`;
- `source_difference:null|{can_retain,revision_id,commercial_sha256}`, `diff_sha256` over the entire preceding projection.

Money fields shown are exact cents (`paid_cents`,`balance_cents`), source day-only due date and source payment summaries (position/date/method/signed cents, not bank-verified). Never return the original CSV, source notes, owner notes, file paths or secrets. `source_row_invalid`, `source_field_changed`, `operations_changed`, `responsibility_changed`, `event_not_confirmed`, `customer_link_changed` and `source_integrity_invalid` are blocking codes with field identifiers. A new project is informational and continues using the existing initial-import UI, not this resync command.

## Commit on the existing route

**POST `/admin/api/imports/honeybook/{batch}/rows/{row}/commit`**, UUID Idempotency-Key. A resync request contains **only**:

```json
{
  "expected_batch_version": 1,
  "expected_row_version": 1,
  "expected_preview_sha256": "64 lowercase hex characters",
  "resync": {
    "expected_link_version": 1,
    "expected_revision_id": 1,
    "expected_event_version": 2,
    "expected_customer_version": 1,
    "expected_diff_sha256": "64 lowercase hex characters",
    "owner_money_reviewed": true,
    "owner_note": "Owner's explicit review note, 5–1000 characters",
    "retain_source_difference_revision_id": 1
  }
}
```

The last field is mandatory only when retaining an existing explicitly accepted total difference; otherwise omit it. No customer/package/resource selections, amounts or source fields are accepted in this branch. Legacy initial/draft requests keep their existing contract; a changed confirmed source without `resync` remains refused. Success retains `{link:...}` and adds `operation:money_resync` for resync requests, so the UI does not ask to reserve resources again. The link identifies the current source head, not a historical replay snapshot.

Only `amount_paid`, `balance`, complete `payments`, and `balance_due_date` may change. Every other original field must match exactly, including source status, signed flag, contacts, notes and agreed commercial tuple. Operational setup/breakdown, event schedule/address/title/customer and immutable service recipe must still match the imported agreement; use frozen selections, not today's catalog. Require a confirmed `capacity_reserved` HoneyBook event with original imported capacity evidence/reservations. Known past projects may be staged for review (original `skip_reason:past` retained); unknown past projects remain skipped and can never be created through this branch. Cancelled sources remain skipped.

Changed evidence appends a complete immutable revision/ledger snapshot, moves the link head/version and batch version, and records owner review plus retained source-difference provenance privately. Event version, services, reservations, BookingEvidence, financial revisions, bank ledger and responsibility remain unchanged. Identical source is a no-op even with another key; only its request receipt is recorded. A replay uses the stored receipt before mutable preview checks, but after fresh owner authorization. Exact request hashes are required. No automatic source review freshness/cutover attestation is created.

Use current reads under actor → importer singleton → batch → event → link/customer/source locks (a row identity lookup under the serialized importer is permitted before event discovery). Concurrent resyncs serialize; stale versions/hash return409. Audit failure rolls back append, head, batch and receipt. Coverage must recognize the original capacity evidence after a money-only head update, without rebooking; future-start checks remain mandatory for new/draft creation. Source gross/refunds/net are shown separately, preserving negative source adjustments; source funds never become CRM bank receipts. After takeover this endpoint refuses updates; C3/discrepancy handling remains a separate delivery.

The projected `current.operations_sha256` binds the frozen service selections (including notes), actual reservation rows and original assignment evidence; the legacy service-only hash remains private in the import mapping for comparison. MariaDB11.8 may reject a locking read against a changed row in an old repeatable-read snapshot (error1020). Top-level commands retry the whole transaction; an enclosing transaction must also retry as a whole, never retry only a savepoint or suppress the conflict. A source head cannot be changed by replaying an older source snapshot.

## Claude

Review this contract and later backend source before wiring a separate **Revisar pagos de HoneyBook** action. Show exact money differences, source-only/unverified labels and owner note; pin the entire read. Any blocked field or409 requires review/reload. Do not reuse the initial-import mapping controls or infer a fresh bank reconciliation from a page reload. C3, automatic four-hour refresh, real import and live activation are not delivered here.
