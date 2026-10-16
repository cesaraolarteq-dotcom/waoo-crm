# ROUTE-01 calibration cases (owner data)

`ROUTE-01-reference-22.json` = the 22 reference ZIPs the owner used on 2026-10-14 to calibrate the website travel fee (website commit a52727d, “2 of 22 reference ZIPs differ by more than $10 (owner accepted)”).

Per case: owner's real historical price, every Google Routes alternative measured that day (one-way miles **rounded to 0.1**, minutes, one-way toll in USD or null when Google returned no toll info), the website's route choice and the fee from the website's own `waoo_tf_fee()`.

Use for ROUTE-01 parity: given the same selected route (miles, toll) the CRM formula must return `expected_fee_usd` exactly (deterministic, no Google call). Special ZIPs (77433, 77355, 77380, 77013, 77573) use the fixed fee.

Note: recomputed from the stored, rounded measurements this gives **3** cases above $10 (77021 +15, 77583 +15, 77586 +20) instead of the 2 reported on the day, which used Google's exact distanceMeters. Treat `expected_fee_usd` (formula output) as the parity target, and `owner_price_usd` only as business context; do not tune rules to these three without the owner's approval.
