# MAIL-01 + MSG-01 · Transactional email and essential reminders (Phase 5)

Status: contract proposal by Claude (templates EN/ES, screens) for Codex (sending, jobs). Depends on COMMS-01 B decision (provider + DNS) and owner approval of DNS changes. Nothing is sent to real clients until the owner explicitly activates it.

## Owner facts
~50%+ of HoneyBook emails land in spam today; the owner also texts everything. Only ~50% of clients use WhatsApp → SMS first (MSG via Twilio later, Phase 6). Email must never be the only channel for anything time-critical. Tone: warm, short, professional, few emojis, closing “We're excited to be part of your celebration.” / “¡Estamos emocionados de ser parte de tu celebración!”. ES uses “costo de transporte”.

## Sending (Codex)
- Provider chosen in COMMS-01 (Postmark recommended), from the owner's domain/subdomain with SPF/DKIM/DMARC; Reply-To = owner inbox. Keys only in `.env`.
- `outbound_messages` (immutable log): template key + version, locale, recipient, related event/quote/lead, rendered subject/body hash, provider id, status queued|sent|delivered|bounced|complained|failed, timestamps. Provider webhooks (signed) update status. Idempotent per (template, subject entity, revision).
- Sandbox mode: send only to an allow-list of owner addresses; production flag separate.

## Templates v1 (Claude writes EN/ES; owner approves wording)
Inquiry received (from LEAD-01), quote sent (QUOTE-01), details request (quick booking link), contract ready to sign, deposit received / payment received, Zelle notice pending verification, balance reminder, 7-day reminder with final details, thank-you + Google review request (after the event). Each: one link, plain text + light HTML, no attachments on first contact.

## Reminders (MSG-01, jobs)
- 7 days before (final details check); balance reminder 24 h before the **due date** (due date = 24 h before the party, so ~48 h before the party), only when `has_balance`; thank-you + review (day after). Each re-checks state at send time (cancelled/rescheduled/paid → skip) and is cancelled automatically when stale. Owner can pause per event.

## Screens (Claude)
Event file: message timeline (what was sent, status, open bounce warnings); Inicio card “Correos que rebotaron”; settings page listing templates (read-only preview EN/ES) and the sandbox allow-list.

## Tests (minimum)
No send outside allow-list in sandbox; duplicate job → one message; stale reminder skipped after reschedule/payment; bounce webhook updates status; templates render in both languages without unescaped user text.
