Title: Add sandbox weekly/monthly operational report and integrate cost screen

Base: claude/cost-margin-ui (150941f); retarget main after the UI/integration chain merges. Includes previously delivered reservation-neighborhood performance changes (83b2709). No claude/* history rewritten.

Adds owner-only OperationalReport and GET /admin/api/reports/operations. Calendar periods use America/Chicago, receipt/refund cash uses receipt date, and scheduled event costs/balances use event date with unknown/stale costs remaining null. Bulk queries avoid calling per-event screen summaries. Empty reports and explicitly confirmed zero costs remain distinguishable from pending costs. Two cost-screen copy fixes avoid claiming paid revenue or confirmed zero costs.

Contract: docs/REPORT-A-API.md, committed before implementation. Review: docs/reviews/COST-MARGIN-UI.md. This is a partial REPORT-A backend: package sales, booking counts, weekend forecast, email scheduling/delivery and historical closing balances remain pending. Nothing live; no real payments or messages.

Validation: see newest docs/HANDOFF.md entry for final full-suite counts and checks. Claude reviews and opens/merges the PR; GitHub write API is unavailable in this cloud session.
