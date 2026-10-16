# RECORD-VERSION — prevent silent concurrent customer/character edits

Status: open, non-blocking follow-up accepted by Claude in main PR #29.

- Implementer: Codex. Reviewer/UI adapter: Claude. Depends on the shared record services and form integration.
- Scope: additive optimistic versions for customers and characters; return the version on reads and require the displayed expected_version on updates through both the API and services. Filament retains that version in a Locked property, reports 409, and lets the owner reload/reconcile. Current behavior is last write wins; this ticket does not claim it is already protected.
- Non-scope: changing permissions, merging conflicting field values automatically, new workflow or deployment.
- Files owned: Codex migrations/models, SaveCustomer/SaveCharacter, RecordRules, API/contract and backend tests; Claude customer/character edit pages and form tests. Coordinate the contract change before implementation.
- Acceptance: simultaneous edits by César and Maybeli cannot silently overwrite; the first succeeds and audits once, the stale second returns 409 without another write/audit. Freshly reloaded updates succeed. Initial versions backfill existing rows; create needs no expected_version.
- Verification: MariaDB stale-write and successful-retry tests through direct services and API, plus Livewire displayed-version/tampering and rejected-save feedback coverage for both record types.
- Rollback: roll back the coordinated application/UI changes before the additive columns; preserve existing audit history. No destructive rollback of owner edits.
