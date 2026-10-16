# Phase 1 backend contract — Codex / Claude

Status: implemented and verified locally on `codex/phase-1-operations`, depends on `codex/phase-1-core`, awaiting Claude review (2026-10-15). Owner authorized Phase 1 in chat. Claude owns all screens; coordinate changes here and in HANDOFF before changing payloads. This is an internal administrative API, not the public booking flow.

## Authentication

Same-origin Laravel session cookies and CSRF; request JSON with `Accept: application/json`. First GET `/session/csrf`, send its token in `X-CSRF-TOKEN` on writes, keep cookies. POST `/login` with email/password. Fortify returns `two_factor: true` when a challenge is needed; POST `/two-factor-challenge` with `code` or `recovery_code`. POST `/logout` ends session.

New owners log in but cannot use `/admin/api` until they enroll and confirm 2FA. POST `/user/confirm-password` with password, POST `/user/two-factor-authentication`, GET `/user/two-factor-qr-code`, then POST `/user/confirmed-two-factor-authentication` with code. GET `/user/two-factor-recovery-codes` only on the authenticated password-confirmed session. Screens must provide recovery-code storage and never log secrets. Public registration/password-reset email is disabled in this slice. Provision owners with interactive `php artisan waoo:admin email --name="Name"`; no seeded account or password.

`GET /admin/api/session` returns user id/name/email/role and timezone. Only active `owner_admin` accounts with confirmed 2FA may use the administrative API. César and Maybeli have identical rights. 401 = authenticate, 403 = unauthorized or 2FA enrollment required, 409 = stale version/committed resource, 422 = validation/conflict detail, 429 = rate limit. Laravel JSON errors use message and (for validation) errors. UI/login route remains a placeholder for Claude.

## Records

Shared application-service signatures for API and Filament writes are in [RECORD-SERVICES.md](RECORD-SERVICES.md). The extraction is implemented on `codex/audited-record-services` and awaiting review; current endpoints and payloads below remain unchanged. Field validation messages are shared in Spanish, and business rejection status codes are preserved.

GET/POST `/admin/api/{customers|characters|resources|variants}`, PUT the same path with `/{id}`. Lists are Laravel pagination objects (`data`, links, metadata), 50/page. PUT supplies full editable record. No hard-delete endpoints.

- Customer: name, nullable email/phone, locale `en|es`.
- Character: name, active boolean.
- Resource: code (unique), name, kind `staff|costume|equipment|vehicle` (immutable), status `active|maintenance|inactive`, skills array; nullable location. Costume requires character_id; equipment requires equipment_kind (e.g. `speaker`); vehicle requires seats (total occupants including driver). Staff skills are explicit identifiers, e.g. `performer`, `face_painter`, `entertainer`, `driver`. Separate resource records represent separate costume/speaker units. Updates require expected_version. Changing a resource with future commitments is blocked pending reassignment, not silently applied.
- Variant: name, status `draft|active|retired`, duration_minutes, requirements array. Each requirement: kind, quantity, plus skill for staff / character_id for costume / equipment_kind for equipment. Requirements are simultaneous for the full event in this first slice. Active variants require complete requirements. Updates require expected_version. Catalog worksheet data remains authoritative; do not invent operational recipes.

POST `/admin/api/variants/{id}/prices`: amount_cents (integer), currency `USD`. GET same path returns newest first. Prices append versions; no mutation endpoint. No assumed tax/discount/card fee calculations in this slice.

POST `/admin/api/costume-compatibilities`: staff_id, costume_id. POST `/admin/api/resources/{id}/blocks`: starts_at, ends_at, reason. Blocks reject overlap with existing commitments including preparation and reviewed driving intervals. Listing and lifting blocks are defined in the lifecycle section below. Removing costume compatibility remains follow-up CRUD; do not build buttons for absent endpoints.

## Event draft

GET/POST `/admin/api/events`; GET/PUT `/admin/api/events/{id}`. GET list accepts from/to date-times and paginates, includes customer/services/reservations. Every date-time must include Z or explicit UTC offset; API stores UTC and the UI displays `America/Chicago` with daylight-saving handling. Avoid naive local timestamps, especially the repeated hour in November.

Create/update payload: customer_id, title, starts_at, ends_at, timezone `America/Chicago`, nullable address/postal_code/setup_minutes/breakdown_minutes/travel_fee_cents, services `[ {"price_version_id": 1} ]`. Update additionally requires expected_version. Response includes version, status and immutable selected price/requirements snapshots. Drafts can be incomplete and **never reserve capacity**. Re-saving a draft refreshes its recipe snapshots; committed events cannot use this edit route. Null travel fee means unknown, not zero. Optional postal_code is a string containing five ZIP digits or ZIP+4 (e.g. `00001` or `00001-1234` in fake fixtures); leading zeros are preserved. This validates syntax only, does not verify a venue/address, and never assigns a travel fee or route automatically.

GET `/admin/api/events/{id}/audit` returns event changes newest first with actor/time. The audit table also stores catalog/resource changes. No secrets/payment card data in logs.

## Manual confirmation of capacity

POST `/admin/api/events/{id}/confirm-capacity`:

```json
{
  "expected_version": 1,
  "resource_ids": [1, 2],
  "payment": {
    "method": "external_zelle",
    "reference": "unique-external-receipt",
    "amount_cents": 10000,
    "received_at": "2026-10-15T15:00:00-05:00"
  },
  "review": {
    "staff_confirmed": true,
    "cargo_checked": true,
    "origin_return_note": "Owner checked crew, origin, passengers, cargo and return.",
    "transfers": []
  }
}
```

Examples above are fictitious, not Waoo prices. This endpoint records owner verification of an **already received external deposit** (`external_zelle|external_card|external_cash`) and reserves resources atomically after revalidation. It neither takes payment nor creates an invoice, Stripe charge, contract or full financial ledger. A failed assignment does not erase or refund an external payment; owner must resolve it externally until the later payment ledger exists. UI must not label `capacity_reserved` as contract signed or fully paid. No hold before payment (D7).

Below 50% of selected services + reviewed travel fee needs review.deposit_exception_reason. Within seven days needs review.short_notice_reason. Record exact received cents. Missing address, prep times, complete recipe or reviewed travel fee prevents confirmation.

Each nearest preceding/following event sharing a resource requires a review.transfers item: from_event_id, to_event_id, from_version, to_version, travel_minutes, contingency_minutes, departure_at, note. Departure = preceding event end + breakdown. Manually check traffic for that date/departure and document its source. Required gap = drive + max(45, breakdown + next setup + contingency). Backend does **not** query Google Maps yet. Client requests to start 15 minutes earlier belong to the later explicit approval workflow; no reduced buffer here.

Transactions lock the event and sorted resource IDs, validate active inventory, person/role count, compatible performers, passenger seats/drivers, blocks, overlaps and transfer margin. One person cannot fill two simultaneous staff roles. A driver may participate during the party. A costume wearer must have performer skill and cannot simultaneously fill another role. Manual staff/cargo/origin/return confirmation remains mandatory; recurring shifts, cargo dimensions, automatic route prediction and optimized multi-role scheduling are not implemented yet.

Retry an identical confirmation safely; changed details on a committed event return 409. Cancel/reschedule/reassignment needs the future CHANGE-A workflow, not direct edits. No notification or public traffic changes occur.

## UI integration and remaining work

Claude: implement Spanish admin shell, login/enrollment/challenge/recovery screens, client/resource/catalog/event forms, and calendar with buffers. Consume these endpoints or propose changes in HANDOFF; do not duplicate the reservation logic in the browser or bypass it with direct Filament/Eloquent writes. Filament actions must use the same audited transactions; coordinate extraction into application services before replacing an endpoint. Optional unconfirmed draft assignments and resource suggestions need a separate agreed API before adding UI behavior. Owners' worksheet, account emails, staging approval/access and acceptance remain necessary for actual use. UI approval and Phase 1 acceptance are separate from this backend delivery.

## Resource block lifecycle (OPS-01 follow-up)

GET `/admin/api/resources/{id}/blocks` lists blocks for that unit, paginated 50, ordered by starts_at/id. Optional `state=active|released|all` (default active), `from` and `to` with explicit UTC offsets; range selects overlapping intervals. Responses include released_at, released_by and release_reason; an unreleased block has null release fields.

POST `/admin/api/resources/{id}/blocks/{block_id}/release` with `{ "reason": "Maintenance completed and unit checked." }` lifts only that block. Reason must be 10–1000 characters. It locks the same resource row used by confirmation, checks that the block belongs to the unit, records actor/time/reason and an audit entry, and preserves the original interval/reason. Identical retries return the original result without duplicate audit entries; a different reason after release returns 409. This does not activate a unit marked maintenance/inactive or release any committed reservation. No delete endpoint or edit of the original evidence is provided. Booking/transfer checks ignore released blocks; every other conflict check still applies.
