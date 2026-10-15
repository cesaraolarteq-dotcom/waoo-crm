# AUTH-READ-LIMIT — Separate authenticated read and write request budgets

Status: open; non-blocking follow-up from Claude's PR #22 review. Parent: AUTH-01 / UI-ADMIN-01. Implementer: Codex; reviewer: Claude, with calendar request measurements.

Problem: throttle:admin-write (60/min) currently covers both reads and writes. A calendar view can make several legitimate reads and consume the budget needed to save an event.

Scope: separate named limits for authenticated reads and writes, with a higher read budget selected from measured calendar requests. Keep pagination and active owner + confirmed 2FA authorization on every route. Preserve login/challenge limits and per-user isolation. Coordinate the name change in session/API routes with the admin shell rather than removing throttling globally.

Acceptance/tests: calendar reads do not consume the write allowance; writes are still limited; read budget is bounded; two users have independent budgets; staff/inactive/unenrolled/anonymous access remains blocked; login and 2FA challenge limits remain intact. Document the chosen budgets and measured request pattern.

Files owned: FortifyServiceProvider limit definitions, admin API route groups and tests; Claude supplies UI request counts. No changes to business permissions or public/client APIs.

Rollback: restore the original conservative shared limiter using a normal commit, without bypassing authentication or changing persisted data.
