# AUTH-READ-LIMIT — Separate authenticated read and write request budgets

Status: backend implemented, awaiting Claude review; browser sizing evidence remains pending. Non-blocking follow-up from Claude's PR #22 review. Parent: AUTH-01 / UI-ADMIN-01. Implementer: Codex; reviewer: Claude, with calendar request measurements.

Problem: throttle:admin-write (60/min) currently covers both reads and writes. A calendar view can make several legitimate reads and consume the budget needed to save an event.

Scope: separate named limits for authenticated reads and writes, with a higher read budget selected from measured calendar requests. Keep pagination and active owner + confirmed 2FA authorization on every route. Preserve login/challenge limits and per-user isolation. Coordinate the name change in session/API routes with the admin shell rather than removing throttling globally.

Acceptance/tests: calendar reads do not consume the write allowance; writes are still limited; read budget is bounded; two users have independent budgets; staff/inactive/unenrolled/anonymous access remains blocked; login and 2FA challenge limits remain intact. Document the chosen budgets and measured request pattern.

Files owned: FortifyServiceProvider limit definitions, admin API route groups and tests; Claude supplies UI request counts. No changes to business permissions or public/client APIs.

Rollback: restore the original conservative shared limiter using a normal commit, without bypassing authentication or changing persisted data.


## Backend delivery (2026-10-16)

AdminApiRateLimit delegates to Laravel named admin-read/admin-write middleware after owner authorization. Both remain60/min per owner with independent counters; routes/DTOs/permissions/login/challenge/portal limits unchanged. Current Calendar.php uses Eloquent + Livewire rather than admin API fetches; an actual initial calendar HTTP render leaves API reads at60 before the first API request. No browser network profile is claimed. The proposed higher read allowance is deferred until Claude supplies measured traffic; keeping60 avoids guessing while already resolving read/write interference.

Focused auth/calendar/budget integration:17 tests /367 assertions pass, including actual audited save after read exhaustion, bounded writes and expiry, same-IP owner separation, HEAD classification, denied-owner gates before consumption and initial calendar render. Full integrated evidence is recorded in HANDOFF. No migration or UI wiring required.
