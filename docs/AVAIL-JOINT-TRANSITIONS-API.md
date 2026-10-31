# Joint resource transitions — 2026-10-30

Internal solver extension, no public identifiers: optional job `required` lists units that must be used by that job, without greedily binding them to role order. Optional solve fourth argument `separations` lists `{first, second, resources}`: these two jobs may not reuse any listed unit. Invalid evidence returns unknown.

The read evaluator first solves physical and people demand. It then prohibits reuse between every pair of parties in the allocation (including existing unassigned teams) unless time-specific travel evidence proves the gap from breakdown-end to setup-start, including30 extra minutes. Missing proof means owner review if no independent team can cover it; it never becomes physical impossibility solely because the route is unknown. No60/120minute cutoff can prove a route. Existing pinned units remain required; roles may be matched jointly inside that team.

ZIP routing is an estimate, not door-to-door proof. No provider is called under transaction locks. Final payment admission still needs a durable whole-team hold and current versions; a read witness alone never permits a charge.

A pinned unit absent from the recipe remains an explicit occupied slot, so it cannot bypass transfer constraints. Unknown routes prohibit sharing, not independent teams. Exact-location Google evidence is keyed by both locations and departure timestamp, cached for10minutes, and ceils fractional seconds to minutes. The budget remains one0.9second uncached lookup for web requests; reschedule preview allows three2second lookups. ZIP-to-ZIP remains an estimate method, not operational proof. ConfirmBooking requires breakdown + actual drive + max(30, recorded contingency) + setup.

Calendar timestamps no longer collapse an external entry into an imported party. Main #166 adds verified feed/UID-to-import bindings (docs/CALENDAR-IDENTITY-API.md). Only those bindings with current matching times deduplicate; actual private reconciliation/UI remains open. Unbound entries are retained conservatively. This can require owner review or count an unreconciled duplicate twice, intentionally avoiding a false free slot.
