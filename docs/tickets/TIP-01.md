# TIP-01 — Card tips after the party (owner decision 2026-10-18)

**Owner (César):** instead of Zelle, a Stripe tip with buttons **5 %, 10 %, 15 % or another amount**, link generated automatically. **Waoo absorbs card fees**; clients are never told about fees. **Card only** (no Zelle line). 100 % of the tip goes to the party's staff.

UI preview (Claude, done): `/dev/portal/tip`, `/dev/portal/tip-thanks`, `/dev/portal/tip-closed` (`resources/views/portal/tip.blade.php`, `App\Support\TipCopy`), and the thank-you email's second button "Dejar una propina / Leave a tip" (`MailTemplatesDraft`, `mail/layout.blade.php` → `button2`).

## Backend for Codex (publish the API before code)

- **Access:** a tip grant per event + customer (scope `tip.create`, no other scope), issued only for a `capacity_reserved`, non-cancelled event whose party has ended; valid **14 days after the party**; revocable. Raw link shown once / put in the thank-you email as `tip_link` (server-built, never from the browser).
- **Page projection (whitelist):** customer first name, party date (Houston), team **first names** for that event, party **total** (current finance revision total), options computed server-side: 5/10/15 % of total, **rounded to whole dollars** (half up), each with a stable key `pct_5|pct_10|pct_15`; state `open|paid|closed`. Never fees, never staff pay, never ledger.
- **Checkout:** POST with `option` (`pct_5|pct_10|pct_15|custom`) and `custom_amount` (USD, $1–$2,000, 2 decimals) + Idempotency-Key. Server recomputes the amount from the pinned finance revision and option; creates a Stripe Checkout session (test mode only until owner activation) with `metadata: kind=tip, event_id, grant_id`; line item "Propina para el equipo / Tip for the team". No fee line, no surcharge.
- **Recording:** webhook (existing verified Stripe webhook path) records a **separate ledger kind `tip`** (or a separate `tips` table) tied to the event: **not revenue, not a payment toward the balance, never changes balance/deposit**, excluded from "Dinero que entró" in REPORT-A (show as its own line "Propinas para el staff"). Stripe fee is recorded as a Waoo expense when known; the staff amount is the gross tip.
- **Several tips allowed** per event (each its own session/idempotency key). Refund of a tip = owner action, audited.
- **Distribution:** STAFFPAY-01 later splits each event's tips among that event's assigned staff (rule from César: equal split unless he says otherwise — ask). Until then tips are listed per event for the owner.
- **Mail:** `ApprovedMail` accepts an optional second button (`button2`, variable `tip_link`) only for `thank_you_review`; if the tip grant cannot be issued, the email goes out with only the review button (never a broken link).
- **Tests:** amount tampering (browser amount ignored), out-of-range custom, closed/revoked/expired grant, event not ended/cancelled, webhook replay idempotent, tip never alters balance or reports' cash-in, privacy of the projection (no fees/pay/ledger), ES/EN.

Nothing real: test mode only, no live charges until César activates it.
