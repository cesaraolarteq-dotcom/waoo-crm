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
