# Mesa de aprobación — César, 2026-10-22

Owner paste in chat is the approval evidence. No deployment, real-data update, send, charge or flag activation accompanies this delivery.

## Confirmed existing texts (no wording changed)

Approved: `mail_inquiry_received`, `mail_details_request`, `mail_balance_reminder`, `mail_week_before`, `mail_thank_you_review`, `quick_booking_msg`, `quick_unpaid_link_msg`, `stripe_link_msg`, `stripe_reminder_24h`, `stripe_hold_released`.

The incomplete stripe_hold_released note was clarified by César: “que lninguna cotizacion dure mas de 48 horas para que no hayan conflictos con las fechas”. This confirms the quote policy; it does not request new release-message wording. Existing approved release copy remains exact.

`birthday_draft`: approved as the displayed DRAFT only; no send authorization, coupon issuance, marketing consent or final campaign terms are inferred. All sending and campaign blocks stay intact.

## Code/config decisions

- `dec_mail_cst`: remove CST/CDT display only, preserve America/Chicago conversion and actual event instants. Repeated fall-back wall hours now intentionally display equally in email; stored instants remain distinct.
- Quote validity: new quotes/revisions default to min(now + 48 elapsed hours, event start); reject explicit longer deadlines. No rewrite of old revisions, agreements, links or retry receipts. Expiry is not a resource reservation or a guarantee of availability.
- `mail_payment_received`: preserve frozen words; ensure a separator between balance and nonempty due suffix in both preview and actual rendering. Existing real-mail source already supplies “ · vence / · due ”; the reported glued example is additionally guarded. No extra date when balance_due is empty.
- `dec_glitter_90`: approved config creates an additional 90-minute $350 option during a future explicit owner-catalog import, copying only the owner-supplied 120-minute Glitter Mobile recipe. Original 120-minute variant/price retained. No source recipe means no invented resources or variant; explicit existing target preserved. No import run here.
- `dec_babyshower_setup`: 15/15 confirmed, logged only; no need to rewrite existing catalog rows.

## Host decisions for Claude — approved, not applied here

`dec_flag_analytics`, `dec_flag_company`, `dec_flag_anniversary`: enable corresponding features on crm-test during the separately approved host workflow. Defaults unchanged.

`dec_nameless_leads`: exclude nameless HoneyBook contacts in the private migration workflow; do not delete already imported records or change HoneyBook here.

`dec_google_calendar`: connection approved for crm-test; actual OAuth/account selection, permissions and integration readiness still require the host workflow. No credentials requested in chat, no calendar writes here.

## New addendum and requested copy

The prior 2026-10-20 frozen file and 2026-10-21 approved source remain unchanged. Current addendum is `client-texts-2026-10-22`. Approved inline portal/PDF strings are read through ApprovedClientTexts without changing their words. Quote wording requested below is `pending_review` and cannot become active approved copy until César approves it; current mail continues showing its actual {valid_until}.

### portal_reserved · approved

ES: ¡Tu fiesta está reservada! 🎉

EN: Your party is booked! 🎉

### portal_reserved_body · approved

ES: Tu contrato está firmado y recibimos tu depósito. Solo falta el saldo antes de la fiesta; aquí abajo tienes cómo pagarlo.

EN: Your contract is signed and we received your deposit. Only the balance is left before the party; how to pay it is below.

### portal_zelle_memo · approved

ES: En la nota del Zelle escribe

EN: In the Zelle memo write

### portal_zelle_memo_help · approved

ES: así sabemos que es tu pago

EN: so we know it is your payment

### portal_stripe_confirming · approved

ES: Estamos confirmando tu pago con Stripe. Esto tarda unos segundos; esta página se actualiza sola.

EN: We are confirming your Stripe payment. This takes a few seconds; this page refreshes by itself.

### portal_owner_notice · approved

ES: Estás viendo este enlace con tu sesión de dueño. Para ver y probar lo que ve el cliente (pagar, firmar), ábrelo en una ventana de incógnito o en tu celular.

EN: You are viewing this link with your owner session. To see and test what the client sees (pay, sign), open it in a private window or on your phone.

### pdf_bill_to · approved

ES: Facturar a:

EN: Bill to:

### pdf_attention · approved

ES: Atención:

EN: Attention:

### mail_quote_sent · pending_review

ES: Asunto: Tu cotización Waoo Party para el {subject_date}

¡Hola {first_name}! Aquí está tu cotización de {package} para el {event_date}.

[Aquí va la tabla con paquete, transporte y total]

Para apartar tu fecha pedimos un depósito del 50 % ({deposit}). Esta cotización tiene una vigencia máxima de 48 horas, hasta el {valid_until}.

[Botón: Ver y aceptar mi cotización]

¡Estamos emocionados de ser parte de tu celebración!

EN: Subject: Your Waoo Party quote for {subject_date}

Hi {first_name}! Here is your quote for {package} on {event_date}.

[Table with package, travel fee and total goes here]

To reserve your date we need a 50% deposit ({deposit}). This quote is valid for a maximum of 48 hours, until {valid_until}.

[Button: Review and accept my quote]

We're excited to be part of your celebration!

### quote_link_msg · pending_review

ES: ¡Hola {first_name}! Aquí está tu cotización de Waoo Party para el {event_date}: {link}. Tiene una vigencia máxima de 48 horas, hasta el {valid_until}.

EN: Hi {first_name}! Here is your Waoo Party quote for {event_date}: {link}. It is valid for a maximum of 48 hours, until {valid_until}.


## Additional owner portal report, same session

César reports wardrobe details are internal, and the package summary must not repeat the additional-character count already represented by the character list. Client event HTML and JSON now project character names/quantities without outfit; the package summary keeps name/duration only. Financial and operational snapshots, allocations, charges and issued/signed documents are untouched. Admin/staff labels retain wardrobe details.

César confirms the affected page is a reservation link on crm-test, and Stripe reported a successful test payment before returning. A browser defect was reproduced independently: an active checkout stops being polled after a refresh removes the return parameter; a transient non-OK response also used to permanently stop polling. Polling now runs for an active checkout even without that parameter, retries temporary errors, uses no-store and a per-request timeout, and advances to the server-rendered next step only on an authoritative response. No client-side payment confirmation is fabricated. Real reservation diagnosis still needs the private host webhook/receipt state; this environment has no host access. No claim that the existing crm-test reservation is fixed or that its webhook was received.
