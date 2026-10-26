# AUTH-READ-LIMIT — Separate authenticated read and write request budgets

Status: open; non-blocking follow-up from Claude's PR #22 review. Parent: AUTH-01 / UI-ADMIN-01. Implementer: Codex; reviewer: Claude, with calendar request measurements.

Problem: throttle:admin-write (60/min) currently covers both reads and writes. A calendar view can make several legitimate reads and consume the budget needed to save an event.

Scope: separate named limits for authenticated reads and writes, with a higher read budget selected from measured calendar requests. Keep pagination and active owner + confirmed 2FA authorization on every route. Preserve login/challenge limits and per-user isolation. Coordinate the name change in session/API routes with the admin shell rather than removing throttling globally.

Acceptance/tests: calendar reads do not consume the write allowance; writes are still limited; read budget is bounded; two users have independent budgets; staff/inactive/unenrolled/anonymous access remains blocked; login and 2FA challenge limits remain intact. Document the chosen budgets and measured request pattern.

Files owned: FortifyServiceProvider limit definitions, admin API route groups and tests; Claude supplies UI request counts. No changes to business permissions or public/client APIs.

Rollback: restore the original conservative shared limiter using a normal commit, without bypassing authentication or changing persisted data.

## Implementation contract · 2026-10-26

Keep the existing **60 writes per minute per authenticated administrator** and introduce a separate **60 reads per minute** budget. GET and HEAD use `admin-read`; every other method uses `admin-write`. A shared `admin-api` selector dispatches to those named definitions with explicit read/write key namespaces. All three authenticated `/admin/api` groups (web, staff and tips) use this selector after the unchanged authentication and active-owner/confirmed-2FA guards. No public/client/staff-portal or Google OAuth limiter changes.

Do not increase the numeric read limit on the old calendar assumption: current `Calendar` navigation uses signed Livewire requests and server-side queries, not `/admin/api`. A synthetic HTTP trace will exercise the initial calendar GET and six signed navigation updates while both API budgets are exhausted. Claude should confirm the real browser request counts before any later numeric increase. A higher limit is conditional on measured demand, not a prerequisite to separating the counters.

Tests must first reproduce the actual shared-budget failure: sixty successful reads prevent a valid customer save, and sixty rejected writes prevent a session read. Then prove independent bounded counters, per-user isolation on a shared IP, cross-file budget sharing, HEAD behavior, window expiry, denied identities, retained login/challenge limits and the current calendar route behavior. Rejected writes count toward the write cap and create no customer or audit row.
