# AVAIL S2 resource-pool solver, internal contract

Implements César's 2026-10-28 pool decision. This is an internal pure capacity
subproblem, not an endpoint, hold, assignment or completed availability result.
No real inventory count is supplied by this document or a default.

`PoolCapacitySolver::solve(resources, jobs, blocks)` returns
`scope=resource_pools`, `state=feasible|infeasible|unknown`, and an internal
`witness` only for feasible results. Never put the witness/resource identifiers in
client JSON. A pool-feasible result alone must never become public available.
Location/traffic, origin/return, costume-wearer compatibility, cargo, day cap,
notice, snapshot freshness and final transactional admission remain outer checks.

Resources are distinct anonymous physical units: integer id, kind
(staff/equipment/costume/vehicle), driver boolean for staff, positive seats for
vehicles. Jobs have a unique string key, integer UTC interval [starts_at,ends_at),
minimum_drivers and expanded slots. Each slot has a job-local unique string key,
a kind and an eligibility list of physical resource IDs compiled from the
versioned recipe/skills/character/outfit evidence. Existing fixed reservations
use singleton eligibility, never silently reassign the owner's crew. Incomplete
recipes or eligibility must stay unknown in the snapshot compiler, not become an
empty 'impossible' pool. Skills overlapping on one person never create two people.

The caller extends busy intervals with known setup/breakdown. The solver applies
active time-off/resource blocks and joint temporal assignment: a resource covers
one slot at a time but may cover nonoverlapping jobs. Adjacent endpoints do not
overlap in the pool subproblem; actual transfer feasibility is a separate required
check. Drivers come from staff actually selected for that job, not extra duplicated
headcount. When vehicles are requested, assigned seats cover assigned staff and
selected drivers cover the number of vehicles. Candidate and existing demands
must be solved together; independent successful checks do not suffice.

Bounded exact search distinguishes exhaustive impossibility from budget exhaustion.
Malformed/incomplete input or limits return unknown with an internal reason code,
never fabricated counts or infeasible. Limits are engineering bounds, not owner
staffing rules: 256 units,64 jobs,512 slots,4096 blocks,50,000 search states and
200ms default. Caller-wide provider/read budget must further bound a full request.
No database/model/provider/cache/clock-calendar writes, dependencies or public
route changes. A successful witness is temporary proof only. Actual counts,
weekly availability, routes and all combined S2 evidence are still needed before
website activation; current public S1 fallback remains conservative.

## Actual CRM inventory, not manual duplicate counts

César confirmed on2026-10-29 that staff and positions/skills are already registered
in the CRM. `ReadPoolInventory` (prepared locally, not included in this solver support delivery) reads those existing active Resource rows, active
time-off blocks and character/outfit metadata. It never asks the owner to re-enter
counts, reads personal staff data, or creates a parallel staffing table. It selects
no names/contact/address/block-note fields. Eligibility comes from persisted
skills, equipment kind, character and physical wardrobe rules (including outfit0).

This inventory hash covers inventory/time-off only. It must NOT be used as an
entire availability generation marker: bookings, leases, unassigned commitments,
recipes and logistics need the complete outer snapshot. Resource reservations are
not time-off rows and must be supplied as fixed commitments by the outer reader;
an inventory read is never a free-calendar verdict. All reads are transactional,
no FOR UPDATE/global admission lock and no domain/provider/cache writes.
