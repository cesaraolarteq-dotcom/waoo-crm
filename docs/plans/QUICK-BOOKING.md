# "Reserva rápida": the owner's real booking flow, automated (owner request 2026-10-15)

## How Waoo books today (≈90% of bookings, by phone/WhatsApp)
1. César quotes the client by hand (conversation).
2. The client pays the deposit (mostly Zelle).
3. César asks the client for her data.
4. César retypes everything into HoneyBook: package, characters, date, time, duration, package price, travel fee, deposit and when it was paid, next payment and its due date, client's full name, phone, email and event address. Then he sends the contract.

## Target flow in the CRM (two halves, then everything is automatic)
**Owner's half (≈1 minute in the CRM, "Nueva reserva"):**
- Pick the **package** from the catalog → price, duration and includes fill in automatically.
- **Characters** (only if the package includes them) + extras.
- **Date**, and optionally the start time if already agreed.
- **Travel fee**: automatic by ZIP (website formula) or typed by hand.
- **Deposit received**: amount, method (Zelle/card/cash), date → the system computes **total, balance and the balance due date** (24 h before), and records the real amount (D3).
- The CRM creates a **client link** and shows a **"Copiar enlace"** button plus a ready text for WhatsApp/SMS in ES or EN (most of these clients are in a direct conversation).

**Client's half (her phone, 2 minutes, EN/ES):**
- Full name, phone, email, **event address**, **service start time** (pre-filled if the owner set it; she confirms), child's name/age/theme (optional), notes.
- Reviews the contract **already filled with both halves** and **signs** (in-house signature, D4).

**When both halves are complete** → the contract PDF is generated and signed, the event appears in the calendar, a receipt/confirmation goes to the client, reminders and the balance notice are scheduled, and the Monday report counts it. If the client doesn't finish within N days, the CRM reminds her and shows "datos pendientes" on the owner's home screen. The date is already held because the deposit was received (D7).

## Notes
- This is the **deposit-first flow** (v2 §7: "historical flow; keep the booking and show contract pending"). It coexists with the online flow (quote → sign + pay) for website leads.
- Conflict protection still applies: when the owner saves the reservation with crew/costumes, RESV-A-01 checks them (and warns if the date is fully booked).
- The owner can edit his half until the client signs; after signing, changes go through an addendum (TPL-01/CHANGE-A).
- **Priority:** this replaces most of the daily HoneyBook typing, so it should be the **first client-facing feature of Track A** (ticket BOOK-QUICK, early in Phase 4, before the full online portal).
