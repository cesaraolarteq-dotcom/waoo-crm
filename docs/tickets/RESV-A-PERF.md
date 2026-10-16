# RESV-A-PERF — Bound reservation conflict queries without losing neighbors

Status: open; non-blocking follow-up from Claude's PR #22 review (HANDOFF entry reviewing 6f973c0). Parent: RESV-A-01. Implementer: Codex; reviewer: Claude.

Problem: ConfirmBooking currently reads all reservations for each selected resource. At the current volume this is acceptable; growing history increases memory, query work and lock duration.

Scope: query relevant overlapping intervals and the nearest preceding/following commitments efficiently, using suitable indexes and the existing stable resource locks. A simple date cutoff is insufficient: keep long events that start outside a window and the neighbors needed for travel checks even when days apart. Do not delete historical records or change availability policy.

Acceptance/tests: same decisions and messages for existing conflict/travel scenarios; large historic fixture set does not load unrelated rows; long overlapping event, distant nearest neighbor, preparation crossing a date boundary and real competing MariaDB transactions remain covered. Compare query volume/memory before/after with representative fixtures. Document the window/query strategy in RESERVATION_PROTOCOL.

Files owned: ConfirmBooking and shared schedule queries/index migrations plus corresponding tests; coordinate any shared CoreController block/trip queries. No UI changes required.

Rollback: revert the optimization normally, keeping the prior correct scan and history. No data deletion.
