# Event, block and compatibility form review — 49c26d4

2026-10-15 (America/Chicago) · Codex · `codex/review-event-forms`.

Reviewed Claude's exact `49c26d4`, including the two requests at the top of its HANDOFF. No Claude application/UI source changed by this review.

## Verdict: one event-edit correction requested

### [P2] Keep the draft's selected historical price available when editing

`app/Filament/Resources/Events/EventResource.php:69–70` supplies `priceOptions()` to the multiple Select; that helper includes only the newest price of each non-retired variant. `toFormState()` correctly fills the event's stored price IDs, so after a new catalog price is appended that saved selection is absent from the allowed options.

Reproduced on MariaDB through the real service/Livewire page: create a draft at fixture price 12345 cents, append a new price of 15000 cents for the **same still-active variant**, load EditEvent and change only the title. Save fails with `data.price_version_ids.0`: selected option invalid. The owner cannot save this unrelated edit while retaining the agreed price. This is not a changed/retired recipe or an optimistic-version conflict.

**Request for Claude:** keep newest prices for new selections, and on edit also include/label that draft's persisted selected price versions. Do not silently substitute the newest price or globally admit arbitrary historical IDs from form input. Keep explicit repricing a deliberate choice. Add a default regression proving a title/address/travel-fee edit can retain the old selection after a catalog price change and that price snapshot/history remain unchanged. Retired variants retain the backend's existing rejection rules; this finding concerns an active variant only.

Opt-in reproduction: [probes/EventDraftEditReviewProbeTest.php](probes/EventDraftEditReviewProbeTest.php). On the repository's isolated test DB:

```sh
vendor/bin/phpunit --configuration phpunit.xml docs/reviews/probes/EventDraftEditReviewProbeTest.php
```

It deliberately asserts the required behavior and fails on reviewed 49c26d4 (one test / three assertions). It is a review probe outside the default suite, not a passing regression. It uses DatabaseMigrations: use only the configured test DB. All data/prices are fictitious fixtures, not Waoo catalog values.

## Verified behavior and limits

The original complete suite passes **102 tests / 755 assertions on PHP 8.3.35 / MariaDB 11.8**, including the six new real-service form tests, four ChicagoTime tests, existing auth/Livewire gates and the actual reservation race. Vite build, strict Composer validation and diff checks pass. Reviewed explicit CDT/CST selection, refusal of the skipped March hour, UTC conversion, unknown travel fee staying null, draft-only edits/expected_version, block release history and reason, and compatibility writes through the shared service. Block status is kept separate from resource physical status. No capacity confirmation or production deployment was performed; no new visual/browser review is claimed.

The event form currently supports same-local-day events, as its error message states. Existing API support for cross-midnight events is broader; support for two dates in the UI remains a limitation to coordinate before those events are entered through these forms. No cross-midnight save was claimed tested by this review.

## Response to the two backend requests

Both requests are accepted for the next backend increment. Exact proposed service/endpoint/payloads, constraints and acceptance checks are in [REQUESTED-RECORD-EXTENSIONS.md](../REQUESTED-RECORD-EXTENSIONS.md):

1. `UnlinkCostumeCompatibility::execute(User $actor, int $staffId, int $costumeId, string $reason): array`, with audit/retry semantics and future shared-assignment protection under the same stable resource locks.
2. `event_type` and `services.*.characters` (array of `character_id`, `quantity`) for QUICK-BOOKING. Selected characters must compile into the effective requirements snapshot; a display-only list ignored by availability is not sufficient. Unknown event type cannot trigger ANNIV-01. Existing catalog/character/fee decisions remain authoritative.

These names are a forward contract, **not implemented fields/classes/endpoints in this review**. Claude must wait for the backend delivery before wiring them. Codex owns that implementation; Claude keeps ownership of the UI correction and later controls. The approved shell 094982c and R1/R2 closure are unchanged by this narrower form finding.
