# RECORD-VERSION coordinated API / form contract

2026-10-16 · Codex · additive concurrency follow-up accepted in PR #29; interface recorded before source.

## Data and service contract

Add unsigned integer `version`, default 1, to customers and characters. Backfill existing rows through the non-null default; expose it on normal model/API reads, cast to integer. Never mass-assign `version` from input.

Signatures remain `SaveCustomer::execute(User $actor, array $input, ?int $customerId = null): Customer` and corresponding SaveCharacter. Create has no expected version and returns version 1. Every update must provide integer `expected_version >= 1`; missing/invalid is 422. Authorize the fresh explicit owner/2FA first. Lock the row, compare displayed expected version, return 409 if stale before any write/audit; successful update increments once and audits with before/after versions atomically. Direct service calls and PUT `/admin/api/{customers|characters}/{id}` share this path. Extra submitted `version` is ignored, never used to bypass the guard.

## Coordinated consumers

Customer/character Filament edit pages capture the version on mount in `#[Locked] public ?int $displayedVersion`. Send that value, not a fresh model version at save; after success advance it to the saved version for repeat edits. On 409 retain form edits, show the existing persistent rejection message and require reload/reconciliation. Create pages stay unchanged.

ApproveClientDetails already locks the customer and compares immutable submitted base fields to the current record. It must additionally pass that locked customer's current version to SaveCustomer (not a browser value); keep its existing stale-submission/event/base-field checks. Customer creation in QuickBook remains unchanged. No silent last-write-wins caller remains.

## Verification / rollback

Test both record types via API and direct services: required version, create/read defaults, stale update with zero write/audit, explicit actor and fresh reload. Run real two-process races on MariaDB, one winner and one 409; the winner's audit and version are unique. Filament: stale editor retains inputs and cannot replace its Locked version; two successful edits on one mounted editor advance its version. Preserve BOOK-QUICK approvals. Migration rollback removes columns only after coordinated code rollback, leaves audit/business data intact, and works on MariaDB and SQLite.

Scope excludes customer-data field merges, new permissions, delete actions, availability changes, live deployment and owner acceptance. Claude reviews both service and form adapters before merge.
