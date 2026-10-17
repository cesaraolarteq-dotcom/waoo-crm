# Event crew driver — implementation contract v1

2026-10-17. Owner q28 confirmed in latest chat and Claude STAFF-SKILLS-REPLY: at least one driver among staff actually assigned across all event services. Applies to new confirmations needing staff. API published before implementation. No extra staff or price change; no single-slot skills_all requirement. Existing capacity_reserved idempotent replay and historical evidence remain unchanged.

## Commands and errors

No new browser input or endpoint. ConfirmCapacity::execute(User $actor,int $eventId,array $input), ConfirmPaidCapacity and ConfirmImportedCapacity continue through ConfirmBooking. Compile flattened roles with event_service_id and requirement/occurrence identity; added costume performer roles are explicitly identified. Match each resource to at most one simultaneous slot. Fits still enforce skill, character, exact outfit, equipment and staff-costume compatibility.

New pure MatchEventResources::execute(array $slots,array $resources,callable $fits): array returns resource-index → slot-index, prioritizing the maximum possible number of drivers via augmenting paths. Match drivers first, then extend without removing already matched resources; reassign roles when needed. Never count unused selected resources. Full role coverage is mandatory. For any staff slots, require at least one assigned driver; when vehicles are selected require enough assigned drivers for all vehicles. Return ordinary booking validation 422 “Falta alguien que maneje en el equipo.” or existing multi-vehicle error, before writes. Empty staff crew adds no driver role. Vehicle seats/cargo, busy/block/compatibility locks, neighboring routes and 45-minute buffer remain separate unchanged requirements.

## Evidence and reads

Nullable JSON booking_evidence.staff_assignment_snapshot: old rows stay null. New confirmations store policy_version `event-driver-v1`, minimum_drivers, assigned_driver_ids, and assignments with event_service_id, requirement_index, occurrence, resource_id, kind, skills and slot/costume identity. Snapshot records chosen assignment, not a new reservation count. Same transaction as booking evidence, reservations, operational review and audit; failure rolls back all. No editing/rebuilding older snapshots after a resource skill changes. No PII or new client surface. Expose through existing owner booking-evidence reads; no new client field.

Claude UI: use the existing server error and show driver among assigned staff, not a new headcount checkbox. Marking someone driver is an owner-maintained factual skill, never inferred from selection. Backend tests use synthetic drivers; do not alter actual catalog or resource data.

Tests: global two-service driver, unused spare rejected, augmenting reassignment, multi-role uniqueness, costume fit, no-staff event, multiple vehicles, atomic failures, historical replay. Exhaustive small-graph oracle checks matching coverage and maximum assigned drivers. No deployment or real event confirmation.
