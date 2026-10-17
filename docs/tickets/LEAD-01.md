# LEAD-01 · Website quote form → CRM lead (Phase 2)

Status: backend delivered on codex/lead-01, source ea13afd, API docs/LEAD-01-API.md published first. Full272 tests /2583 assertions pass. Claude website/spool/UI, independent review/main merge and live activation remain pending. **Signing correction:** timestamp + newline + UUID + newline + exact raw body must all be authenticated; body-only signing below is superseded by the API contract. Sandbox/staging only, default off.

## Goal

Every request from waooparty.com/contact reaches the CRM as a **lead** without ever losing one. Today `send-quote.php` only emails sales@ (fields: name, phone, email, event_date, start_time, zip, event_type, package, message, travel_estimate, honeypot `website`; high-demand flag from the calendar).

## Website side (Claude)

- `send-quote.php` keeps sending the email **first** (unchanged fallback). Then, if `crm_lead_url` is configured, POSTs JSON server-to-server to the CRM with a 3 s timeout. A CRM failure never changes what the visitor sees.
- Auth: `X-Waoo-Signature: sha256=<HMAC(body, shared secret)>` + `X-Waoo-Timestamp` (±5 min). Secret only in the website config file on Hostinger and the CRM `.env`; never in git or chat.
- Idempotency: `Idempotency-Key` = UUID per submission. Failed posts append one line to a private spool file outside the web root; a retry script (hPanel cron, every 10 min) resends with the same key.
- Honeypot hits are not sent.

## Payload

`name`, `phone`, `email`, `event_date` (Y-m-d), `start_time` (HH:mm Houston, nullable), `zip`, `event_type`, `package` (website code), `message`, `travel_estimate_text` (as shown to the visitor, informational only), `locale` (en|es), `consent` {`text`, `version`} as displayed on the form (TCPA line), `demand_flag` (open|filling|full|unknown), `submitted_at` (ISO with offset), `page` (`/contact`).

## CRM side (Codex)

- `POST /api/leads` (no session; signature + timestamp + idempotency required; rate-limited). Stores an immutable `lead_submissions` row and creates/updates a `leads` row: status `new|contacted|quoted|booked|lost`, owner note, timestamps. Never auto-creates a customer or event, never sends a message.
- Duplicate hint (not merge): matches existing customers by normalized phone or email; the owner decides.
- The website travel estimate is stored as text only; it is never used as a price.
- Audit entries for status changes; leads are owner-only.

## Screens (Claude)

- **Prospectos** list: new first, date/ZIP/package, “alta demanda” badge, duplicate hint, status chips.
- Lead detail → **“Convertir en reserva rápida”** prefilled (client match or new, package, date, time, ZIP); status becomes `booked` only after the quick booking succeeds.
- Inicio card: “Prospectos sin contestar”.

## Tests (minimum)

Valid signature stored once (same key twice → one lead); bad/expired signature rejected; spool retry is idempotent; email still sent when CRM is down; no customer/event created by the endpoint; conversion links lead → event.
