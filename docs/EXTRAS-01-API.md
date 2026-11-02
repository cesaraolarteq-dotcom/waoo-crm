# EXTRAS-01 · Client adds catalog extras from the portal (design, before code)

2026-11-02 · Claude (covering for Codex until 2026-11-04). Design only: no code, route, flag or migration yet.

## Owner decisions (César, 2026-11-02)
- Up to **7 days before** the party, without owner approval **if the whole team still fits** (people, costumes, equipment, drivers, the 4-at-once / 10-per-day limits).
- **The extra is added to the balance** (no separate payment at that moment). The deposit already paid is not recalculated; the balance and its 24 h-before due date follow the new total.
- **The client signs an annex** (same in-house e-signature as the contract: name, email, time, IP, user agent, SHA-256 of the exact annex PDF). EN + ES.

- **Offered extras (confirmed 2026-11-02):** "Pintacaritas agregado a un paquete", "Cañón de espuma", "Photo Booth", "Glitter Mobile", "Estación de slime", "2 chisperos"/"4 chisperos", "Personajes inflables y bolas disco". Stored as `service_variants.offered_as_extra` (owner-editable in Catálogo).
- **Extras run at the same time as the booked service; they never extend the party.** The extra's busy window is the party's own window (its setup/breakdown inside the party's), and capacity is checked for that overlap. The portal, the annex and the email must say it, e.g. ES "El extra se realiza al mismo tiempo que tu servicio; no extiende la hora de la fiesta." / EN "The add-on runs at the same time as your service; it does not extend the party time." An extra longer than the party is not offered.

## Pending owner input
1. Annex text (EN/ES) — Claude drafts, César approves.

## Flow (proposed)
1. Portal "Agregar extras" (only `responsible_system=crm`, status `capacity_reserved`, start ≥ 7 Houston days away, no pending reschedule/cancel, no open payment exception, contract signed).
2. Client picks one or more extras → server prices them from the **current** price version (extras are new sales; the package keeps its agreed price) and runs `AdmitEventCapacity::state` for the party **with the extras added**, under the day locks. Only `available` continues; anything else: "Escríbenos" (owner decides).
3. Server creates a pending `event_change` kind `extras` (reuses CHANGE-A storage from #167) with the frozen lines, new total, and the annex PDF; capacity for the extras is **held** (pool demand) until signed or expired (48 h, never past the 7-day cutoff).
4. Client signs the annex → in one transaction: event services appended, new `FinancialRevision` (origin `extras_annex`, previous lines unchanged, new lines appended, deposit target unchanged, balance due 24 h before), resources for extras reserved/held, audit. No money is collected here; the existing balance link/reminder shows the new balance.
5. Unsigned at 48 h or at the 7-day cutoff → the change expires and the held demand is released.

## Guards
- Lock order: global DailyCapacity → event → change → capacity days → characters → resources (same as AdmitEventCapacity).
- Never reprice the original package; never lower the deposit; a received deposit is never refused.
- Imported (HoneyBook) parties: not offered until the C3 bridge is active for them.
- Owner sees the request in Inicio + the event timeline; can cancel a pending extras change before signature.

## Depends on
- #167 (CHANGE-A storage/annex signing) merged; #171 for the notice email when mail is on.
