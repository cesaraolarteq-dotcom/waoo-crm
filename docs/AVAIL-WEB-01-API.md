# AVAIL-WEB-01 — website availability API v1

2026-10-22 · Codex · **original published contract**. The original publication implemented no route, service, flag, scheduler or website connection. The S1 transport slice at the end records the subsequent bounded implementation; it does not complete the evaluator or website connection. Sources: [ticket](tickets/AVAIL-WEB-01.md), [owner queue item12](plans/CODEX-PENDING-2026-10-20.md), [temporary authorization](plans/CODEX-AUTONOMY-UNTIL-SUNDAY.md). This refines the ticket's endpoint proposal and selects its signed server-to-server option; the owner's business rules remain unchanged. Implementation and website integration require their own tested deliveries.

## Owner rules and meaning

- Houston (`America/Chicago`), every day, party window8a.m.–1a.m., maximum10 parties per operating day. Operational day is anchored to its8a.m. start; after-midnight times carry their real next-calendar-day date. Never count a long party twice merely because it crosses midnight. Exact closing-boundary treatment for a party ending after1a.m. is not decided here: do not advertise that case as available until resolved.
- Less than14days of notice requires owner approval. Older7day wording is superseded. Proposed precise comparison is candidate start strictly before `now(America/Chicago)->addDays(14)`, preserving local clock across DST; equality is not short notice. Freeze this boundary in implementation fixtures and review before activation.
- Reuse actual resource inventory, skills, equipment recipes, physical costume units/wardrobes, resource blocks, paid reservations and unpaid48h leases. One assigned staff member may also drive; do not add a person to satisfy the team-driver rule.
- Reuse package setup/breakdown and existing scheduling/travel evidence. Unknown minutes are unknown, not zero. The approved45minute transfer contingency is retained. Do not silently add it twice if the authoritative scheduling policy already includes setup/breakdown in its buffer. Owner-approved15minute-earlier exceptions require individual owner evidence; public input cannot authorize one.
- Traffic estimates must use the candidate event date/time and both neighboring transfers, not today's route. Missing geocoding, traffic, vehicles/driver/cargo evidence or return/origin data must result in review/unknown, not a guessed travel time. ROUTE-01's price calculation alone is not a crew-transfer feasibility check.
- Availability is an estimate of a checked snapshot, **not a resource hold, crew assignment, booking confirmation, price, payment or promise**. Booking rechecks current capacity under existing locks. Historical archives and cancelled/prospect records never consume current capacity; imported operating commitments do, even while HoneyBook is responsible for payment/messages.

## Transport and authorization

Website PHP server calls the CRM over HTTPS using a fixed configured CRM origin; the browser never receives credentials or calls these routes directly. Choose signed server-to-server rather than a public enumeration endpoint. No CORS wildcard and no cookie/session authority. Proposed `WAOO_SANDBOX_WEB_AVAILABILITY=false` and a dedicated `WAOO_AVAILABILITY_SHARED_SECRET` are **not added to config in this delivery**. Do not reuse the lead-intake secret. Initial implementation stays local/testing/staging; live enabling remains separately authorized.

Headers for both methods:

- `X-Waoo-Timestamp`: decimal Unix seconds within±300s.
- `X-Waoo-Signature`: `sha256=` plus lowercase HMAC-SHA256 hex of `timestamp + "\n" + METHOD + "\n" + exactRequestTarget + "\n" + exactRawBody`, keyed with the dedicated secret's raw bytes.
- Request target includes `/api/...` and the exact transmitted query string, excluding scheme/host. GET has empty body. Reject duplicate query keys, unknown keys, invalid percent encodings and noncanonical method; never reconstruct another query string for signature verification. Do not log headers, raw targets, coordinates, request/response bodies or secrets.

An identical signed read can be retried inside the time window; no mutation/idempotency receipt is created. Retry after the window signs the same query/body with a fresh timestamp. Authentication precedes validation/cache lookup. Constant-time comparison and route/IP rate limits apply even to failed authentication. Initial operational limits:60requests/minute per caller IP, max16KiB POST body, max24candidate starts and3alternative dates; these are engineering abuse bounds, not sales policies.

## GET /api/availability — compatible day signal

Query: required `date=YYYY-MM-DD`; optional `zip` (US5digits), `service` (active stable CRM service-variant ID), `alts=0|1` (default0). Exact date only; no relative strings. No customer name/email/phone/address, coordinates, free text or event ID. When service/characters/location evidence is incomplete, the response must not imply checked package-specific availability.

```json
{
  "schema_version": 1,
  "scope": "day_signal",
  "date": "2026-11-14",
  "timezone": "America/Chicago",
  "level": "filling",
  "capacity_state": "unknown",
  "demand": "weekend",
  "requires_owner_approval": false,
  "slots": [],
  "alts": [],
  "checked_at": "2026-10-22T10:00:00-05:00",
  "expires_at": "2026-10-22T10:05:00-05:00",
  "reservation_created": false
}
```

`level` remains `open|filling|full|unknown` for the future existing PHP adapter. `capacity_state` is `available|unavailable|unknown`. A count below10, a free costume or an empty calendar alone cannot yield available/open. Daily cap reached can yield unavailable/full. Any complete feasible interval requires the same full resource/logistics evidence as the check below. Full means all permitted starts for the requested recipe are proven impossible; checking a few starts cannot establish that a whole day is full.

The next8upcoming Saturdays/Sundays retain the ticket's existing marketing demand indication (`demand=weekend`); legacy `level=filling` may reflect demand even if capacity_state is unknown. It must never hide a proven full day. This indicator does not mean “few places remaining,” does not create fake scarcity and does not fabricate available slots. Client copy remains the already approved registry's responsibility; no new client wording is approved here.

`alts` is at most3 `{date,level:"open"}` objects, only from dates actually checked for the same complete request. Unknown/short-notice-review days are not silently advertised as open alternatives. GET without enough request context returns no fabricated alternatives.

## POST /api/availability/check — explicit candidate times

This separate read keeps destination coordinates out of URLs. It is read-only despite POST and creates no lead/customer/booking. Content-Type `application/json`; closed input:

```json
{
  "date": "2026-11-14",
  "timezone": "America/Chicago",
  "service_variant_id": 12,
  "characters": [{"character_id": 7, "quantity": 1, "outfit": null}],
  "destination": {"postal_code": "77001", "latitude": 29.76, "longitude": -95.36},
  "candidate_starts": [
    {"local": "2026-11-14T15:00", "offset_choice": null},
    {"local": "2026-11-14T17:00", "offset_choice": null}
  ]
}
```

IDs come from a separately reviewed public catalog mapping; do not guess slugs/IDs from a website label. Variant duration/requirements/timing are loaded on the server; no browser-supplied price, stock, duration, buffers, staff assignments or available=true. Character quantities/wardrobes follow current catalog compatibility, preserving literal outfit `"0"`. Missing required selections yield an incomplete result, never default to a different character or stock unit. Coordinates must be valid, inside supported territory and coherent with the ZIP; missing/uncertain location yields review, not a ZIP-centroid guarantee. Do not request a street address/name for this estimate.

Each candidate is its own full local date/time. Reject past instants (422); a cached result cannot extend a start into the past. Reject duplicate candidates and dates outside the requested operating-day window. No invented15/30minute slot grid: the website submits the times it is considering. End time derives from the versioned variant duration, including overnight date rollover; setup/breakdown extend the busy interval. Use the existing server Houston parser. Reject nonexistent spring-forward local times; require explicit CDT/CST choice for fall-back ambiguity. Never silently pick the first offset.

```json
{
  "schema_version": 1,
  "scope": "candidate_times",
  "date": "2026-11-14",
  "timezone": "America/Chicago",
  "level": "filling",
  "capacity_state": "available",
  "demand": "weekend",
  "requires_owner_approval": false,
  "slots": [
    {
      "starts_at": "2026-11-14T15:00:00-06:00",
      "ends_at": "2026-11-14T17:00:00-06:00",
      "state": "available",
      "requires_owner_approval": false
    }
  ],
  "unresolved": [{"candidate": 1, "code": "review_required"}],
  "alts": [],
  "checked_at": "2026-10-22T10:00:00-05:00",
  "expires_at": "2026-10-22T10:05:00-05:00",
  "reservation_created": false
}
```

The examples illustrate the wire shape only: IDs/duration/coordinates are synthetic, not a seeded product, route validation or availability finding. No runtime fixture is activated by this contract.

`slots` contains only starts with a complete successful capacity check. Short-notice starts otherwise feasible use `state=owner_approval_required` and `requires_owner_approval=true`; the website must put them on the request-for-approval path, not instant booking. Generic uncertainties are in `unresolved`, not presented as available. `unresolved.code` is closed: `unavailable|review_required|incomplete_request|evaluation_unavailable`. Impossible candidates use only generic `unavailable`; no resource names/counts, event titles, reasons such as “no driver,” neighboring bookings, staffing phone numbers, exact routes or private audit hashes are public.

With candidates supplied, capacity_state=available means at least one returned checked slot, unavailable means all supplied candidates are proven unavailable, and unknown means no checked slot plus at least one unresolved candidate. **This is candidate scope, not proof the entire day is full**: legacy level cannot become full on that basis alone. Mixed results preserve both checked slots and unresolved indexes. Null timing/corrupt commitment/incomplete imported capacity/provider failure must remain unresolved. Client JSON never includes owner-only explanations.

## Authoritative computation and concurrency

Proposed internal seam, not existing classes:

```php
App\Services\Availability\ReadWebsiteDay::execute(array $input): array;
App\Services\Availability\CheckWebsiteCandidates::execute(array $input): array;
```

HTTP authentication, gate, validation and safe projection must wrap these independently of any future owner UI. Internal full feasibility evaluator is a separate implementation prerequisite; do not call a mutating ConfirmCapacity service then roll it back as an availability check. ReservationNeighborhood is a low-level overlap helper, not a complete public availability evaluator.

Evaluate one consistent set of versioned operations, catalog recipes, resource compatibility, blocks, paid holds, unpaid leases and neighboring transfers. Do not reuse another event's selected crew without a valid full matching proof. Household drivers, dual-role staff, vehicles/cargo, physical equipment and both previous/next travel must be feasible together; independently positive staff/costume checks are insufficient if no single combined assignment exists.

The read never releases, expires, promotes or repairs commitments. A lease with pending Zelle review still blocks release under existing protocol; expired wall-clock time alone must not make its disputed inventory free. Cancelled/released commitments do not double count; promoted lease+paid hold count once by event. The daily cap counts distinct effective event commitments (including active/pending-review date leases); an uncommitted draft/lead alone does not occupy one of the10parties. Missing/unconfigured commitments in an imported draft must cause review where capacity cannot be established, rather than hiding HoneyBook work. Existing owner evidence remains authoritative; source payment responsibility alone cannot hide occupied capacity.

No real Google calls in contract/tests. Later routing adapter must budget provider calls and use event-date traffic evidence. Work is bounded; budget exhaustion returns unknown/503, never fallback open. Snapshot change during evaluation invalidates the result or triggers a bounded recheck; no new domain write or global scheduler lock for a web read. Acceptance still performs its own transactional check, including the global10/day constraint; two simultaneous readers can both see the same slot without reserving it.

## Cache, errors and website integration

Cache authenticated, strictly projected responses server-side for at most5minutes, keyed by complete normalized selection, operating day, exact candidate instants, location hash, authoritative scheduling/catalog policy versions and a capacity-generation marker. Do not cache raw coordinates or private evaluation graphs in public/shared response caches. Invalidate on reservation/hold/block/catalog/recipe/assignment/timing changes and pending-payment review transitions. If that generation marker cannot be maintained atomically, disable positive caching; do not claim five-minute cached capacity is current.

Shorten expires_at to any known transition (lease expiry, local policy/lead-time boundary, business-day rollover). CDN/browser responses: `Cache-Control: private, no-store`; cache-control does not authorize another caller or remove the need to recompute booking. No token permitting reservation is returned.

-200: valid result, including capacity unknown; strictly closed response projection.
-401: missing/invalid/stale signature;403: insecure staging or unauthorized caller;404/503: disabled gate per the eventual uniform route policy (freeze one choice in implementation).
-413: body too large;415: wrong media;422: malformed/unknown input, invalid dates/times/coordinates or incompatible catalog selections;429: rate limit with Retry-After;503: unavailable/over-budget/untrusted capacity evaluation. Generic errors only, no exception/provider messages.

Website adapter remains **unimplemented and disconnected**. Future PHP call has2second total timeout, configured trusted HTTPS origin, bounded retries and no raw error logging. Legacy iCal fallback may retain only its existing demand/unknown signal and must identify its internal source as fallback; it cannot manufacture CRM-verified slots or claim capacity_state=available. The request form remains usable when unknown/full; submitting a request never confirms a booking. Automatic SMTP/SMS/lead conversion/payment/holds are outside both reads.

## Implementation verification matrix (not tests delivered)

1. HMAC binds method/target/body/timestamp; changed query/body/method, stale auth, duplicate keys, oversized bodies, disabled gate, plain HTTP, rate limits and no credential leakage.
2. Closed request/response schemas; no person/event/staff identifiers, counts, private notes/routes, raw coordinates or source evidence in output/logs. Only requested public catalog IDs in input.
3. Physical speakers bottleneck, role-skill matching, same-person driver, vehicle/cargo, outfit0/flexible/fixed compatibility, resource blocks and before/after transfer traffic for actual weekday/time.
4. Setup/breakdown missing vs explicit0,45minute contingency once, previous/next feasibility,15minute exception blocked without owner evidence. No provider calls in automated tests.
5. Day cap9→10, concurrent final-slot acceptance, adjacent-day overlap, overnight operating day, candidate vs whole-day full, short notice before/equal/after14day boundary, DST gaps/folds.
6. Active/expired/cancelled/promoted48h leases, pending Zelle retains stock, paid holds, source-imported drafts with incomplete capacity, corrupt commitment evidence, snapshot races and cache invalidation.
7. Website2second outage→iCal demand/unknown fallback; never fabricate slots, block submission or mark fallback as verified. Weekend8day demand rule cannot mask full/unknown capacity.
8. Reads leave event/resource/ledger/customer/lead/grant/mail tables unchanged; no reserve/release, payment, pricing, send, actual provider call or deployment.

No new application tests are needed for this documentation-only delivery. Validate embedded JSON and relative source links, and keep progress at design/no percentage earned. The eventual implementation must first add failing behavior tests from this matrix. Remaining implementation review boundaries: day-closing semantics, short-notice exact boundary, disabled-route status, capacity generation, full matching read extraction and private routing evidence. None is permission to connect the website or invent a scheduling policy.

## S1 transport freeze · 2026-10-26 · before implementation

Claude requested implementation in PR #129 after César confirmed the operating-window/cap/14-day rules. First executable slice prepares the server-to-server boundary, not a complete feasibility evaluator:

- Default-OFF `sandbox_web_availability` / dedicated `availability_shared_secret`, minimum32 and maximum512 raw bytes, no lead-secret fallback. Only local/testing/staging; unavailable configuration/production returns503. Staging requires HTTPS. Both routes use a shared60/min/IP throttle, including unauthenticated failures. Maximum raw target2048 bytes and raw body16384 bytes; HMAC verifies the exact request target and raw body before payload validation. Unsupported methods (including HEAD and method overrides) return405.
- GET implements only the compatible day signal: strict date (today through2100), optional ZIP5digits, active service-variant ID and alts0/1; duplicate/unknown/array query keys or malformed encoding fail422 after authentication. All responses keep capacity_state=unknown, slots/alts empty, reservation_created=false. A weekend demand flag applies only to the next8 upcoming Saturday/Sunday dates. There is no count-only available/full claim. Day-level requires_owner_approval is conservatively true when its earliest8a.m. start is earlier than now+14 local days; this does not classify individual later starts. Exact candidate policy belongs to S2. No response cache; expires_at equals checked_at, so this provisional result has no reusable availability lifetime.
- POST authenticates/bounds the wire and checks JSON media type, but returns503 `evaluation_unavailable` until the complete candidate-input validator and authoritative evaluator land together in S2. It does not parse selections, issue slots, silently choose DST offsets or route requests to a mock evaluator. The existing full candidate contract remains the S2 target.
- Closed generic errors only (`availability_unavailable`, `authentication_failed`, `invalid_request`, `payload_too_large`, `unsupported_media_type`, `method_not_allowed`, `evaluation_unavailable`). No echoed query/body/coordinates, provider exception, Laravel debug trace or new client copy. All availability successes/errors use private,no-store headers. No CORS browser access or shared positive cache is introduced.
- No new reservation, customer, lead, grant, ledger, audit, mail or routing-provider writes; only the abuse throttle cache changes. GET with an explicit service may read active catalog membership, not inventory/neighboring events. S1 does not earn full AVAIL completion or authorize Claude to switch the live website. Website catalog mapping, actual resource+driver/cargo/date-traffic evaluator, capacity-generation invalidation, candidate/DST checks and final cap/hold/race matrix remain required before verified slots or cutover.

This bounded slice intentionally exposes unknown/unavailable rather than weakening the published evidence requirements. S2 must preserve it as the safe fallback when any prerequisite is missing.

## Shared notice prerequisite · 2026-10-28

`App\Services\Scheduling\BookingNoticePolicy::requiresOwnerApproval` unifies the existing website day signal, operational confirmation (`ConfirmBooking`, including paid/imported callers) and owner form. The threshold is now in Houston plus14 **local calendar days**; strictly earlier requires the existing explicit owner reason, exact equality does not. Spring/fall DST changes preserve the local clock instead of adding336 UTC hours. The day signal still uses the earliest8a.m. start and remains unknown capacity. The existing idempotent confirmation branch precedes new-booking checks: exact retries of already-confirmed evidence remain retries as time advances. No retroactive revision of saved evidence or new exception authority.

The owner form's only visible change replaces7 with14 in the existing Spanish label and uses the same policy for visibility; no new client copy/contract/price. The seven-day dashboard urgency card and week-before mail schedule are different rules and are unchanged.

This correction is **not full S2**. The10/day constraint still needs serialized admission across operational confirmation, unpaid Stripe leases, paid costume commitments and imported operating commitments (including those owned financially by HoneyBook). `ConfirmBooking` alone cannot enforce that shared quota, and separately counting reservations would double-count multiple units/promoted holds. No positive capacity response, cap enforcement claim, routing proof, website cutover or external activation is introduced here.
