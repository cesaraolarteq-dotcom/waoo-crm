# Quick booking without a received deposit (César, 2026-10-21)

Owner request, in his words: "que el zelle no sea obligatorio recibirlo; sea opcional, si se recibió se coloca cuánto. Si envío el link
el cliente recibe el link y llena sus datos personales y si no ha pagado tiene el monto total, el monto del depósito y puede pagar con
Zelle que verificaremos luego o tiene la opción de pagar con Stripe si no se marcó el Zelle como recibido anteriormente."

## What the owner wants

1. **Quick booking, section 4** becomes a choice: "¿Ya recibiste el depósito?" Sí / Todavía no.
   - **Sí** = today's flow (amount, optional reference, day, "lo vi reflejado en el banco") → real receipt, paid hold.
   - **Todavía no** = no receipt, no amount fields. Booking saves, link is created, unpaid.
2. **Client data is already optional** (merged in claude/walkthrough-4): the owner may send the link with no name/phone/email.
3. **Client portal, unpaid booking:** after "Tus datos" (all required) the client sees the **total**, the **deposit (50 %)**, and two ways to pay:
   - **Zelle**: instructions + "Ya lo envié" notice (existing ZelleNotice flow). The owner verifies it later; nothing is confirmed by the notice.
   - **Stripe** (card/Klarna, +3 % shown visibly, 50 % default with 100 % option) = the Quick Stripe flow already specified (S1–S3).
   - If the owner marked the Zelle as received, the Stripe/Zelle deposit choice does not appear (only the balance, as today).
4. Owner decisions already given still apply: 48 h hold of date + costume for an unpaid booking, 24 h reminder if unpaid, the booking confirms
   on a verified payment (Stripe webhook or owner-verified Zelle), 3 % only on Stripe.

## Notes for Codex

- This is the same unpaid path as the Quick Stripe lease (S2/S3): an unpaid quick booking is the lease; a Zelle notice must not create a receipt
  or extend the lease by itself — the owner verifying it does (then the paid hold replaces the lease).
- Keep the paid quick booking path unchanged. Never fabricate an owner attestation for an unpaid booking.
- Please deliver backend + screens (section 4 toggle, portal payment step) and tell Claude when ready; Claude reviews/integrates and opens the PR.

## Owner clarification (same day)

"En caso de no marcar el pago como recibido, en el portal del cliente debe decir cuánto debe pagar el cliente y si lo hace por Zelle marcar
como pagado por Zelle y si es por Stripe, Stripe recibir el pago y mandar la notificación automáticamente, y si es Zelle debe estar pendiente
por aprobación pero el cliente debería poder seguir con los siguientes pasos."

- The portal states clearly **how much to pay now** (deposit) and the total.
- **Zelle:** the client taps "Pagué por Zelle" (amount, optional reference). The booking shows **"Zelle pendiente de aprobación"** for the
  owner (dashboard + event file) until the owner verifies it. **The client is NOT blocked:** she continues to the next steps (contract,
  signature) right away. The notice is not a receipt; only the owner's verification records money and confirms.
- **Stripe:** Stripe takes the payment; the verified webhook records it and the **notification goes out automatically** (owner alert +
  client confirmation, using the approved texts). No owner action needed.
- In both cases the 48 h hold / 24 h reminder apply until a payment is verified (Stripe webhook or owner-verified Zelle). A pending Zelle
  notice keeps the booking from expiring silently: flag it for the owner instead of releasing the date without review.
