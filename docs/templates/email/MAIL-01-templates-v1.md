# MAIL-01 templates v1 (EN/ES) — DRAFT for owner approval

Status: wording draft by Claude for César. Not active. Variables in `{{ }}` are filled by the CRM; every variable is escaped. One link per message. Plain text first (HTML version = same text, Waoo logo header, one button). Subjects ≤ 60 characters. ES always says “costo de transporte”.

Variables (all declared; the renderer refuses unknown ones, escapes every value and formats money as $1,234.50):
`first_name` (≤20 chars in subjects), `event_date`, `address`, `package`, `package_price`, `travel_fee`, `subtotal` (package + travel), `discounts` (list of `{label, amount}`; only visible labels, never the private reason), `discount_total`, `total`, `deposit`, `amount` (this payment), `paid`, `balance`, `has_balance` (boolean, true only when balance > 0 — never test the formatted `$0.00`), `due` (balance due date), `valid_until`, `link` (portal), `review_link` (Google review), owner phone (346) 851-8830.

Subjects: rendered subject ≤ 60 characters with real data; `first_name` is cut to 20 characters in subjects only; tests cover long names and long package names in both languages.

---

## 1. inquiry_received — after a website request (LEAD-01)

**EN** · Subject: We got your request, {{first_name}}!
> Hi {{first_name}}! Thank you for contacting Waoo Party. We received your request for {{event_date}} and we're checking availability for your ZIP code now. We'll reply very soon with options and the complete price, including the travel fee.
> If you'd like to talk sooner, text or call us at (346) 851-8830.
> We're excited to be part of your celebration!
> — Waoo Party

**ES** · Asunto: ¡Recibimos tu solicitud, {{first_name}}!
> ¡Hola {{first_name}}! Gracias por escribir a Waoo Party. Recibimos tu solicitud para el {{event_date}} y ya estamos revisando la disponibilidad para tu código postal. Muy pronto te enviamos opciones y el precio completo, con el costo de transporte incluido.
> Si prefieres hablar antes, escríbenos o llámanos al (346) 851-8830.
> ¡Estamos emocionados de ser parte de tu celebración!
> — Waoo Party

## 2. quote_sent — QUOTE-01

**EN** · Subject: Your Waoo Party quote for {{event_date}}
> Hi {{first_name}}! Here is your quote for {{package}} on {{event_date}}:
> Package: {{package_price}} · Travel fee: {{travel_fee}}
> {{#discounts}}{{label}}: −{{amount}}
> {{/discounts}}**Total: {{total}}**
> To reserve your date we need a 50% deposit ({{deposit}}). Review and accept your quote here: {{link}}
> This quote is valid until {{valid_until}}. We're excited to be part of your celebration!

**ES** · Asunto: Tu cotización de Waoo Party para el {{event_date}}
> ¡Hola {{first_name}}! Aquí está tu cotización de {{package}} para el {{event_date}}:
> Paquete: {{package_price}} · Costo de transporte: {{travel_fee}}
> {{#discounts}}{{label}}: −{{amount}}
> {{/discounts}}**Total: {{total}}**
> Para apartar tu fecha pedimos un depósito del 50 % ({{deposit}}). Revisa y acepta tu cotización aquí: {{link}}
> Esta cotización es válida hasta el {{valid_until}}. ¡Estamos emocionados de ser parte de tu celebración!

## 3. details_request — quick booking link

**EN** · Subject: Finish your booking, {{first_name}}
> Hi {{first_name}}! Thank you for your deposit of {{paid}} — your request for {{event_date}} is recorded. To finish your booking, please complete your details and sign your contract here: {{link}}
> It only takes a few minutes. We're excited to be part of your celebration!

**ES** · Asunto: Termina tu reserva, {{first_name}}
> ¡Hola {{first_name}}! Gracias por tu depósito de {{paid}}: registramos tu solicitud para el {{event_date}}. Para terminar tu reserva, completa tus datos y firma tu contrato aquí: {{link}}
> Solo toma unos minutos. ¡Estamos emocionados de ser parte de tu celebración!

## 4. contract_ready

**EN** · Subject: Your contract is ready to sign
> Hi {{first_name}}! Your Waoo Party contract for {{event_date}} is ready. Read it and sign here: {{link}}
> There you can also see your balance ({{balance}}, due {{due}}) and how to pay. We're excited to be part of your celebration!

**ES** · Asunto: Tu contrato está listo para firmar
> ¡Hola {{first_name}}! Tu contrato de Waoo Party para el {{event_date}} está listo. Léelo y fírmalo aquí: {{link}}
> Ahí también ves tu saldo ({{balance}}, vence el {{due}}) y cómo pagarlo. ¡Estamos emocionados de ser parte de tu celebración!

## 5. payment_received — only after the owner verifies (Zelle) or Stripe confirms

**EN** · Subject: Payment received — thank you!
> Thanks so much, {{first_name}}! We received your payment of {{amount}}. Paid so far: {{paid}} · Balance: {{balance}}{{#has_balance}} (due {{due}}){{/has_balance}}.
> We're excited to be part of your celebration!

**ES** · Asunto: Pago recibido, ¡gracias!
> ¡Muchas gracias, {{first_name}}! Recibimos tu pago de {{amount}}. Pagado hasta hoy: {{paid}} · Saldo: {{balance}}{{#has_balance}} (vence el {{due}}){{/has_balance}}.
> ¡Estamos emocionados de ser parte de tu celebración!

## 6. zelle_notice_pending — after the client says “I sent my Zelle”

**EN** · Subject: We got your Zelle notice
> Hi {{first_name}}! Thanks for letting us know you sent {{amount}} by Zelle. As soon as we see it in our bank we'll confirm it. Until then your balance stays the same.

**ES** · Asunto: Recibimos tu aviso de Zelle
> ¡Hola {{first_name}}! Gracias por avisarnos que enviaste {{amount}} por Zelle. En cuanto lo veamos en el banco te lo confirmamos. Mientras tanto tu saldo no cambia.

## 7. balance_reminder — only if `has_balance`; sent 24 h **before the due date** (the due date itself is 24 h before the party, so this goes out ~48 h before the party)

**EN** · Subject: Friendly reminder: balance due {{due}}
> Hi {{first_name}}! Your party is coming up on {{event_date}}. Your balance of {{balance}} is due {{due}}. You can pay by Zelle to 346-216-8739 (Waoo Party LLC, no fee) or by card here: {{link}}
> Thank you! We're excited to be part of your celebration!

**ES** · Asunto: Recordatorio: tu saldo vence el {{due}}
> ¡Hola {{first_name}}! Ya se acerca tu fiesta del {{event_date}}. Tu saldo de {{balance}} vence el {{due}}. Puedes pagar por Zelle al 346-216-8739 (Waoo Party LLC, sin cargo) o con tarjeta aquí: {{link}}
> ¡Gracias! ¡Estamos emocionados de ser parte de tu celebración!

## 8. week_before — 7 days before, final details

**EN** · Subject: One week to go, {{first_name}}!
> Hi {{first_name}}! Your Waoo Party celebration is one week away: {{event_date}} at {{address}}. Please check that the address, start time and details are right here: {{link}} — if anything changed, reply or text us at (346) 851-8830.
> See you soon!

**ES** · Asunto: ¡Falta una semana, {{first_name}}!
> ¡Hola {{first_name}}! Falta una semana para tu celebración con Waoo Party: {{event_date}} en {{address}}. Revisa que la dirección, la hora y los detalles estén bien aquí: {{link}} — si algo cambió, respóndenos o escríbenos al (346) 851-8830.
> ¡Nos vemos pronto!

## 9. thank_you_review — the day after

**EN** · Subject: Thank you for celebrating with us!
> Hi {{first_name}}! Thank you for choosing Waoo Party. We hope everyone had an amazing time! If you have a minute, a Google review helps other families find us: {{review_link}}
> We'd love to celebrate with you again!

**ES** · Asunto: ¡Gracias por celebrar con nosotros!
> ¡Hola {{first_name}}! Gracias por elegir Waoo Party. ¡Esperamos que todos la hayan pasado increíble! Si tienes un minuto, una reseña en Google ayuda a otras familias a encontrarnos: {{review_link}}
> ¡Nos encantaría celebrar contigo otra vez!

---

Owner checklist before activation: approve each text (or edit), confirm the Google review link, confirm Zelle wording, confirm whether card payments mention the 3% processing fee in the reminder.
