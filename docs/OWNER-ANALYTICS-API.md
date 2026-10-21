# Owner analytics v1 — contract before implementation (2026-10-21)

Branch: codex/owner-analytics. Sandbox only, `WAOO_SANDBOX_ANALYTICS=false` by default.

`ReadBookingAnalytics::execute(User $actor, array $input): array`, input `year` (2000–2100).
Owner admin with confirmed 2FA, secure transport. History also requires its existing flag.
Read only. No customer identifiers, names, contact details, project titles, notes or raw source strings
in the response or CSV. Existing financial Reports remain unchanged.

Population: current historical revisions of completed events, plus operational capacity-reserved/completed
CRM events. Imported operational events appear once, using their current source agreement instead of a
native financial revision. Exclude prospects, cancelled, unknown historical status and CRM drafts. Report
counts exclusions/unknowns. Status exclusions are scoped to the selected party year;
undated rows cannot be assigned to a year and are explicitly date_unknown_all_years. Historical dates and amounts are source evidence, never verified receipts.
Party date in Houston defines the year/month/weekday. Booking lead time requires an explicit source
creation date; never substitute import time. Negative/unparseable lead time is unknown.
USD ticket average uses only known nonnegative totals; denominator and unknown count are explicit.
Packages use canonical catalog variant IDs (with current catalog names for the owner screen) only, with an unknown bucket for unmapped historical packages.
Lead sources map to a closed vocabulary; unknown free text stays Other. ZIP requires five digits.

Output schema_version=1, year, timezone, booking_count, source_counts, exclusions,
months, weekdays, packages, postal_codes, lead_sources, lead_time {known_count, unknown_count,
average_days}, ticket {known_count, unknown_count, total_cents, average_cents, currency}.
CSV: long format dimension/key/count, plus ticket and lead-time metrics. No individual rows or raw text.

ANNIV-01 contract (next delivery): a separate default-off draft preparation service; no outbound messages,
codes or send jobs. Completed birthday and reliable date, explicit promotional permission and suppression
checks are mandatory. Raw HoneyBook SMS evidence is not marketing permission. Latest request asks about a
month before anniversary; draft will expose the proposed date without replacing the existing six-week send
policy until the discrepancy is resolved. ES/EN copy remains unapproved. No email provider activation.

Test acceleration: retain real multi-process and schema/migration tests on DatabaseMigrations. A transaction
fixture may be introduced only with mixed-suite reset handling and evidence of unchanged assertions.
