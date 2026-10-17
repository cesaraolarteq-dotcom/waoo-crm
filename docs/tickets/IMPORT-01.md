# IMPORT-01 · Bring future HoneyBook bookings into the CRM before the pilot (Phase 5)

Status: contract proposal by Claude (mapping UI, owner review screen) for Codex (importer, reconciliation). Owner action needed: export from HoneyBook (projects/clients/payments CSV) — no HoneyBook credentials in chat or git.

## Goal
Every **future confirmed** HoneyBook event must exist in the CRM (and block staff/costumes/equipment) before the CRM takes new bookings in parallel, so nothing is double-booked. ~40 events/month; history (~250 archived) is IMPORT-02 / Phase 8, not this ticket.

## Flow
1. Owner uploads the HoneyBook CSV export(s) on an owner-only page (file stays in private storage; deleted after import; never in git).
2. Dry run: parse → proposed customers (match by email/phone, never auto-merge different people), events (date/time Houston, address/ZIP, package text), amounts (total, paid, balance) with a per-row status: ready / needs mapping / conflict / skipped (past or cancelled).
3. Owner maps HoneyBook package names to CRM service variants (remembered mapping), picks characters/outfits when the text is ambiguous, confirms travel fee as recorded in HoneyBook (evidence = “imported”, not recalculated).
4. Import creates customers, event drafts and an **imported ledger** entry for money already received (marked source=honeybook, not re-verified as Zelle/Stripe), then the owner confirms capacity per event through the normal ConfirmCapacity screen (or a batch confirm with the same checks). Contracts stay in HoneyBook for these events (link/reference stored).
5. Reconciliation report: count and totals HoneyBook vs CRM; anything not imported listed with reason.

## Rules
Idempotent by HoneyBook project id; re-import updates only rows still in draft; no messages to clients; no Stripe/Zelle actions; amounts never invented; Houston DST handled by ChicagoTime.

## Tests (minimum)
Same file twice → no duplicates; ambiguous package → needs mapping; past/cancelled skipped; totals reconcile; customer with same name but different phone not merged.
