# Exact-head review: IMPORT #71 and QUOTE #68

2026-10-17 · Codex. Read newest HANDOFF in claude/import-ui first. No actual CSV opened; synthetic data only. No deployment, send, charge or actual import.

## #71 at7390e40 (includes f4da93c): changes requested

Original ImportHoneyBookPageTest: **7 tests / 61 assertions PASS**, PHP8.3.35/MariaDB11.8, isolated application source with existing vendor/assets. This verifies the exact head, not a newer combined stack. No browser claim.

**R1 — finalization falsely confirms deletion when cleanup is pending.** ImportHoneyBook::finalize ignores FinalizeHoneyBookImport's returned batch and always sends success / “El archivo original se borró”. The service intentionally returns imported_cleanup_pending when deletion fails; it does not throw. The screen's test accepts either status and only assertNotified, so it misses the misleading claim. Branch on returned status: pending should show a persistent warning and explicit retry; say deleted only on imported. Test storage delete=false, evidence remains, then retry succeeds and only then deleted copy appears. Do not delete durable source agreements.

**R2 — remembered character mapping loses outfit and note.** openRow flattens each character to price_version_id/character_id/quantity; the repeater also omits outfit/note. Commit reuses servicesInput and saves the reduced selection as the remembered mapping. Thus a previously explicit version becomes any version, losing operational intent. Preserve/display/edit optional outfit and note using the same constraints as the existing event form; keep string “0” intact. Test a remembered explicit outfit+note survives opening and committing a second row. Never silently drop a restriction from a suggestion.

**C1 — pin coverage version.** finalize currently fetches batch()->version at submit, rather than the version shown when coverage was reviewed. Backend recomputes domain coverage, limiting the consequence, but this bypasses the displayed-version convention. Pin a Locked coverage batch/version, invalidate attestation on batch selection/upload/row mutation and reject stale review until refreshed. Test another owner changes the batch after review.

Positive: row/batch/source/catalog/customer pins, private upload and temporary cleanup, server money, explicit customer selection, no verified payment/resource reservation, restricted owner route. Keep corrected /admin/api/imports/honeybook/{batch}/rows/{row}/mapping (same fix independently made on Codex branch). Pending imported capacity UI remains separate.

Request for Claude: R1/R2 before approval; C1 recommended in the same follow-up. API published separately on codex/import-owner-resolution at9c91e9d: choose explicit owner acceptance plus private note for package mismatch; conditional due date; suggestion helper; owner-confirmed cash label. Backend delivery will follow; do not fake exceptions in UI. Preserve one Filament pages union when integrating.

## #68 atde2f347: approved

Reviewed one-read projection, immutable revision/snapshot/PDF and separate accept/reject consent pins, name/email requirements, actual PDF access guard, server decisions, revoked/replaced/admin cases, escaped client whitelist and per-number/revision/outcome idempotency payload reuse. Admins cannot decide. Browser PDF-click state is only a hint: backend PDF-access evidence remains authoritative.

**32 tests / 308 assertions PASS**,38.623s, PHP8.3.35/MariaDB11.8: QuotePortalPageTest, QuotePortalPreviewTest, QuotePortalTest, QuoteClientViewModelTest. No new browser/full-UI-stack/remote-CI claim. Exact JSON retry logic inspected; Claude's own prior browser evidence is not relabeled as Codex testing. Approval supersedes earlier old-head review concerns. Nonblocking: ReadQuoteViewModel repeats same-day date; Codex can shorten end to time+timezone while retaining date for cross-day and CDT/CST precision.

#69 now in main per owner. #72 carries previously reviewed #70; no new exact-head #72 whole-branch review is claimed here. Never lose EmailTemplates, Leads/Quotes/QuoteDetail or ImportHoneyBook in provider merges.
