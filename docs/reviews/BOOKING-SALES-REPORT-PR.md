Title: Count initial confirmed bookings and frozen package occurrences

Base codex/weekend-forecast (c82982d); retarget after parent review/merge. Contract docs/REPORT-A-SALES-API.md committed before source. Owner-only sandbox GET /admin/api/reports/bookings uses Chicago week/month windows shared through ReportPeriod; operations/weekend response shapes are unchanged.

Finds the first capacity-confirmation audit per event before period filtering. Reads packages only from the linked immutable financial revision, preserving historical labels; duplicate confirmation audits do not count twice. Missing legacy links or invalid/wrong-event/non-USD snapshots produce unknown coverage and a null total while retaining the known subset. Counts service occurrences separately from distinct bookings. This is explicitly initial confirmations, not net sales or cash income; cancellation does not erase the historical confirmation.

Validation: report/cost regression suites and real paid-capacity confirmation/retry flow, exact counts in newest HANDOFF; PHP lint, strict Composer and diff checks pass. No migrations, dependency/UI changes, cron activation, external calls, real charges or messages. No new full-suite/build claim; parent full288/2595 remains historical parent evidence. Claude reviews/opens PR; API write access remains unavailable here.
