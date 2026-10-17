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

## Source file format (owner decision 2026-10-17)

The owner extracts HoneyBook data through ChatGPT's HoneyBook connection (neither Codex nor Claude Code can reach HoneyBook directly). The importer accepts **one CSV, one row per future confirmed event**, UTF-8, header row exactly:

`honeybook_project_id, project_name, status, client_first_name, client_last_name, client_phone, client_email, event_date, start_time, end_time, event_address, event_zip, services, characters, package_total, travel_fee, discount, total, amount_paid, balance, balance_due_date, contract_signed, payments, notes`

- Dates `YYYY-MM-DD`; times `HH:MM` Houston wall time (resolve with ChicagoTime; ambiguous/nonexistent → needs review, never guessed).
- Money in dollars with two decimals, no `$`; parse to integer cents; empty = unknown (never 0). Check `package_total + travel_fee − discount = total` and `total − amount_paid = balance` per row; mismatch → needs review, imported values kept as source evidence.
- `services` / `characters` = HoneyBook text joined by ` + `; mapped by the owner to CRM variants/characters in the dry-run screen (mapping remembered).
- `payments` = `YYYY-MM-DD|amount|method` joined by `;` → imported ledger lines with source `honeybook` (not re-verified Zelle/Stripe).
- `contract_signed` yes/no → stored reference only; contracts stay in HoneyBook for these events.
- The file contains real client data: owner uploads it on the owner-only screen to private storage; it is never committed, logged or pasted into chat; deleted after import. Synthetic fixtures only in tests.
- Claude reviews the owner's real file locally (mapping + reconciliation report for the owner) and shares only aggregate findings/format issues in HANDOFF, never row data.
