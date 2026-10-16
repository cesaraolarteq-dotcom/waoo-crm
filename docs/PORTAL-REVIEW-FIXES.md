# Portal review corrections (PRs #42 / #43)

2026-10-16 · Codex · interface recorded before implementation.

## Approval snapshot (R1)

No service signature changes. `EventContract` captures the submission ID, event version and revision when the page is loaded, in locked server-owned Livewire state. The displayed details and approval both use this snapshot. A newer client submission does not become the target of an already-open page/modal. `ApproveClientDetails::execute(User $actor, int $eventId, int $submissionId, array $input, string $key)` still enforces latest revision and expected event version. On conflict no writes occur; reload the page to review the new submission. No generic version guards are removed.

## Requested local time (R2)

Additive input to `POST /portal/api/details` / `SubmitClientDetails::execute`: when `start_time_confirmed=false`, send exactly one of:

- Existing `requested_start_at`: ISO date with explicit offset (preserved for compatibility).
- New `requested_local_time`: strict `HH:mm`; optional `requested_time_choice`: `cdt` or `cst` for the repeated November hour.

For the new form, the server derives the date from the locked event start in `America/Chicago`; browser date, device timezone and original event offset are not inputs. Resolve that date plus requested wall time using the shared Chicago timezone helper. Reject March nonexistent times (422). For ambiguous November times require the explicit choice (422 if absent). Store the resolved ISO instant in the existing immutable submission `data.requested_start_at`; do not store temporary local-time fields or move the event. Reject mixed local and ISO inputs. Preserve both expected versions, scopes, sandbox gates and idempotency. The browser sends the local form only; bilingual optional choice explains which occurrence applies if the hour repeats. No new endpoint/scope/migration.

## Consent wording (C1)

No new consent configuration or activation. Keep the current empty version map disabled. When configured with an approved nonempty string, render the exact configured string and associated version (escaped plain text) and store that same version/text/hash using the existing service. Never translate approved legal wording automatically. Existing version-map configuration is unchanged; no message sending is enabled.

## Fixture time (R3)

Keep payment validation and assertions; use a past fixture receipt date. Add a frozen-clock regression before 00:05 Houston. These corrections await Claude review and do not approve or merge Claude's original PRs automatically.
