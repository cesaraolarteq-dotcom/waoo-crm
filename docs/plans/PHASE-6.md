# Phase 6 — Staff and advanced management (owner OK 2026-10-17)

César authorized Phase 6 on 2026-10-17 ("okay para fase 6"). Exit condition (PROJECT_PLAN §18, adjusted by César 2026-10-18): **each staff member sees their assigned parties of the week on their own page, and real costs are traceable.** Staff do not accept/decline in the CRM: César asks them directly (WhatsApp) and records who goes. Phases 1–5 keep going in parallel (merge #73/#74, owner tests in crm-test, approved copy, real import).

Rules carried over: owner data wins, nothing invented (rates, hours, formulas come from César); staff never see business income or other people's pay; no real SMS, charge or deployment without explicit owner approval; API contract before code; Claude ↔ Codex cross-review.

## Order (fastest owner value first)

| # | Ticket | What César gets | Backend | UI | Blocked by |
|---|---|---|---|---|---|
| 1 | **REPORT-A** | Monday email + month summary (money in, balances, weekend staff cost, packages sold) and the same view on Inicio | Codex: review/merge existing `codex/operational-reports`, `codex/prepared-owner-reports`, `codex/booking-sales-report`, `codex/weekend-forecast` onto main | Claude | MAIL-01 transport for the email part (dashboard view works without it) |
| 2 | **STAFF-01** | Read-only staff page "my week": one personal link per person that does not change and always shows that person's assigned parties of the current week (role/character/costume, arrival, address, who drives, what to bring, instructions). No availability, no accept/decline (owner 2026-10-18) | Codex | Claude | Staff list from César |
| 3 | **STAFFPAY-01** | Pay per assignment (planned → final), extras, tips, expense reimbursements, period statement per person (export) | Codex | Claude | Owner pay rules (below) |
| 4 | **SMS-01** | Automatic texts to clients and staff from the number clients already know (HoneyBook toll-free, transferred to César's Twilio at cutover) | Codex (disabled transport now) | Claude | Owner Twilio account now; number transfer at Phase 8 |

Inventory/consumables and checklists/incidents (also listed for Phase 6 in the plan) follow after these four unless César reorders.

## STAFF-01 — contract outline for Codex (publish the API before code)

**Owner decision 2026-10-18:** "es mejor que el staff simplemente vea información de su fiesta, no que acepten o no, y tampoco que coloquen su disponibilidad porque nosotros preguntamos directamente… puede ser un enlace de ellos que no cambie, simplemente se actualice con la información del trabajo de la semana." This replaces the invitation/availability outline of 2026-10-17.

- **Staff identity:** a staff `Resource` (one person = one resource) gets **one durable personal link** (high-entropy token, stored hashed/encrypted like portal grants). It does not expire on its own; the owner can **rotate** it (old link dies at once) or **turn it off** (person leaves). Owner buttons: send by WhatsApp/SMS, copy, rotate, turn off; "last opened" shown to the owner. No password, no /admin access.
- **What the page shows:** that person's assignments on **confirmed** events (capacity reserved) for the **current week, Mon–Sun Houston time**; past parties drop off, next week appears on Monday. Read-only. It updates by itself when the owner assigns or changes something.
- **Job card:** date, arrival time, party hours, setup minutes, role, character + costume version (OUTFIT-01), address + Maps link, parking/access notes, whether *you* drive, teammates on the same party by **first name only** (with who drives), what to bring, owner instructions, day contact only on the party day. Never: totals, deposit, balance, pay of anyone, client phone/email before the day, teammates' phones.
- **No availability, no invitations, no accept/decline, no deadline alerts.** Assignment = the owner's existing reservation of the person on the event (after asking them directly).
- **Driver rule:** keep 43607f1 (≥1 driver among assigned staff); the page shows who drives.
- **Safety of a durable link:** no-store/no-referrer headers, noindex, rate limit, rotate on loss, link scoped to one person only, nothing writable. If a link is forwarded, the exposure is that person's week (addresses) — acceptable per owner, mitigated by rotation.
- **Audit + versions** as everywhere (owner actor on issue/rotate/turn off).
- **Tests:** cross-staff denial, rotated/turned-off link, only confirmed events, week window and DST, privacy of money fields and teammates' contact data, admin session cannot impersonate staff.

## STAFFPAY-01 — needs owner rules first

Known (BACKLOG 2026-10-15): recreador $25/h, animador $35/h, personaje $60/h, personaje de traje $80/h; personaje de peluca rate to confirm. Missing from César: prep/makeup paid time per role, minimum paid hours, staff transport pay formula (people picked up + distance), tips and extras policy, pay period (weekly?). Calculation and approval tool only: not payroll, taxes or worker classification (plan §10).

## SMS-01 — keep the number clients already know

**Owner facts (2026-10-17):** César's client SMS number is HoneyBook's Business Phone & SMS line: a dedicated **toll-free** number provisioned inside HoneyBook's account, carrier registration **approved**, and clients already receive texts from it. Per HoneyBook's Help Center: the number can be transferred out on request to support; cancelling the add-on loses the number permanently; porting an existing number *into* HoneyBook is not available. His personal 346-851-8830 (AT&T) cannot be API-sent.

**Plan:**
1. **Pilot (HoneyBook + CRM in parallel):** the number stays in HoneyBook. From the CRM the owner sends links with the existing one-tap "Enviar por SMS / WhatsApp" buttons. No second automated number during the pilot (two numbers would confuse clients).
2. **Now:** César opens his own Twilio account (Waoo Party LLC), no number purchased yet. Codex builds SMS-01 behind a **disabled transport** (like MAIL): consent records per channel, STOP/HELP handling, outbox/idempotency, staff + client templates, toll-free verification prerequisites. No real sends.
3. **Cutover (Phase 8):** before cancelling anything in HoneyBook, César asks HoneyBook support to transfer the number to his Twilio account; expect re-verification of the toll-free number under his own account (assume it, do not promise it carries over). Then activate SMS-01 in the CRM with that number.
4. WhatsApp templates later, only as a complement (~50 % of clients use it).

## Owner pay rules (César, 2026-10-20, catalog questionnaire s01–s20, q13/q18/q31/q34)

Source: César's answers in his catalog worksheet (private). Calculation/approval aid only, not payroll or tax advice (plan §10).

- **Period:** weekly; paid Tuesday–Wednesday for the previous week; by Zelle.
- **Hourly rate by role:** recreador/host $25 · animador $35 · personaje de cabeza / disfraz completo $60 · personaje de peluca $80 (the higher rate already covers makeup/wig prep: **no separate prep pay**) · pintacaritas $25 · operador (foam, photo booth, sparklers) $25 · facilitadora de spa $25 (women only; 15 people marked) · robot LED bajo (own staff) $60. Robot alto: subcontracted stilt walker **$200/h** (external cost, not staff pay).
- **Paid time:** package duration only (the 15 min before/after for setup are **not** paid), with a **minimum of 2 paid hours per party**.
- **Transport:** paid **only to the driver**, set per party by distance and number of people picked up (no formula): one pickup nearby (e.g. Katy) ≈ $20; group pickup of 3 people ≥ $40; far ≈ $60–80; maximum seen $80 (~100 miles round trip, 3 pickups). Store as an owner-entered amount per assignment, with these as guidance. External driver who only carries equipment (≈5% of parties): $50–60 per trip.
- **César and Maybeli:** paid hourly by the role they work at the party, **no transport pay**.
- **Tips:** split **equally** among the people who worked that party; card processing fee is **absorbed by Waoo** (not deducted from tips); paid together with the weekly pay.
- **No expense reimbursement** (gas, materials, tolls, parking) and **no bonuses**.

Client-side money rules from the same answers (for Stripe/quotes): card payments carry a **3 % surcharge**; accepted methods Zelle, card, cash, Cash App, and Afterpay/Zip/Klarna through the Stripe payment link; extra time exists only for some packages, at the rate in each contract.

## What Claude needs from César

1. **Staff list:** name, phone (to send their link), roles/skills (incl. who drives), approved characters, active yes/no. (Can be a spreadsheet; it stays out of git.)
2. **Pay rules:** wig-character rate, prep time per role, minimum hours, transport pay formula, tips/extras, pay period.
3. **Twilio:** César has never logged into twilio.com — the number lives inside HoneyBook (answer 2026-10-18). Next: open his own Twilio account (Waoo Party LLC, no number purchased) and ask HoneyBook support how the transfer works; keep the HoneyBook number active until the cutover transfer. No card/secret in chat.
4. **Reports:** confirm the Monday + monthly emails go to sales@waooparty.com (decision 2026-10-15) and what "staff cost of the weekend" should include.
