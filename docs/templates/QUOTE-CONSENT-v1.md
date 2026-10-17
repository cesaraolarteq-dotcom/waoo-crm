# Quote decision consent — v1 DRAFT for César's approval

Status: **draft by Claude, not approved, not active.** Used by the client quote page (/portal/quote): the client must check the box with this exact text before "Aceptar" or "No me interesa". Once a quote is sent, its text is frozen with that quote; changing the wording later needs a new version and only affects new quotes. Config key `waoo.quote_acceptance_consent` (`version` + `es`/`en` → `accepted`/`rejected`), plain text, no placeholders.

Based only on owner rules (CLAUDE.md): deposit 50 % of package + travel fee, non-refundable; balance due 24 h before the event; contract = the owner's contract, signed separately. Accepting a quote is **not** the contract signature and does not reserve the date.

Version: `quote-consent-v1-2026-10-17`

## Accept

**ES:** Acepto esta cotización de Waoo Party con los servicios, la fecha, el lugar y el total que aparecen arriba. Entiendo que aceptarla todavía no aparta mi fecha: la reserva se confirma cuando Waoo Party recibe el depósito del 50 % (paquete + costo de transporte), que no es reembolsable, y yo firmo el contrato. El saldo se paga a más tardar 24 horas antes de la fiesta.

**EN:** I accept this Waoo Party quote with the services, date, place and total shown above. I understand that accepting does not reserve my date yet: the booking is confirmed when Waoo Party receives the 50% deposit (package + travel fee), which is non-refundable, and I sign the contract. The balance is due no later than 24 hours before the party.

## Reject

**ES:** No acepto esta cotización. Entiendo que esto no me compromete a nada y que puedo pedirle a Waoo Party una cotización nueva cuando quiera.

**EN:** I do not accept this quote. I understand this does not commit me to anything and that I can ask Waoo Party for a new quote at any time.

## Owner checklist

- [ ] Wording OK in Spanish and English (or tell Claude what to change).
- [ ] Optional: your lawyer reviews it together with the contract.
- [ ] After approval Claude puts it in the configuration with this version; nothing changes for quotes already sent.
