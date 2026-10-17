# Phase 6 — Staff and advanced management (owner OK 2026-10-17)

César authorized Phase 6 on 2026-10-17 ("okay para fase 6"). Exit condition (PROJECT_PLAN §18): **assignments accepted by staff and real costs traceable.** Phases 1–5 keep going in parallel (merge #73/#74, owner tests in crm-test, approved copy, real import).

Rules carried over: owner data wins, nothing invented (rates, hours, formulas come from César); staff never see business income or other people's pay; no real SMS, charge or deployment without explicit owner approval; API contract before code; Claude ↔ Codex cross-review.

## Order (fastest owner value first)

| # | Ticket | What César gets | Backend | UI | Blocked by |
|---|---|---|---|---|---|
| 1 | **REPORT-A** | Monday email + month summary (money in, balances, weekend staff cost, packages sold) and the same view on Inicio | Codex: review/merge existing `codex/operational-reports`, `codex/prepared-owner-reports`, `codex/booking-sales-report`, `codex/weekend-forecast` onto main | Claude | MAIL-01 transport for the email part (dashboard view works without it) |
| 2 | **STAFF-01** | Staff phone portal: availability, job invitations, accept/decline, my schedule with role/character/costume/address/instructions; owner sees who accepted and gets an alert when someone has not answered | Codex | Claude | Staff list from César |
| 3 | **STAFFPAY-01** | Pay per assignment (planned → final), extras, tips, expense reimbursements, period statement per person (export) | Codex | Claude | Owner pay rules (below) |
| 4 | **SMS-01** | Automatic texts to clients and staff from the number clients already know (HoneyBook toll-free, transferred to César's Twilio at cutover) | Codex (disabled transport now) | Claude | Owner Twilio account now; number transfer at Phase 8 |

Inventory/consumables and checklists/incidents (also listed for Phase 6 in the plan) follow after these four unless César reorders.

## STAFF-01 — contract outline for Codex (publish the API before code)

- **Staff identity:** separate from owner admins. A `staff` user linked to an existing staff `Resource` (one person = one resource). Login by personal magic link sent by the owner (copy/WhatsApp/SMS buttons, like the client portal), expiring and revocable; optional PIN. No access to /admin, money, clients' contact data beyond what the job needs, or other staff.
- **Availability:** weekly recurring + date exceptions; declared availability ≠ acceptance (plan §10). Only accepted assignments count as committed capacity; pending invitations stay "en revisión".
- **Invitation → acceptance:** owner invites a person to a role on a booked event (from the existing assignment/reservation); person accepts/declines with a deadline; decline frees the role and alerts the owner (Inicio card); no silent reassignment after instructions were sent (notify).
- **Job sheet (staff view):** date/time Houston, arrival/setup, address + parking/access notes, role, character + costume version (OUTFIT-01), what to bring, owner instructions, contact of the day only on the event day. Never: total, deposit, balance, other people's pay.
- **Driver rule:** keep 43607f1 (≥1 driver among assigned staff); staff portal shows who drives.
- **Audit + versions** as everywhere (expected_version, UUID keys, owner actor).
- **Tests:** cross-staff denial, revoked/expired link, admin cannot act as staff, decline frees role, deadline alert, privacy of money fields, DST.

## STAFFPAY-01 — needs owner rules first

Known (BACKLOG 2026-10-15): recreador $25/h, animador $35/h, personaje $60/h, personaje de traje $80/h; personaje de peluca rate to confirm. Missing from César: prep/makeup paid time per role, minimum paid hours, staff transport pay formula (people picked up + distance), tips and extras policy, pay period (weekly?). Calculation and approval tool only: not payroll, taxes or worker classification (plan §10).

## SMS-01 — keep the number clients already know

**Owner facts (2026-10-17):** César's client SMS number is HoneyBook's Business Phone & SMS line: a dedicated **toll-free** number provisioned inside HoneyBook's account, carrier registration **approved**, and clients already receive texts from it. Per HoneyBook's Help Center: the number can be transferred out on request to support; cancelling the add-on loses the number permanently; porting an existing number *into* HoneyBook is not available. His personal 346-851-8830 (AT&T) cannot be API-sent.

**Plan:**
1. **Pilot (HoneyBook + CRM in parallel):** the number stays in HoneyBook. From the CRM the owner sends links with the existing one-tap "Enviar por SMS / WhatsApp" buttons. No second automated number during the pilot (two numbers would confuse clients).
2. **Now:** César opens his own Twilio account (Waoo Party LLC), no number purchased yet. Codex builds SMS-01 behind a **disabled transport** (like MAIL): consent records per channel, STOP/HELP handling, outbox/idempotency, staff + client templates, toll-free verification prerequisites. No real sends.
3. **Cutover (Phase 8):** before cancelling anything in HoneyBook, César asks HoneyBook support to transfer the number to his Twilio account; expect re-verification of the toll-free number under his own account (assume it, do not promise it carries over). Then activate SMS-01 in the CRM with that number.
4. WhatsApp templates later, only as a complement (~50 % of clients use it).

## What Claude needs from César

1. **Staff list:** name, phone, roles/skills (incl. who drives), approved characters, active yes/no. (Can be a spreadsheet; it stays out of git.)
2. **Pay rules:** wig-character rate, prep time per role, minimum hours, transport pay formula, tips/extras, pay period.
3. **Twilio:** open his own Twilio account (no number yet); keep the HoneyBook toll-free number active until the cutover transfer. No card/secret in chat.
4. **Reports:** confirm the Monday + monthly emails go to sales@waooparty.com (decision 2026-10-15) and what "staff cost of the weekend" should include.
