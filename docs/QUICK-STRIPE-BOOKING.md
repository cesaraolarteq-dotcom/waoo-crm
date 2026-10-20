# Reserva rápida con pago por Stripe — owner decisions (2026-10-20)

Owner request: same as Reserva rápida, but instead of recording a Zelle deposit he sends a link; the client pays with Stripe
(card, Klarna, Afterpay, Zip) and the booking confirms itself when Stripe confirms the payment. Today he builds a Stripe
payment link by hand (amount + 3 % + a memo for control).

## Owner decisions (verbatim intent)
1. **Amount:** default = 50 % of (package/extras + travel fee), plus 3 %. Owner can switch to 100 % per booking (clients
   paying with Klarna/Afterpay/Zip often prefer one payment). Choice: 50 % | 100 %.
2. **Hold while unpaid:** the date and the costume are held for **48 hours** from link creation. At **24 hours**, if unpaid,
   the client gets a reminder. At 48 h unpaid, the hold is released (the event stays as "Pago no recibido", never deleted).
3. **3 %:** applies to everything paid through Stripe. It is added to the amount Stripe charges (no Stripe surcharge
   feature); Stripe just charges the final amount.

## Flow (proposal)
- Reserva rápida gets "¿Cómo paga?": *Ya recibí Zelle* (today) | *Cobrar con Stripe*. Stripe mode hides the deposit
  section and shows "Cobrar 50 % / 100 %" with the live amount: base, 3 %, total to charge.
- Save creates customer + draft event + financial revision + the 48 h hold (costume hold API, item 3) + a portal grant whose
  first step is **Pagar**. Owner gets the (short) link and the ready WhatsApp/SMS message. Event state "Esperando pago" with
  the hold expiry visible.
- Portal: party summary, the amount, a visible line "Cargo por pago con tarjeta o financiamiento (3 %)", button Pagar →
  Checkout Session created on click (sessions expire in 24 h; the portal link lives longer). Allowed methods: card, Klarna,
  Afterpay, Zip (no Affirm). Statement descriptor/metadata: `WP-<event id>` + package + date + client.
- Verified webhook → receipt recorded (gross incl. 3 %; principal vs customer fee kept separate) → booking "Depósito recibido",
  hold promoted to the normal reservation path; the client continues to details + contract in the same portal.
- Payment after 48 h: money is never lost; if the costume/date is still free it confirms, otherwise owner review (no auto refund).
- Reminder at 24 h: through MAIL (owner-approved template) when sending is enabled; until then, the event shows
  "Enviar recordatorio" with the WhatsApp/SMS text for the owner to send.

## Before real charges (Claude's notes for the owner/Codex)
- The client must see the 3 % before paying (card-network surcharge rules require disclosure). Klarna/Afterpay/Zip merchant
  terms may prohibit surcharges: confirm each before live mode. Test mode first.
- Everything stays sandbox: no live keys, no real sends until the owner approves.

## Follow-up decision (2026-10-20)
Owner asked whether to charge sales tax instead of the 3 %. Answer given: tax is not a substitute (it is remitted to the
state and would apply to Zelle too); whether his services are taxable in Texas is a question for his accountant, separate
from this feature. **Decision: keep the visible 3 % on Stripe (option 1).** Fallback if Klarna/Afterpay/Zip terms forbid
surcharges: published card price + 3 % Zelle discount (owner would have to approve new website prices).
