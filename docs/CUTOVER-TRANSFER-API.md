# C3 B4 · Whole-group switch ("Pasar al CRM") and reversal

2026-11-02 · Claude, covering Codex until 2026-11-04 (César's request). Sandbox only, own flag `waoo.sandbox_cutover_transfer` / `WAOO_SANDBOX_CUTOVER_TRANSFER` (default OFF) on top of the cutover + bridge flags. Codex review required before merge.

## ActivateCutoverTransfer
`execute(User $actor, {opening_set_id, expected_opening_set_sha256, owner_confirms_switch:true}, uuid)` inside `CutoverCohort::run` (actor → import singleton → grants → events → links).
- Replay of the same key returns the same transfer. A second switch of a set is 409 (unique transfer per set).
- Re-proves what preparation proved, on current rows: reconciliation intact and unexpired, readiness `missing === []` (this includes the owner's HoneyBook automations-off and collections-retired attestations), same cohort hash, opening-set members equal current `OpeningAgreementFacts`; set unexpired (240 min); no active slot for any member; every member still `honeybook` at the frozen event version.
- Per member: original-source `FinancialRevision` with the exact adapter shape from CUTOVER-RECEIPTS-API (`lines_snapshot` source text, package/travel/discount components, tax 0, customer_fee 0, original total, deposit target, reviewed CRM due instant). An event may only already have finance rows that belong to reversed transfers (revision = max + 1).
- `effective_at` = now (second), taken **after** the finance rows exist; must be ≥ source observation and set creation and < set expiry.
- Sealed `CutoverTransfer` payload (same schema as the B2 fixture), bindings, `cutover_active_openings`, `events.responsible_system = crm` via direct update (no event version bump: operations unchanged). Audit per event + transfer.
- Self-check: `EffectiveOpeningAgreement::find` must accept every member, otherwise 409 and the whole transaction rolls back.
- Creates no grant, message, reminder, charge or HoneyBook call.

## ReverseCutoverTransfer
`execute(User $actor, {transfer_id, expected_transfer_sha256, reason}, uuid)` in the same cohort protocol.
- Whole group only: bindings = active slots = manifest members, all still `crm`.
- Refused (409, listing what happened) if for any member since `effective_at` there is: a ledger row, an opening receipt allocation, a payment intent (or any non-terminal one), a Zelle notice, a tip grant/intent, an outbound message or mail occurrence, an SMS, or an unrevoked portal grant created after the switch. Unknown is never treated as "not sent / not paid".
- Effects: delete active slots, responsibility back to `honeybook`, immutable `cutover_reversals` row (encrypted payload + hash), audits. Transfer, bindings, opening and finance rows remain as history.

## Owner screen
"Cambio final" → "Pasar al CRM": with the flag on and a current prepared set, button "Pasar N fiestas al CRM" (browser confirm); after the switch, "Deshacer el cambio". Flag off keeps the previous disabled button and blocker texts.

## Not done
Scheduling reminders/balance links after the switch (separate bounded plan, no catch-up burst); MariaDB two-process race proof for activate vs. a concurrent receipt; production adapters.
