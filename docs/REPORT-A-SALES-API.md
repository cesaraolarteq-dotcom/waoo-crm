# REPORT-A — initial confirmed booking/package counts

Contract before implementation. Owner-only finance sandbox. No sends, writes or rate assumptions.

`App\Services\Reports\BookingSalesReport::execute(User $actor, array $input): array`

`GET /admin/api/reports/bookings?period=week&anchor=2026-11-02`

Required period=week|month and real YYYY-MM-DD anchor (2000–2100), same Chicago Monday/month boundaries and exclusive UTC upper bound as OperationalReport. `ReportPeriod::resolve(array $input): array` becomes the shared period validator/formatter; original operations payload remains unchanged.

Definition shown to the owner: **initial capacity confirmations**, dated by the first `booking.capacity_reserved` audit entry for each event (subject_type=events). Neither event date, draft creation, payment date nor later repeated audit entries count as another booking. This is an explicit operational measure, not accounting revenue or an approved universal sales definition. Later cancellation/rescheduling does not erase the historical initial confirmation; this slice does not count cancellations/net sales.

Package source: immutable FinancialRevision.lines_snapshot linked by capacity_payment_links at confirmation. Never read current catalog/event-services, latest finance revisions, client contacts or bank data. Missing links/revisions mean package coverage unknown (legacy/manual reservations), never infer zero. Counts apply only to USD finance snapshots linked to that same event. A line represents one contracted service occurrence (no quantity field exists here); grouping is by service_variant_id + historical name, so later renaming cannot rewrite historical labels. Different historical names for one variant may appear as separate rows. Labels are escaped by the UI.

Response: environment=test, timezone, generated_at, period (same keys), basis=first_booking.capacity_reserved.created_at, booking_count, package_coverage_complete, package_unknown_booking_count, package_occurrence_count (null if any unknown booking), known_package_occurrence_count, packages [{service_variant_id,name,occurrence_count,booking_count}]. `packages` is the known subset; always show unknown count beside it. Empty cohort is complete, count zero. Each event is counted once, multiple services add occurrences, and booking_count per package deduplicates an event containing repeated occurrences. Private/no-store. No event/customer IDs or cost/payment internals returned.

Claude: display “Reservas confirmadas por primera vez”, period dates, known package counts and unknown-booking warning. Do not label it net sales, completed events, cash revenue or canceled-adjusted sales. Owner can review this definition in the dashboard without blocking useful implementation. Scheduled emails remain pending and inactive.
