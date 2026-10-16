# ANNIV-01 — "Un año después" rebooking email (owner request, 2026-10-15)

Status: **spec draft, open questions below.** Not in Phase 1. Proposed for Phase 2 (automations), after MAIL-01 and the event history exist. Implementer: Codex (scheduler, eligibility, codes); Claude (email EN/ES, admin screen, tests of the screen).

## Owner's idea (his words, summarized)
If a client booked us last year for, say, Sept 17, the birthday child probably has a birthday around that date. About 4–6 weeks before the 1-year mark, send: "We'd love to be part of the celebration again" + a **unique 10% discount code** just for that client. **Send only if that client has NOT booked again.** Someone who already rebooked (e.g. 3 months ahead) must not receive it, so nobody asks to apply the discount to a booking already made.

## Rules (proposed)
1. **Anchor:** the client's most recent event that actually happened (status completed, not cancelled), in America/Chicago.
2. **Send window:** one email per client per anchor, on day **[OWNER: 46–48 weeks after the event (4–6 weeks before the anniversary) or 48–50 weeks (2–4 weeks before)]**. Daily job; sends at ~10:00 am like the thank-you email.
3. **Do not send if, at send time, the client:**
   - has any event dated after the anchor (booked, pending deposit, or draft with deposit) — i.e. already rebooked;
   - already received this email for this anchor;
   - unsubscribed from promotional emails, or has no email;
   - the anchor event was cancelled, or was flagged "do not contact" by the owner.
   Checked again right before sending (not only when queued).
4. **Event types:** only events that point to a birthday **[OWNER: birthdays only, or also others?]**. Baby showers, gender reveals and corporate events have no yearly repeat.
5. **Code:** unique per client and anchor, random and hard to guess (e.g. `WAOO-7KX9Q`), single use, 10% off. It is the owner's own promotion (CLAUDE.md: promotions are never automatic unless the owner defines them; this one he defined).
   - Applies to **[OWNER: package/services only, not the travel fee — recommended]**.
   - Valid until **[OWNER: e.g. 60 days after sending, or until the anniversary date]**.
   - Not combinable with other discounts **[OWNER confirm]**.
   - Redeemed only on a **new** booking created after the email was sent; the CRM refuses it on an existing booking (this is the owner's main concern).
6. **Language:** the client's language (ES/EN). Copy in `docs/templates/client-messages.md` once approved.
7. **Law/deliverability:** promotional email ⇒ unsubscribe link + business postal address (CAN-SPAM). Sender sales@waooparty.com. No SMS unless the client opted in to texts (TCPA) — email only at first.
8. **Admin view:** "Un año después" list: who gets it this week, who was skipped and why (already rebooked, unsubscribed…), codes sent, codes used, and bookings that came from it. The owner can pause the whole campaign or one client.
9. **Old clients:** the ~250 HoneyBook clients can feed this after migration, if their event dates are imported. **[OWNER: include them?]**

## Draft copy (to approve)
- ES subject: "¡Ya casi es un año de la fiesta de {{child_name}}! 🎈"
- ES body: "Hola {{first_name}}: hace casi un año celebramos a {{child_name}} y nos encantaría volver a ser parte de su celebración. Como agradecimiento, aquí tienes un código exclusivo para ti: **{{code}}**, con 10 % de descuento en tu próxima reserva (válido hasta el {{expires}}). ¿Revisamos disponibilidad para la fecha?"
- EN subject: "It's almost a year since {{child_name}}'s party! 🎈"
- EN body: "Hi {{first_name}}, almost a year ago we celebrated {{child_name}}, and we'd love to be part of the celebration again. As a thank-you, here's a code just for you: **{{code}}**, 10% off your next booking (valid until {{expires}}). Shall we check availability for the date?"

## Acceptance tests (to write)
- Client with event 47 weeks ago and no new event → 1 email + 1 code.
- Same client with a new booking created 3 months ahead → no email.
- Client rebooks after the email → code works once on that new booking; refused on a booking created before the email.
- Cancelled anchor, unsubscribed, no email, non-birthday type → no email.
- Job runs twice the same day → still 1 email (idempotent).
- Two children / two events in a year → anchor is the latest event; one email per anchor.

## Open questions for the owner
1. Exact timing: 4–6 weeks before the anniversary (46–48 weeks after) or 2–4 weeks before (48–50 weeks)? Recommended: 6 weeks before, so families still have time to plan.
2. Birthdays only?
3. 10% on package/services only, or also on the travel fee?
4. How long is the code valid?
5. Include migrated HoneyBook clients?
