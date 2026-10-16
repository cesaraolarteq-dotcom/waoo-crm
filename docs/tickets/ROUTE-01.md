# ROUTE-01 · Travel fee (costo de transporte) inside the CRM (Phase 2)

Status: backend source f31361c on codex/route-01, API published first97c84ac. Targeted45/490 and synthetic website-formula parity pass; final integrated LEAD/ROUTE/OUTFIT300/2864 pass on codex/outfit-01 d1b00a3.22 real calibration fixtures, Claude UI/review and real provider/staging validation remain pending. Default off, no live calls. See docs/ROUTE-01-API.md.

## Goal

The CRM computes the same travel fee the owner calibrated for the website (`travel-fee-lib.php` / `travel-fee-config.php`, owner decision 2026-10-14) — **from the exact event address**, not only the ZIP — and the owner confirms it before it becomes `travel_fee_cents`.

## Rules to port (single source of truth: the live website config)

- Origin ZIP 77493; Google Routes `computeRoutes` with TOLLS and the `US_TX_EZTAG` pass.
- Round trip under `best_route_under_round_trip_miles` (30): Google best route (tolls only if they help); otherwise the fastest toll route.
- Graduated tiers per slice of miles, round trip, + round-trip tolls; round to $5; 160-mile round-trip limit (beyond → “requiere revisión”); special fixed fees by ZIP (`travel-fee-zips.php`); non-Texas → outside.
- Copy the tier/rate values into CRM config as data, with a **parity test** against the 22 reference ZIPs the owner used to calibrate (expected website results), so the two never drift silently.

## Service (Codex)

- `TravelFeeQuote::forAddress(User $actor, string $address, ?string $zip): array` → `fee_cents|null`, `status` (ok|outside|review|unavailable), and an owner-only breakdown (miles, tolls, tier amounts, special-ZIP flag, route type). Cache 30 days per normalized address; daily Google call cap; Google key in CRM `.env` only.
- Failure or limit → `unavailable`: the UI shows “Pendiente”, **never $0**.
- Never writes the event by itself. The owner accepts (or edits) and the existing event/quick-booking save stores `travel_fee_cents` plus a snapshot of the calculation (inputs, result, rules version).

## Screens (Claude)

- Event form and Reserva rápida: “Calcular transporte” next to the address → shows the estimate and breakdown; “Usar este costo” fills the field; manual edit stays possible with a reason.
- Event file shows how the fee was obtained (calculated/edited) and when.

## Tests (minimum)

Parity with the 22 reference ZIPs; special ZIP wins; outside Texas; >160 mi → review; Google failure → unavailable (no $0); cache hit makes no call; daily cap respected.
