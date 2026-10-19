# Phase 8 — Final migration and switch-over (preparation, 2026-10-19)

Exit condition: complete data, restorable backups and César's acceptance. **Nothing real** (import, cancel, transfer, deploy, sends, charges) without César's specific authorization for that step.

## Owner decision 2026-10-19 — who charges the existing parties
César: *"que sea el CRM quien los cobre pero que no lo haga hasta que esté live; por los momentos ambos tendrán la información, pero una vez apagamos HoneyBook el CRM se activa."*

So, for the parties already booked in HoneyBook:
1. **Now (shadow):** they are imported into the CRM (IMPORT-01) with full information, but marked **responsable: HoneyBook**. The CRM shows them on the calendar, blocks staff/costumes, and counts them in reports, but **sends nothing and charges nothing** for them (no reminders, no balance link, no thank-you email).
2. **During the pilot:** HoneyBook keeps collecting and messaging those clients. Every payment or change made in HoneyBook is re-synced into the CRM (re-run of the import = idempotent update + owner review of differences) so both show the same balance.
3. **Switch-over day:** one final re-sync and reconciliation, then the owner flips the cohort to **responsable: CRM**. From that moment the CRM plans the remaining reminders (only future ones, no catch-up) and the balance link; HoneyBook automations for those projects are turned off **before** the flip so no client gets both.
4. New bookings made in the CRM during the pilot are **responsable: CRM** from the start (one system per event, PROJECT_PLAN §17.8).

**For Codex (backend):** a per-event `responsible_system` (`honeybook` | `crm`, default `crm` for CRM-born events, `honeybook` for imported ones), audited; MAIL planning, portal payment links and TIP refuse events with `honeybook`; an owner action "Pasar al CRM" per cohort (all imported events at once, with a preview of what will be planned) requiring a fresh reconciliation; reverse action allowed only before any CRM message was sent. Re-sync of imported events = IMPORT-01 update path with owner-reviewed diffs (payments, date/time, package, status).

## Owner decision 2026-10-19 — reconciliation freshness

César approved a maximum **4 hours (240 minutes)** between the last actual owner-reviewed source payments and authorizing the whole-cohort transfer. If older, review the payments again before switching. A preview reload or old-file re-upload is not a new review. This controls readiness for the switch; it does not configure automatic HoneyBook synchronization every 4 hours. Implementation/enforcement belongs to C3 and remains pending; this policy choice authorizes no real transfer or sends.

## Steps to the switch (checklist)
1. **History archive** — IMPORT-HISTORY (262 clients, 242 past parties): dry run → owner OK → import. Needed by ANNIV-01.
2. **Documents** — for every future party: signed contract PDF and invoice/receipts from HoneyBook (Print → Save as PDF; no bulk export), attached as originals, never re-signed. Past parties: only what César wants to keep.
3. **Reconciliation** — per event: total, paid, refunds/credits, balance, contract status, date/time = HoneyBook. Global counts and totals match. Report shows aggregates only.
4. **Website form** — switch the waooparty.com lead form from HoneyBook to the CRM (LEAD-01, already built) on switch-over day; keep HoneyBook's form unpublished, not deleted, until acceptance.
5. **Phone number (SMS)** — see below.
6. **Email** — provider chosen and verified (SPF/DKIM), templates approved, sandbox → live by César's explicit activation.
7. **Backups** — automated encrypted daily backup off Hostinger; **restore drill** on a clean environment, timed and documented, before the flip.
8. **Training** — 1 session with César and Maybeli on the real screens: day-to-day (Inicio, Calendario, Dinero, Zelle), quotes, quick booking, staff links, changes/cancel, reports; one-page runbook in Spanish.
9. **Switch-over** — final re-sync → reconciliation OK → HoneyBook automations off → flip cohort → form switched → watch the first week daily.
10. **HoneyBook stays read-only as backup** for an agreed period (e.g. 60–90 days **[César]**), then cancel only after: reconciliation complete, documents exported, backups restored once, César's OK. **Do not cancel the phone add-on before the number transfer completes.**

## Phone number transfer (HoneyBook → César's Twilio)
- Facts: the toll-free number is HoneyBook's Business Phone line inside HoneyBook's account; clients already receive texts from it; per HoneyBook Help Center it can be transferred out on request to support; cancelling the add-on loses the number permanently.
- Steps: (1) César opens Twilio account (Waoo Party LLC), no number purchased. (2) César asks HoneyBook support how the transfer works (message prepared 2026-10-18). (3) Before switch-over: request the transfer to his Twilio account; expect **toll-free verification again** under his account (do not assume it carries over). (4) SMS-01 stays disabled until the number shows as transferred + verified in Twilio. (5) Only then cancel the HoneyBook phone add-on. During the transfer, client texts go out from the CRM one-tap buttons (his phone) — no automated SMS.

## Rollback (if the switch goes wrong)
- Turn off new CRM automations (MAIL/SMS/TIP flags) and public booking; keep everything already recorded (payments, contracts, messages sent).
- Flip the affected cohort back to `honeybook` only if no CRM message/charge went out for those events; otherwise handle one by one.
- Re-enable HoneyBook automations for that cohort; publish HoneyBook's form again.
- Never restore an old database over newer payments: reconcile Stripe/Zelle and changes since the restore point (PROJECT_PLAN §17 rollback).

## Owner inputs
- How long HoneyBook stays as read-only backup; switch-over date (not set).
- Which past documents to keep beyond future parties.
