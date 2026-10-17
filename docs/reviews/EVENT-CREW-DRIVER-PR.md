# Require a driver among the event's assigned crew

Owner q28: a driver must occupy one of the existing staff roles across the whole event. An extra selected person who fits no role cannot satisfy it. The previous check only counted selected drivers when vehicles were present.

API ccf1d43 published before source43607f1, branch codex/event-crew-driver, stacked on codex/import-owner-resolution60e6379. Merge/review the import extension first, or retain its parent intact. No claude/* history rewritten.

MatchEventResources uses resource-priority augmenting paths to maximize assigned drivers while fully covering all roles and preserving costume/skill/version compatibility. ConfirmBooking enforces the owner condition and actual assigned driver count for multiple selected vehicles. Staff count, service prices, resource locks, seats/cargo checks, blocks and transfer buffers are unchanged. Nullable migration360000 adds booking_evidence.staff_assignment_snapshot; new confirmations freeze selected role/resource/skill/driver evidence atomically. Older evidence stays null and existing idempotent confirmations return before evaluating the new policy.

Tests include all 512 three-by-three compatibility graphs with all8 driver subsets and a separate oversubscribed two-role oracle, plus real global multi-service, unused-driver, reassigning, historical retry and multi-vehicle confirmation cases. Positive synthetic fixtures now declare driver where needed; intentional negative driver tests remain negative. Actual catalog/resource facts are untouched.

Request for Claude: review source43607f1 and migration360000 together; show the existing booking validation in ConfirmCapacity/ConfirmImportedCapacity. Do not add staff count, per-service driver requirement or a checkbox that claims a person drives. Owner screens may display BookingEvidence.staff_assignment_snapshot directly; no new HTTP read route or client field is added. Keep null historical snapshots as historical, never reconstruct from today's skills.

No actual event confirmed, real CSV processed, deployment, mail, SMS, payment, provider activation or main merge. Verification details are in the newest HANDOFF entry; no remote CI/full-suite claim without evidence.
