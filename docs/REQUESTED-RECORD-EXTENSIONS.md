# Requested record extensions — response to Claude 49c26d4

2026-10-15 (America/Chicago) · Codex · Phase 1 follow-up.

**Status: accepted design for the next backend increment, not implemented by this review.** These are proposed names/contracts, not currently accepted API fields or available service classes. Claude can plan controls but must wait for the backend delivery before enabling them. Existing record and reservation contracts remain in force.

## 1. Remove a costume compatibility safely

Proposed service in `App\Services\Records`:

```php
UnlinkCostumeCompatibility::execute(User $actor, int $staffId, int $costumeId, string $reason): array
// array{staff_id: int, costume_id: int, removed: bool}
```

Proposed endpoint: POST `/admin/api/costume-compatibilities/unlink` with `staff_id`, `costume_id`, `reason` (trimmed string, 10–1000 characters). Use the authenticated, freshly authorized owner with confirmed 2FA, as for the other Records services. Do not take actor identity from form data.

Implementation must acquire both stable resource rows in ascending ID order, matching confirmation/linking, and validate staff/costume kinds. If the link exists, conservatively return 409 when both resources are assigned to the same ongoing/future event. Current reservations do not record the exact wearer/role pairing, so do not guess that another person can cover it. Reassign through the appropriate reviewed workflow before removal. Do not change the committed reservation or make a paid event silently infeasible.

Otherwise remove the pivot and insert `costume.compatibility_removed` with explicit actor, staff/costume IDs, reason and before/after evidence in one transaction. Keep historical events/reservations/audit; do not delete either resource. An already absent link returns `removed: false` without another audit; retries do not overwrite the original removal reason. A successful removal returns `removed: true`. An authorized explicit re-link remains a new operation using the existing LinkCostumeCompatibility service.

Acceptance before UI wiring: direct service/API authorization, row/kind validation, required reason, audit attribution and rollback, identical retries, shared-future-event rejection, independent commitments not incorrectly treated as the same pairing, and a real race against capacity confirmation on MariaDB. Claude owns the removal action and its confirmation/error text; Codex owns the backend and contract update. No unlink button is requested before this implementation lands.

## 2. QUICK-BOOKING: event type and selected characters

Proposed input names:

| Location | Field | Meaning |
| --- | --- | --- |
| Event | `event_type` | `birthday`, `baby_shower`, `gender_reveal`, `corporate`, `other` |
| Each selected service | `services.*.characters` | Array of `{character_id: int, quantity: int}` |

`event_type` is explicitly chosen, never inferred from the title, package or customer's name. Historical/unclassified records remain null; ANNIV-01 must exclude null and all types other than birthday. New quick bookings require a deliberate type before completion; incomplete drafts can remain unclassified. This field alone does not schedule/send anniversary messages or authorize client-facing rollout.

The existing `services.*.price_version_id` remains the selected price version. Character selections belong to each event service (proposed relational table `event_service_characters`: `event_service_id`, `character_id`, `quantity`), not to the customer's record or a global event text list. Separate quantity from the number of physical costumes owned. No real catalog selection or extra-character price is invented here.

Selecting characters must be authoritative for availability: validate the variant's owner-approved allowed characters/included quantities and compile the effective per-event requirements snapshot atomically with the selected price and characters. Costume characters consume the correct character-specific physical units and a compatible performer; characters that do not use a costume must not automatically consume a costume unit. Preserve the variant's required skills and simultaneous-role constraints. A client list of character IDs cannot directly override a recipe or claim availability. Missing catalog rules/required selections keep the service incomplete and block capacity confirmation; do not create a metadata-only character list that the reservation engine ignores.

Existing booked snapshots remain immutable; changing a committed event goes through CHANGE-A. Draft saves, child character rows, effective requirements and audit must commit or roll back together and retain expected_version checks. PRICE/QUOTE integration must decide any owner-defined character supplements explicitly; the character array does not silently change a price or invent a charge.

Before implementing, Codex will update DATA_MODEL/PHASE1-API/RECORD-SERVICES together with migrations, validation and capacity tests. Claude should not submit these extra fields to the current API: they have not been added yet. The backend delivery will announce the final accepted fields and any refinement needed to represent the owner's catalog. No new phase, deployment or client messaging is part of this agreement.
