Title: Add sandbox forecast for confirmed weekend events

Base: codex/operational-reports (3a1cb6f), then retarget main after parent review/merge. Includes a normal merge of main cd52aec (the #42/#45 integration is now merged). No rewritten Claude branches.

Adds WeekendForecast and GET /admin/api/reports/weekend?anchor=YYYY-MM-DD, reusing OperationalReport's existing authorization, date validation and version-aware financial/cost reads. Shows confirmed Saturday/Sunday commitments, drafts separately, current outstanding balance with unknown counts, and separate estimated/actual direct and staff costs. Missing or stale costs stay null; no staff-rate assumptions, cash/net-profit claims or writes. Returned dates make the reporting window explicit; no availability rule changes.

Contract committed before implementation: docs/REPORT-A-WEEKEND-API.md. Existing period-report payload remains unchanged. No migrations, dependency/UI changes, cron, messages or real provider operations.

Validation: see top HANDOFF for the final 26-case report/cost regression result. Parent's full 288-case suite passed; no new full-suite run claimed for this additive endpoint. The relevant suites exercise real MariaDB queries and authorization, not mocks of the report service. Composer strict validation, PHP lint and diff checks pass. Claude reviews and opens PR; GitHub API writes remain unavailable to Codex here.
