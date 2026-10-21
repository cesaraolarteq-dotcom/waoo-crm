# STAFFPAY-01 — owner-only calculation and payment evidence, API v1 proposal

Contract before implementation. Source: César's latest instructions relayed with the history-intake handoff (2026-10-20): weekly Zelle, only the package duration with a two-hour minimum, transport only for the driver with an amount entered by the owner, equal tip shares, and Waoo absorbs the provider commission. These decisions supersede the older missing-rule list in PHASE-6.md. This document implements nothing and earns no completion credit. Review before wiring screens.

No automatic payout, payroll provider, tax calculation, staff money page, outbound message or deployment. Do not infer a transfer from a button press, imported source amount, bank reference or Stripe tip notification. The existing staff portal remains read-only and contains no compensation.

## First slice: immutable event compensation snapshots

Services under `App\Services\StaffPay`, all owner/2FA and sandbox finance gated:

- `ReadStaffPayEvent::execute(User $actor, int $eventId): array` — explicit current agreement, source fingerprint, deduplicated assigned people, package duration evidence, driver evidence, current planned/final revision and missing facts. Reads never create rates, assignments or payment records.
- `SaveStaffPaySnapshot::execute(User $actor, int $eventId, array $input, string $key): StaffPaySnapshot` — closed input below, stable UUID, expected displayed source versions. Atomic event + sorted staff locks, append-only snapshot + audit + mutation receipt. No resource assignment changes, ledger receipt, actual Zelle call or client message.
- `ReadStaffPayWeek::execute(User $actor, array $input): array` — owner-selected Houston `starts_on` and seven-calendar-day window, versioned source projection, per-person known amounts/missing states, planned/final separately. A DST week is seven local dates, not 168 elapsed hours. No invented weekday for payday or automatic release.

Proposed owner HTTP paths: `GET /admin/api/events/{event}/staff-pay`, `POST /admin/api/events/{event}/staff-pay`, `GET /admin/api/staff-pay/week?starts_on=YYYY-MM-DD`. No routes are present until implementation. Responses have private/no-store headers and closed projections; staff/client grants cannot call these routes.

This first write schema requires a native current financial revision. Imported/source-original events need a later explicit adapter to the versioned agreement/financial bridge; reads expose `unsupported_agreement_source` rather than inventing a financial revision, zero cost, verified bank receipt or completed compensation. HoneyBook's responsibility for customer collections must not be misrepresented as proof that staff were paid.

Snapshot input:

```text
expected_event_version: positive int
financial_revision_id: positive int
expected_revision: nonnegative int (per event and kind)
source_sha256: displayed 64 lowercase hex chars
kind: planned | final
people: [
  { resource_id: positive int,
    package_service_id: positive int | null,
    base_amount_cents: nonnegative int | null,
    transport_amount_cents: nonnegative int | null,
    extra_amount_cents: nonnegative int | null,
    reimbursement_amount_cents: nonnegative int | null,
    note: owner text }
]
coverage_confirmed: boolean (default false)
note: owner text, 10..2000 characters
```

One row per assigned person per event, regardless of role count or how many reservation slots represent that person. All people must be from the displayed assignment source; missing/unassigned people require fixing the operational assignment first. A person who performs and drives remains one person, with separate base and transport components. Unknown is null, known zero is zero; never silently convert one into the other. `coverage_confirmed` is explicit and cannot turn a missing required fact into a known amount.

For an unambiguous selected package, expose `package_minutes` from the agreed event-service snapshot and `payable_minutes = max(120, package_minutes)`. Do not pay travel/setup/breakdown as package time. No automatic multiplication by number of characters, staffing slots or skills. Multiple packages or an unclear mapping of a person's work to packages produces `needs_duration_review`; the owner must identify the agreed service, without changing the customer's agreement. Until a later approved rule handles multiple sequential packages, do not finalize such a case by choosing maximum/sum or duplicating the two-hour minimum.

Initial amounts are exact owner-entered cents, visibly labelled as such. No implicit tariff from catalog price, package margin, old role labels, skill names or historical wages. A later rate engine needs explicitly approved role/rate mapping and minute-proration/rounding rules; this contract does not invent them. Unknown rates or unresolved duration are visible blockers to claiming calculated wage completeness. The owner note and source evidence distinguish manual amounts from a future calculated amount.

Nonzero transport requires that the same person is actually designated as a driver in the current assignment evidence; a `driver` skill alone is insufficient. No per-mile, passenger, route or split formula. If operational data identifies only eligible drivers without choosing the actual driver, return `needs_driver_designation`, and do not accept a nonzero transport amount until that prerequisite has a versioned contract/implementation. Zero transport does not assert that someone drove.

Source fingerprints use explicit versioned field lists: event identity/version/status/window/responsibility; financial revision identity/version; agreement service identity/duration; actual assigned staff/role/driver evidence and reservation identities/versions. Never hash whole models with `toArray()`/`getAttributes()`. Source changes make a snapshot stale, never rewrite it. Do not include client phone/email, billing address, bank detail or staff link tokens in compensation hashes/exports/audits.

No auto-write into COST-A. If a later explicit adapter posts an approved final snapshot to event costs, its unique source identity prevents counting it twice alongside manual costs. Planned compensation is never cash paid; final compensation is never proof of disbursement.

## Tips, kept separate until verified

Do not add an owner-entered tip amount to the first-slice input. A later versioned adapter reads genuine collected, source-linked TIP evidence, its currency, amount, refunds/disputes and selected recipient set. The full customer tip amount is the distributable pool; Stripe's processing commission is Waoo's cost and never reduces a person's share. Original tip evidence remains immutable.

Equal division can leave indivisible cents. Do not invent a recipient preference, redistribute after a final statement or declare the pool fully allocated until a deterministic remainder rule and the recipient membership policy are approved. Preview may expose quotient/remainder and `needs_tip_allocation_policy`. Do not include pending/unsettled/disputed amounts as available cash. If a refund/dispute arrives after an approved allocation, show a review state and retain history; no automatic negative staff payment or clawback.

## Weekly payment evidence — later slice, not an actual transfer

Publish the concrete close/payment API before implementing it. Requirements already frozen:

- A weekly statement freezes exactly the displayed final, current, fully reviewed snapshot set for one person. Multiple concurrent closures cannot pay the same assignment/snapshot twice. Planned/stale/unknown amounts block a complete statement.
- Corrections append revisions/adjustments; paid statements cannot be overwritten or silently reopened. A changed assignment/package does not rewrite an already paid week.
- The owner records an actual Zelle amount and Houston received/paid date after personally checking it, with stable UUID and explicit confirmation. Duplicate controls must use the exact person/statement/amount/day, not just an optional reference. Optional reference alone is not proof or a uniqueness guarantee.
- Preserve statement total, actual amount, outstanding/overpaid difference and reconciliation evidence separately. No bank integration, automated payout, client receipt ledger entry or assertion that a provider transfer occurred.
- Export is owner-only/private and uses the frozen statement. The durable staff-week link remains without money. Any personal statement-sharing channel needs a separate owner-approved access contract.

## Required verification before implementation can be called ready

Synthetic realistic names only. Cover: two-hour minimum; exact agreed duration; two roles/one person without double counting; missing/zero values; source staleness and version-pinned replay; driver skill versus actual designation; non-driver transport refusal; owner/2FA/flags/cross-staff denial; complete transaction rollback on audit/storage failure; DST week boundaries; no money in staff/client pages; historical/imported compensation never inferred from customer payments; disputed/refunded tips; equal-share remainder blocker; duplicate concurrent snapshot/statement/payment attempts; owner-only export privacy. No live payment or message is part of verification.
