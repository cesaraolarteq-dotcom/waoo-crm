# EXTRAS-01 · Client adds catalog extras from the portal (design, before code)

2026-11-02 · Claude (covering for Codex until 2026-11-04). Design only: no code, route, flag or migration yet.

## Owner decisions (César, 2026-11-02)
- Up to **7 days before** the party, without owner approval **if the whole team still fits** (people, costumes, equipment, drivers, the 4-at-once / 10-per-day limits).
- **The extra is added to the balance** (no separate payment at that moment). The deposit already paid is not recalculated; the balance and its 24 h-before due date follow the new total.
- **Acceptance by checkbox (2026-11-02, replaces a drawn signature):** the full annex text is shown; the client ticks "Leí y acepto agregar este extra. Entiendo que no se puede cancelar ni reembolsar." and presses "Agregar a mi fiesta" (disabled until ticked). Evidence: name, email, time, IP, user agent and SHA-256 of the exact rendered annex text/PDF (UETA/E-SIGN clickwrap). A PDF copy of the annex is emailed (when mail is on). EN + ES.
- **Party cancelled later:** the extra is lost like the deposit (no refund of the extra).

- **Offered extras (confirmed 2026-11-02):** "Pintacaritas agregado a un paquete", "Cañón de espuma", "Photo Booth", "Glitter Mobile", "Estación de slime", "2 chisperos"/"4 chisperos", "Personajes inflables y bolas disco". Stored as `service_variants.offered_as_extra` (owner-editable in Catálogo).
- **Additional characters are also an extra (2026-11-02):** the client may add characters to the booked package (existing `event_services.extra_characters` / `extra_character_cents`, current catalog extra-character price), with costume + wearer capacity checked like any extra.
- **Final, non-cancellable (2026-11-02):** once the annex is signed the extra belongs to the event; the client cannot cancel or get a refund for it later, because it blocks that service/costume for other clients. Event cancellation rules for the original package are unchanged; the extra amount follows the deposit rule of non-refundable. The annex and the portal must say this before signature.
- **Extras run at the same time as the booked service; they never extend the party.** The extra's busy window is the party's own window (its setup/breakdown inside the party's), and capacity is checked for that overlap. The portal, the annex and the email must say it, e.g. ES "El extra se realiza al mismo tiempo que tu servicio; no extiende la hora de la fiesta." / EN "The add-on runs at the same time as your service; it does not extend the party time." An extra longer than the party is not offered.

## Annex text (approved 2026-11-02)
ES: Anexo al contrato · Fiesta del [fecha]. Agregas: [extra o personaje adicional] por $[monto]. El extra se realiza al mismo tiempo que tu servicio; no extiende la hora de la fiesta. Al aceptar, el extra queda agregado a tu evento y no se puede cancelar ni reembolsar, porque reservamos ese servicio y ya no se ofrece a otros clientes. Nuevo total: $[total]. El monto se suma a tu saldo, que vence 24 horas antes de la fiesta. Todo lo demás de tu contrato sigue igual.
EN: Contract addendum · Party on [date]. You are adding: [add-on or additional character] for $[amount]. The add-on runs at the same time as your service; it does not extend the party time. Once you accept, the add-on is part of your event and cannot be cancelled or refunded, because we reserve that service and no longer offer it to other clients. New total: $[total]. The amount is added to your balance, due 24 hours before the party. Everything else in your contract stays the same.

## Flow (proposed)
1. Portal "Agregar extras" (only `responsible_system=crm`, status `capacity_reserved`, start ≥ 7 Houston days away, no pending reschedule/cancel, no open payment exception, contract signed).
2. Client picks one or more extras → server prices them from the **current** price version (extras are new sales; the package keeps its agreed price) and runs `AdmitEventCapacity::state` for the party **with the extras added**, under the day locks. Only `available` continues; anything else: "Escríbenos" (owner decides).
3. Acceptance and application happen in one request: the capacity check, the acceptance evidence and the event/finance update commit together (no pending hold needed, since accepting is immediate).
4. Client accepts the annex (checkbox) → in one transaction: event services appended, new `FinancialRevision` (origin `extras_annex`, previous lines unchanged, new lines appended, deposit target unchanged, balance due 24 h before), resources for extras reserved/held, audit. No money is collected here; the existing balance link/reminder shows the new balance.

## Guards
- Lock order: global DailyCapacity → event → change → capacity days → characters → resources (same as AdmitEventCapacity).
- Never reprice the original package; never lower the deposit; a received deposit is never refused.
- Imported (HoneyBook) parties: not offered until the C3 bridge is active for them.
- Owner sees the request in Inicio + the event timeline; can cancel a pending extras change before signature.

## Depends on
- #167 (CHANGE-A storage/annex signing) merged; #171 for the notice email when mail is on.
