# QUOTE-01 · Versioned quotes the client can accept (Phase 2)

Status: backend A88d53f3 plus B/C5a5433d delivered for independent review on codex/quote-portal. API2d90794 and detailed112af8e/97348c2 published before code; docs/QUOTE-01-API.md and docs/QUOTE-PORTAL-API.md. Full377 tests/3523 assertions pass. Private quote presentation/PDF/decision, revision-only recoverable links and explicit accepted-snapshot finance→verified-payment/capacity are implemented. Claude UI, independent review, main merge, owner-approved consent wording and owner acceptance remain pending; whole QUOTE-01 is not complete. Sandbox defaults off; no live deployment/message/charge.

## Owner's real flow (Master Context)
Inquiry → date → ZIP → service → time → availability → **price** → deposit → contract details → booking confirmed. A quote always shows: service, package price, travel fee, total, discount (only if one applies), final total, 50% deposit, payment method (Zelle preferred; card/pay-later +3% processing), and one clear next step. Prices are base + travel fee. Never invent prices or discounts.

## Data
- `quotes`: number (human, e.g. Q-2026-0123), lead_id?, customer_id?, status draft|sent|accepted|expired|superseded|rejected, valid_until (default 7 days, owner-editable), locale, owner note (private), created_by.
- `quote_revisions` (immutable): revision n, lines snapshot (price_version_id, name, duration, characters + outfit, quantity, amount), travel fee + ROUTE-01 evidence id, discount lines (explicit label, amount, reason, approved_by, expires_at — never hidden in the base price), customer fee policy (card 3% shown as text, not added to the quote total unless the owner chooses), totals (subtotal, travel, discount, total, deposit = ceil(total/2)), event date/time window, ZIP/address.
- A change creates a new revision; the previous one is superseded. Catalog price changes never alter a sent/accepted revision.

## Services / API (owner + 2FA, idempotent writes, Locked versions in UI)
- CreateQuote / ReviseQuote (from scratch, from a lead, or from an event draft); totals computed server-side only.
- SendQuote → issues a portal grant scoped `quote.read`, `quote.accept` (reuse portal grants + COMMS-01 recovery); no message sent by the CRM until MAIL-01; UI offers copy/WhatsApp/SMS like #54.
- Client: portal shows the current revision (EN/ES), PDF download; **AcceptQuote** (expected revision, consent text/version, timestamp, IP/UA evidence) → status accepted. Acceptance does not reserve capacity and does not take money by itself; the next step is deposit (Zelle notice or Stripe test) and then the existing QuickBook/ConfirmCapacity path, which must reference the accepted revision so amounts match exactly.
- Expire job (JOBS) marks overdue quotes expired; accepted quotes never expire.

## Screens (Claude)
Quote builder (package + characters/outfits + extras, Calcular transporte, optional discount line with reason, preview of the client view), quote list with statuses, “Convertir en reserva” from an accepted quote, Inicio card “Cotizaciones por vencer”, PDF and portal page EN/ES matching the Waoo brand.

## Tests (minimum)
Totals exact (incl. odd-cent deposit), revision immutability, discount visible and approved, expired cannot be accepted, accept twice → one acceptance, booking created from the accepted revision has identical amounts, catalog price change after sending does not change the quote.
