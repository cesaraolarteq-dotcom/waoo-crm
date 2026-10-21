# HoneyBook independent verification and customer correction — v1 (2026-10-21)

Contract before implementation. Branch codex/honeybook-independent-verification, based on main after #112.
All new behavior requires sandbox_history plus default-off WAOO_SANDBOX_HISTORY_VERIFICATION.
No source API calls, migration execution, sends, charges or live writes.

## Independent verifier

Artisan `waoo:honeybook:verify --snapshot=/private/absolute/dir --report=/private/new-act.json`.
Four raw JSON captures: projects.json (listProjects), contacts-pipeline-files.json (listContacts plus
pipeline/file responses), workspaces.json (listWorkspaces/listWorkspaceSmartFiles), file-responses.json
(getSmartFileResponses). May contain raw response arrays, named collection envelopes, and MCP text JSON
content blocks. No CSV importer mappings or Claude script imported by the verifier. Unsupported/ambiguous
shapes and missing pagination completeness are failures to verify, never interpreted as zero records.
Optional private exclusions.json: explicit project_id/reason list; not inferred from totals or hardcoded262.
Optional originals.json: project_id/source_reference/kind/path/sha256 manifest of independently retained PDFs.
Each path is private snapshot-relative, no links/traversal; local bytes and CRM encrypted bytes compared.
Without independent bytes, database self-consistency is labelled separately from source authenticity.

Read-only comparison of every source project against historical/current operating heads, customers,
original source fields, typed money/facts, imported ledger and originals; reverse coverage finds extra CRM
projects/files. Source leaves not understood/preserved are retained in the private act as unverified
paths, never counted as matched. A source field absent in the CRM is a difference, not a guessed default.
Payment methods remain source labels; no assertion that source cash was bank-verified Zelle.
Totals are derived from actual sets. Do not force the reported241+18+3,509 or9 differences.
Raw field values/project/contact IDs belong only in the private JSON. Terminal prints aggregate counts.
Private act created exclusively with0600, parent0700, outside git/repository/public/log paths; never overwrites.
Snapshot hashes and verifier version in act, explicit incomplete verdict if raw schema/coverage insufficient.
Claude runs on actual crm-test and compares with his independent act. Neither technical tests nor a database
hash alone certifies the entire migration100%.

## Owner correction

`ReassignHistoricalCustomer::execute(User $actor, int $historyId, array $input, string $uuid): array`.
Pins expected_history_version, expected_revision_id, expected_source_sha256; existing target customer
(id + expected_version) OR new target (name, optional email/phone, locale); owner_reason10..2000 mandatory.
Owner/current2FA/transport/flags rechecked inside shared import transaction. New immutable historical
revision, encrypted correction evidence and audit metadata; old source, revisions, decisions and receipts
remain intact. No event, ledger, payment or client data overwritten. Stable exact retry; stale/conflicting
retry409; audit failure rolls back customer/revision/correction together. No-op target rejected.

Correction is per project. Original global external contact evidence is immutable. This project's explicit
correction takes precedence; other projects using a disputed source contact require review rather than
silently following the former global binding. Does not bulk-move other projects or operating events.
Owner customer-history UI offers target/new customer and reason with pinned versions and UUID.

## Phone matching

Phone-only automatic suggestions require normalized nonempty full-name equality (case/spacing/Unicode
normalization). Missing/different names retain candidates but state conflicting, so bulk import cannot
link them and row-by-row requires an owner reason. Contact/email tiers remain authoritative subject to
existing ambiguity/conflict checks. No fuzzy name matching or automatic merging.
