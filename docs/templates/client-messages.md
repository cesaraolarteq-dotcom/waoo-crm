# Client messages — drafts EN/ES (MAIL-01 / MSG-01 copy, Claude)

Status: **drafts for the owner's review**. Tone per the Master Context: warm, short, enthusiastic, few emojis, a clear next step. Variables in `{{ }}`. ES uses "costo de transporte"; EN uses "travel fee". No message implies a date is held before payment (D7). SMS ≤ 2 segments and always includes "STOP" wording on the first message once SMS is enabled (Track B, A2P).

## 1. Quote sent (email + SMS)
**ES — asunto:** Tu cotización de Waoo Party para el {{event_date_short}} 🎉
Hola {{first_name}}:
¡Gracias por pensar en Waoo Party para la celebración de {{child_name}}! Aquí está tu cotización:
- {{package_name}} ({{duration}}) — {{package_price}}
- Costo de transporte ({{zip}}) — {{travel_fee}}
- **Total: {{total}}** · Depósito para reservar (50%): **{{deposit}}**
Revísala, firma y paga tu depósito aquí: {{portal_link}}
Tu fecha queda asegurada cuando recibimos tu depósito. ¡Estamos emocionados de ser parte de tu celebración!
— Waoo Party

**EN — subject:** Your Waoo Party quote for {{event_date_short}} 🎉
Hi {{first_name}},
Thank you for thinking of Waoo Party for {{child_name}}'s celebration! Here's your quote:
- {{package_name}} ({{duration}}) — {{package_price}}
- Travel fee ({{zip}}) — {{travel_fee}}
- **Total: {{total}}** · Deposit to book (50%): **{{deposit}}**
Review, sign and pay your deposit here: {{portal_link}}
Your date is secured once we receive your deposit. We're excited to be part of your celebration!
— Waoo Party

**SMS ES:** Waoo Party: ¡Hola {{first_name}}! Tu cotización para el {{event_date_short}} está lista: {{portal_link}} Tu fecha se asegura con el depósito de {{deposit}}.
**SMS EN:** Waoo Party: Hi {{first_name}}! Your quote for {{event_date_short}} is ready: {{portal_link}} Your date is secured with the {{deposit}} deposit.

## 2. Zelle declared, pending verification
**ES:** ¡Gracias, {{first_name}}! Recibimos tu aviso de pago por Zelle de {{amount}}. Lo verificamos y te confirmamos en cuanto lo veamos reflejado (normalmente el mismo día).
**EN:** Thank you, {{first_name}}! We got your Zelle notice for {{amount}}. We'll confirm as soon as we see it in our account (usually the same day).

## 3. Payment received — date secured
**ES — asunto:** ¡Listo! Tu fecha con Waoo Party está reservada ✅
¡Gracias, {{first_name}}! Pago recibido: {{amount}} ({{method}}).
Tu {{package_name}} queda reservado para el {{event_date_long}} a las {{start_time}}.
Saldo pendiente: {{balance}}, a más tardar el {{balance_due}}.
Tu contrato y recibo están aquí: {{portal_link}}
¡Estamos emocionados de ser parte de tu celebración!

**EN — subject:** You're booked with Waoo Party ✅
Thanks so much, {{first_name}}! Payment received: {{amount}} ({{method}}).
Your {{package_name}} is booked for {{event_date_long}} at {{start_time}}.
Remaining balance: {{balance}}, due by {{balance_due}}.
Your contract and receipt are here: {{portal_link}}
We're excited to be part of your celebration!

## 4. Reminder — 7 days before
**ES:** ¡Ya falta una semana para la fiesta de {{child_name}}! 🎈 Confirma por favor la dirección, la hora y si habrá piñata o pastel en {{portal_link}}. Saldo pendiente: {{balance}} (vence {{balance_due}}).
**EN:** Just one week until {{child_name}}'s party! 🎈 Please confirm the address, time, and whether there will be a piñata or cake at {{portal_link}}. Remaining balance: {{balance}} (due {{balance_due}}).

## 5. Balance due — 24 h before (only if balance > 0)
**ES:** Hola {{first_name}}, un recordatorio amable: tu saldo de {{balance}} vence {{balance_due}}. Puedes pagar con Zelle a Waoo Party LLC (346-216-8739, nota {{reference}}) o con tarjeta aquí: {{portal_link}}
**EN:** Hi {{first_name}}, a friendly reminder: your balance of {{balance}} is due {{balance_due}}. Pay by Zelle to Waoo Party LLC (346-216-8739, memo {{reference}}) or by card here: {{portal_link}}

## 6. Reschedule — addendum to accept
**ES:** Hola {{first_name}}, preparamos el cambio de tu evento al {{new_date}} a las {{new_time}}. {{fee_line}} Acéptalo aquí para confirmarlo: {{portal_link}}
**EN:** Hi {{first_name}}, we've prepared the change of your event to {{new_date}} at {{new_time}}. {{fee_line}} Please accept it here to confirm: {{portal_link}}
(`fee_line` ES: "Aplica el cargo de cambio de $100 según tu contrato." / EN: "The $100 change fee applies per your contract." — omitted when the admin waives it.)

## 7. Cancellation confirmed
**ES:** Hola {{first_name}}, confirmamos la cancelación de tu evento del {{event_date_long}}. {{policy_line}} Si en el futuro quieres celebrar con nosotros, aquí estaremos.
**EN:** Hi {{first_name}}, we confirm the cancellation of your event on {{event_date_long}}. {{policy_line}} Whenever you'd like to celebrate with us in the future, we'll be here.
(`policy_line` is generated from the contract version: deposit non-refundable / weather credit 50% valid 60 days / amount retained within 48 h.)

## 8. Thank you + review (day after, only with no open incident)
**ES:** ¡Gracias por celebrar con Waoo Party, {{first_name}}! Esperamos que {{child_name}} la haya pasado increíble. Si te gustó, nos ayudaría mucho tu reseña en Google: {{google_review_link}} 💛
**EN:** Thank you for celebrating with Waoo Party, {{first_name}}! We hope {{child_name}} had an amazing time. If you enjoyed it, a Google review would mean a lot: {{google_review_link}} 💛

## Owner questions
1. Do you want the thank-you to go the same night or the next morning?
2. ~~Google review link~~ **Provided by the owner (2026-10-15): https://g.page/r/CVT57MD29OwXEAE/review** → value of `{{google_review_link}}`.
