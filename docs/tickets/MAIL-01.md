# MAIL-01 + MSG-01 · Transactional email and essential reminders (Phase 5)

Status: offline outbox/reminder foundation sourceb30e1cb on codex/mail-01-outbox after API63605ab/d51ceaa. Three manual reminder adapters, owner timeline/warnings/settings/pause APIs and safe fake-only transport protocol. Final verification in newest HANDOFF; no owner-approved text, provider, DNS, automatic trigger hooks, real send or whole MAIL/MSG completion. #69/#70 synthetic previews independently reviewed. Remaining six business adapters/provider ingestion/reconciliation and UI integration pending. Actual sending still requires explicit owner approval/activation.

## Owner facts
~50%+ of HoneyBook emails land in spam today; the owner also texts everything. Only ~50% of clients use WhatsApp → SMS first (MSG via Twilio later, Phase 6). Email must never be the only channel for anything time-critical. Tone: warm, short, professional, few emojis, closing “We're excited to be part of your celebration.” / “¡Estamos emocionados de ser parte de tu celebración!”. ES uses “costo de transporte”.

## Sending (Codex)
- Provider chosen in COMMS-01 (Postmark recommended), from the owner's domain/subdomain with SPF/DKIM/DMARC; Reply-To = owner inbox. Keys only in `.env`.
- `outbound_messages` stores immutable intent and encrypted private envelope. Append-only `outbound_message_events` preserves delivery evidence; `outbound_message_states` is the mutable status projection. Never mutate an immutable log for status updates. Provider/authentication must be verified before any public webhook; no invented generic signature header. Exact API and first supported source bindings: docs/MAIL-01-API.md. Idempotent per (template, subject entity, revision).
- Sandbox mode: send only to an allow-list of owner addresses; production flag separate.

## Templates v1 (Claude writes EN/ES; owner approves wording)
Inquiry received (from LEAD-01), quote sent (QUOTE-01), details request (quick booking link), contract ready to sign, deposit received / payment received, Zelle notice pending verification, balance reminder, 7-day reminder with final details, thank-you + Google review request (after the event). Each: one link, plain text + light HTML, no attachments on first contact.

## Reminders (MSG-01, jobs)
- 7 days before (final details check); balance reminder 24 h before the **due date** (due date = 24 h before the party, so ~48 h before the party), only when `has_balance`; thank-you + review (day after). Each re-checks state at send time (cancelled/rescheduled/paid → skip) and is cancelled automatically when stale. Owner can pause per event.

## Screens (Claude)
Event file: message timeline (what was sent, status, open bounce warnings); Inicio card “Correos que rebotaron”; settings page listing templates (read-only preview EN/ES) and the sandbox allow-list.

## Tests (minimum)
No send outside allow-list in sandbox; duplicate job → one message; stale reminder skipped after reschedule/payment; bounce webhook updates status; templates render in both languages without unescaped user text.
