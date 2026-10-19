# Phase 7 — Wider self-service (preparation, 2026-10-19)

Exit condition (PROJECT_PLAN §18): each new service passes resource and logistics tests. Nothing here is coded until its API is published and reviewed (Codex backend, Claude screens, cross-review). Owner rules come from BACKLOG CHANGE-A, the contract and ANNIVERSARY-REBOOK.md; anything not written there is marked **[César]** and is not invented.

## 1. CHANGE-A — cancel and reschedule (split into small deliveries)

| # | Delivery | Backend (Codex) | Screens (Claude) | Acceptance |
|---|---|---|---|---|
| A1 | **Change request record** | `event_changes` (kind reschedule/cancel, requested_by owner, reason, displayed event/finance/contract versions, state draft→proposed→accepted/declined/void), UUID + expected_version, audit | "Cambiar fiesta" action on the event file: choose reschedule or cancel, reason required | No mutation of event, reservations, money or contract until A4/A6 |
| A2 | **Reschedule check** | Re-run RESV-A on the union of old + new resources and travel for the new slot (holds the new slot, never releases the old one); reuse travel fee rules; returns per-role/costume/driver result | Side-by-side "Hoy / Propuesta": date, time, staff/costume coverage, travel fee, conflicts in plain Spanish | Failed check keeps the original untouched; race of two changes → one valid result |
| A3 | **Money difference** | New financial proposal: preserve the agreed package price (César,2026-10-19); contractual change fee only by an explicit manual owner decision, case by case (never added automatically), with audited decision/reason; reviewed travel fee difference; never edits old revisions or ledger | Table "Antes → Después → Diferencia", explicit Apply contractual fee / Do not apply choice, with reason | Totals reconcile; old revision and payments preserved |
| A4 | **Addendum + client acceptance** | Addendum document version (contract 2.5: one reschedule, within 60 days — counted), portal grant scoped to the addendum, client accepts (same evidence as signature) | Addendum preview, link panel (copy/WhatsApp like quotes), status pill | Accepting swaps reservations atomically (CHANGE-A-CAPACITY) with no gap and no double booking |
| A5 | **Reminders and calendar** | Cancel and re-plan MAIL reminders idempotently after commit; update calendar mirror | Event timeline shows "Reprogramada de X a Y" | No reminder fires for the old date |
| A6 | **Cancel** | Outcome from the contract version: deposit non-refundable; within 48 h up to 100 % retained; weather: 50 % credit for 60 days if paid in full; release capacity once; void pending payment sessions/reminders; keep contract, payments, history; owner override with reason; refunds are **never automatic** (separate owner action) | Cancel modal shows the computed outcome in plain words + override; client notice preview (not sent until MAIL active) | Repeated cancel = one effect; resources free immediately after authorized release |
| A7 | **Owner list** | Read model of changes by state | "Cambios" card on Inicio (pending acceptance, expiring) | — |

Dependencies: RESV-A-01 (done), PAY-02, DOC-01, MAIL B, JOBS-01. Order: A1 → A2 → A3 → A6 (cancel is simpler and more urgent) → A4 → A5 → A7.

## 2. Extras / upsells
- **What:** add-ons to an existing booking (extra hour, extra character, face painting, etc.) offered in the client portal before the party and in the quote.
- **[César]:** which extras exist, prices, deadline to add one (e.g. up to 7 days before?), whether an extra needs owner approval when it needs staff.
- **Rule:** an extra that needs staff/equipment passes the same availability check (RESV-A) before it is sold; otherwise "requiere revisión". It creates a new financial revision line and a balance increase, never edits paid amounts.
- **Deliveries:** EXTRA-1 catalog flag "se vende como extra" (Codex+Claude), EXTRA-2 portal offer + availability check + revision (Codex API, Claude screens), EXTRA-3 owner approval queue.
- **Acceptance:** sold extra always has capacity; balance and contract addendum (if needed) consistent; no extra after the deadline.

## 3. WhatsApp
- Phase 7 scope: **one-tap prefilled messages** from the CRM (already used for links) + later WhatsApp Business templates as a complement (~50 % of clients). No WhatsApp automation before SMS-01 and consent per channel.
- **[César]:** WhatsApp Business number = the same toll-free/HoneyBook number or his personal 346 number?
- **Acceptance:** opt-in recorded per channel; STOP/unsubscribe honored; no duplicate with email/SMS for the same reminder.

## 4. "Un año después" (ANNIV-01)
- Spec approved: `docs/plans/ANNIVERSARY-REBOOK.md` (46 weeks after, birthdays only, unique 10 % code, 7 days, package only, not stackable).
- **Depends on IMPORT-HISTORY** (past parties from HoneyBook, 242 so far) and on an event-type field. Promotional email ⇒ unsubscribe + postal address.
- Deliveries: ANNIV-1 eligibility + codes (Codex), ANNIV-2 owner list "Un año después" (Claude), ANNIV-3 email copy approval (César).

## Publish before code (for Codex)
CHANGE-A A1–A3 API first (records, check, money proposal), then A6 cancel. EXTRA and WhatsApp wait for César's answers above.
