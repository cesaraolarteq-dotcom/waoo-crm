# RESV-A-PERF — Bound reservation conflict queries without losing neighbors

> Integration status 2026-10-17: REPORT-A retains the modern locking full-scan ConfirmBooking. ReservationNeighborhood is present/tested as an isolated helper but is **not called by confirmation**. Its old nonlocking reads must be redesigned and reviewed against repeatable-read concurrency before enabling RESV-A-PERF. Historical implementation notes below describe the older branch, not the active confirmation path.

Status: implemented on `codex/reservation-neighborhood`, awaiting Claude review/integration; non-blocking follow-up from Claude's PR #22 review (HANDOFF entry reviewing 6f973c0). Parent: RESV-A-01. Implementer: Codex; reviewer: Claude.

Problem: ConfirmBooking currently reads all reservations for each selected resource. At the current volume this is acceptable; growing history increases memory, query work and lock duration.

Scope: query relevant overlapping intervals and the nearest preceding/following commitments efficiently, using suitable indexes and the existing stable resource locks. A simple date cutoff is insufficient: keep long events that start outside a window and the neighbors needed for travel checks even when days apart. Do not delete historical records or change availability policy.

Acceptance/tests: same decisions and messages for existing conflict/travel scenarios; large historic fixture set does not load unrelated rows; long overlapping event, distant nearest neighbor, preparation crossing a date boundary and real competing MariaDB transactions remain covered. Compare query volume/memory before/after with representative fixtures. Document the window/query strategy in RESERVATION_PROTOCOL.

Files owned: ConfirmBooking and shared schedule queries/index migrations plus corresponding tests; coordinate any shared CoreController block/trip queries. No UI changes required.

Rollback: revert the optimization normally, keeping the prior correct scan and history. No data deletion.


Implementation contract and measured limitations: [RESERVATION-NEIGHBORHOOD.md](../RESERVATION-NEIGHBORHOOD.md). Existing resource/start/end index is retained; no schema/data migration. Three bounded SELECTs replace two unbounded eager-load queries. At most two event models, zero reservation models; SQL overlap checking can still scan rows inside MariaDB. Tests compare the old scan, retain real concurrent confirmation checks and cover both supported SQL dialects. Full delivery evidence and fictional benchmark results are recorded in HANDOFF and the PR review document.
