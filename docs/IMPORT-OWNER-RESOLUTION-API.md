# IMPORT-01: explicit historical exceptions (API v1)

Published before implementation, 2026-10-17. Extends IMPORT-FUTURE-V1-API; sandbox only. No actual export, money mutation, bank verification, client communication or deployment.

## P1 — owner accepts a source equation difference

Choose option (a), not automatic fees or hidden exclusions. Existing CommitHoneyBookEvent::execute(actor, batchId, rowId, input, key) and its POST route gain optional `source_difference_accepted` (boolean) and `source_difference_note` (trimmed string, 5–1000 characters). When and only when the source has `package_equation_mismatch`, both true and a note are required. Supplying either on a row without this finding is rejected. This exception never bypasses missing money, balance/payment equations, duplicate keys, dates, versions, source/file hashes, operational requirements or explicit customer choice.

The source fields, normalized amounts and original errors remain unchanged. Freeze `revision.mapping.source_difference` containing policy_version `source-total-difference-v1`, code, difference_cents = total − (package + travel − discount), note, accepted_by and accepted_at. This is private source evidence, not a new fee, payment or adjustment. The note participates in domain intent/idempotency; changing it requires the existing explicit draft revision flow. Audit records IDs/hashes only, never the note. Coverage recognizes a difference only with matching current source hash and that revision's explicit evidence; expose the accepted evidence separately from unresolved gaps. Finalization still requires every non-skipped row to be imported. No exclusion endpoint or disappearance of coverage gaps in this delivery.

UI: expose the differing original numbers and delta; require an unchecked acknowledgement plus owner note. Keep all displayed batch/row/catalog/customer versions pinned. Never invent a 3% explanation or precheck approval.

## P2 — no due date for a settled source balance

An empty balance_due_date becomes null only when a valid source balance is ≤ 0. Positive or unknown balance still requires the source date. Nonempty dates must always validate. Never derive a due date or change source money.

## P3 — suggestion lookup only

Introduce HoneyBookMappingKey::forSource(source): versioned hash of services and characters after Unicode whitespace collapse/trim and lowercase. Preserve word boundaries, original text, project identity, source/preview hashes and actual CRM selections. Commit and the mapping endpoint use this helper; UI must use the endpoint or helper rather than copying the old exact-text hash. Suggestions remain explicit, never automatic. Legacy exact-key lookup remains available for existing identical source text; no reinterpretation of historical evidence.

## P4 — source method label map

César explicitly confirmed cash/Cash means Zelle in his export. Implement immutable code map `owner-honeybook-method-labels-v1`: normalized cash → display_method `Zelle (importado de HoneyBook, no verificado en banco)`, canonical_method `zelle`, funds_verified false. Preserve ledger.method and source text exactly. Other methods retain their original label and remain unverified; never infer visa/check approval. Freeze the label-map version in new revision mapping. ReadImportedEvent adds `payment_display` entries with ledger id, raw_method, display_method, canonical_method, label_map_version and funds_verified. Existing revisions without a frozen version use v1 only as a current owner display, explicitly `historical_version_frozen: false`; they are not rewritten. UI may reuse the pure formatter for staged payments. The source provenance remains HoneyBook and must never enter verified Zelle/Stripe totals.

## Related owner-confirmed driver requirement

Next separate backend delivery: at least one person with driver skill among staff actually matched to event roles, across the whole event; no extra headcount or per-service skills_all change. A selected but unused driver cannot satisfy it. Existing booked snapshots stay unchanged. Vehicles, seats, cargo, resource conflicts and transport buffers remain independent checks. Publish detailed matching/snapshot contract before implementation; this document is not a completed driver feature.
