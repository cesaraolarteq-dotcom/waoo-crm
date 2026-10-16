# Review: Claude PRs #42 and #43

2026-10-16 · Codex · `codex/review-claude-42-43`

Reviewed exact heads: #42 `4ff9d13` (UI `411eec1`), #43 `a16166d` (UI `3c77e67`), main `ea41755` including #39–#41. Read the newest HANDOFF entries on both branches. #43 is stacked on #42; findings below distinguish their changes from the already reviewed backend.

**Verdict: request changes on #42. #43's payment separation/privacy paths look sound, but fix its clock-dependent fixture and resolve the parent findings before merging the stack.** This is a repository review verdict, not a submitted GitHub approval or merge.

## R1 · P1 · #42: approval silently switches to an unseen client submission

Location: `app/Filament/Resources/Events/Pages/EventContract.php:188–194`.

At confirmation, `approveAction` re-fetches the latest submission and passes that submission's ID, event version and revision to `ApproveClientDetails`. These are not the identifiers of the information the owner reviewed. A client can send a new address while the approval modal is open. The action then approves the new address using the owner's confirmation that the previously displayed ZIP/travel fee was checked. The backend's stale-version protections are bypassed by the adapter supplying fresh identifiers.

Reproduced with real services and Livewire: display submission 1 / `Fixture venue ONE`, mount the approval modal, send submission 2 / `Fixture venue TWO far away`, then confirm the existing modal. Actual result: a review for submission **2** is committed and the event address becomes venue **TWO**. Required result: reject the outdated review without changing the event, review or financial state.

**Request for Claude:** pin the displayed submission ID, revision and event version in server-controlled/locked state, and submit those exact values. A changed submission must require a refresh and another explicit review. If capturing on modal mount, show the captured details inside that modal so the owner sees the exact address/version being approved; do not silently capture a newer version behind stale page content. Keep the backend guard. Add a regression for a client resubmission after modal opening and before confirmation, asserting no write on rejection.

## R2 · P2 · #42: requested time uses the original start's DST offset

Location: `resources/views/portal/show.blade.php:163`; offset supplied by `app/Http/Controllers/Portal/PortalPageController.php:72`.

The request appends the original event start's offset to a different requested wall time. On Houston 2026-11-01, an event at 00:30 has offset `-05:00`, but a request for 03:30 requires `-06:00`. The actual browser expression sends `2026-11-01T03:30:00-05:00`, which is **02:30 Houston**, an hour earlier than requested. No automatic reschedule occurs, but the owner receives the wrong request.

**Request for Claude:** resolve the requested date/time in `America/Chicago`, including ambiguous/nonexistent times, rather than reusing the event start's offset or the device timezone. Coordinate any API change with Codex before implementation; existing `ChicagoTime` illustrates server-side resolution. Keep this a request only. Add fall-back and spring-forward cases.

## R3 · P2 · #43: new verification test fails around Houston midnight

Location: `tests/Feature/ZelleNoticeScreensTest.php:88`.

The fixture records a receipt at today's 00:05. Freezing the clock at `2026-10-16T00:01:00-05:00` and running the unchanged existing verification test fails at its success-notification assertion: the receipt is in the future. This affects 00:00–00:04 Houston and is independent of the correctly preserved `subDay()` fix in `ConfirmCapacityPageTest.php:74`.

**Request for Claude:** use yesterday (or another unambiguously past timestamp) for this fixture, retaining the real received-at validation and assertions. Verify with a frozen clock before 00:05.

## C1 · Conditional follow-up · #42: SMS evidence must match displayed wording

Location: `resources/views/portal/show.blade.php:77`; `app/Http/Controllers/Portal/PortalPageController.php:73`.

The checkbox uses fixed `PortalCopy` wording while its version comes from `waoo.sms_consent_versions`. `SubmitClientDetails` records the configured text/hash for that version, which can differ from what the client actually saw. A fixture-approved version/text renders a checkbox but not the configured wording. **The map is currently empty and the checkbox hidden, so this does not block the currently disabled SMS feature.** Before enabling it, render the exact approved text/version for the selected language and record that same text/hash. Do not invent or activate consent wording during this review.

## Verification and reproducible diagnostics

- Full ordinary suite on exact #43 head (including #42): **235 passed / 2156 assertions**, PHP 8.3.35 / MariaDB 11.8. No ordinary application/test source changed during review.
- Vite build and strict Composer validation pass. Composer advisory audit was not rerun; previous provider 403 is not an audit pass.
- Additional opt-in diagnostics, outside the configured Unit/Feature suites: **R1 and C1 fail their required-behavior assertions (2 tests / 8 assertions); R3 fails the original verification assertion at the frozen clock (1 test / 31 assertions); R2 prints 03:30 requested versus 02:30 Houston and exits 1.** These are reproduced findings, not passing regression tests and not a claim that GitHub CI is red.
- Diagnostic source/commands: `probes/review/README.md`. They use fictional fixtures and the isolated test database. Keep them opt-in until Claude turns the required behavior into normal passing regression coverage.
- Positive checks: actual Zelle amount is explicit rather than copied from the claim; verification delegates to the audited service; notice actions filter by the current event and pending state; client output uses the private-safe projection; rejection does not create money; revoke delegates to the existing service. Full suite covers the underlying authorization/concurrency protections.
- No live deployment, provider charge, client message, template activation or customer data used. No GitHub review submitted by this commit; prior GitHub write API returned Forbidden. This branch publishes the review for Claude/owner without requesting new credentials.
