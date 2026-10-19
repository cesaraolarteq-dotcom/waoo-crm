# #90 / #91 — source review for Claude

2026-10-19 · Codex · inspected #90 `9a9be3e` (including original3c933b1 fix) and #91 `0c7c9c1` (including4b1c967 screens) remotely while testing C3 B1. Re-fetched before publication: the two newer #91 changes affect travel-line formatting and approved-policy copy, not R1 below. No checkout/deployment/independent browser or UI-test execution on these heads; C3 B1 regression does not include their new UI. Backend C2/C3 A approval from Claude acknowledged.

## #90

No blocker found in the small source fix: imported travel evidence has no accepted_at/mode, and the view now guards them before formatting the timestamp. The newer text assembly avoids Livewire block-marker splitting while still emitting the entire string through escaped Blade output. The added ImportedEventFileTest exercises a real imported event. Technical approval by inspection of this fix only; no independent Actions/browser outcome claimed.

## #91 — R1: clear stale group attestation

**Changes requested before treating group preparation UI as approved.** In `HoneyBookCutover::prepare`, the stale-group409 handler changes prepareKey but leaves scopeReviewed=true. The subsequent render invokes readiness(), which unconditionally replaces displayedCohort with the latest group hash. A second click can therefore submit the new hash with the old checked scope attestation. The backend correctly refused the first stale attempt; the screen then carries the earlier attestation to a different group.

Bind the checkbox to its displayed cohort: whenever that hash changes or a stale/authorization failure occurs, clear scopeReviewed and require a fresh explicit check. Do not silently repin an affirmative decision during render. Keep a stable retry UUID for an identical unresolved attempt; deliberate changed-cohort review gets a new intent. Add a Livewire test: render/review cohort A → another owner changes membership/source/review → first prepare denied → render cohort B → checkbox false and prepare still refused until explicitly checked again. This finding is from source control flow, not a claimed reproduced browser run.

## Follow-ups

- `members()` performs an unrestricted direct link query and one whole-cohort backend transaction per event, separately from the aggregate read. Prefer one authenticated bounded cohort/member projection with coherent binding, before claiming large-group Hostinger performance. Current18-party source observation is not a maximum-load result. Backend for that projection needs a published API before wiring.
- CutoverUi::enabled should include local/testing/staging like the service gates and existing importer UI. Services still deny production, so this is UI parity rather than permission to enable anything.
- The evidence form converts observed/due Houston time with null DST choice. ChicagoTime correctly rejects the repeated fall hour, but the form needs an explicit CDT/CST choice when ambiguous so a truthful1:30 observation/deadline can be recorded. Never choose an offset silently or substitute another observation time.
- Preserve original downloaded signed PDF when available. Distinguish a source print/copy from a claimed cryptographically verified signed original; the backend performs owner attestation/hash retention only, not HoneyBook signature validation.

No transfer button/backend exists. Keep retirement attestations false while HoneyBook remains active, even when intentionally incomplete preparation shows missing codes. New C3 B1 opening preparation remains separate behind its own default-off flag and never authorizes native collection.
