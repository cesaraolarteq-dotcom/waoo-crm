# C3 owner cohort members — bounded, consistent read

2026-10-20 UTC · Codex · API before implementation. Stacked on codex/opening-client-portal; no activation, collection or source modification. Addresses Claude #91's pending bounded members projection.

`ReadHoneyBookCutoverMembers::execute(User $actor,array $filters=[]):array`

GET `/admin/api/cutover/honeybook/members`. Existing owner2FA, imports AND independent cutover sandbox, local/testing/staging only, secure staging transport, CSRF where applicable and private/no-store. Never expose through client/staff sessions. Closed optional query `{page,per_page,expected_cohort_sha256}`: positive page; per_page1..50 (default25); expected hash64lowercase hex when provided. Unknown keys/event IDs/subsets/filter selectors are rejected422. No date filter, active-only selection or browser-selected cohort.

One existing CutoverCohort transaction (current owner → importer singleton → grants → events → source/evidence) inspects the complete server-selected group and staged-source scope exactly once. Reuse that same readiness snapshot's per-member inspections; never run another full cohort transaction for each row or compute global totals from a page. Existing whole-group technical bounds remain: >2000 links or oversized source coverage fail409, never silently truncate. This bounds output and removes repeated per-row cohort scans, but does not claim constant-cost preparation or background snapshot storage.

Response includes the existing readiness projection from that SAME transaction:

```
cohort_sha256,counts,totals,unknowns,readiness,reconciliation,
sending_enabled:false,transfer_available:false,
page:{number,per_page,last,total},
rows:[{
 event_id,title,starts_at,ends_at,timezone,status,responsible_system,
 source:{total_cents,net_paid_cents,balance_cents,funds_verified:false},
 missing:[codes],
 review:null|{version,source_observed_at,expires_at,current,
              honeybook_automations_disabled,external_collections_retired}
}]
```

Stable order by imported link ID, identical to existing cohort membership order. total is complete imported-member count; page clamped to last (empty group number=last=1,rows=[]). A missing/invalid source amount stays null; source payments are not bank-verified. Readiness is still evidence-only and transfer unavailable. No contact/email/phone/address, source project keys/raw cells/payment methods/notes, bank refs, PDF bytes/path/hash/ciphertext, tokens, staff or resource details. Event title is owner-visible; never log/return it outside this private owner projection.

Initial read may omit expected_cohort_sha256. Subsequent page reads should pass the displayed hash; if current complete snapshot differs, fail409 (code `cutover_cohort_changed`) with a generic review-again message and no partial rows. This hash has exactly the existing readiness/preparation meaning; it is NOT a page receipt, consent, freshness renewal or activation token. A clock-only expiry may change missing/readiness without changing membership hash: return current readiness and still require existing prepare checks. No writes to reviews, reconciliation, audit, source or sessions, and no source calls.

**Claude wiring:** replace HoneyBookCutover::members' unbounded link list and one ReadHoneyBookCutoverReview per row with ONE service call per render, using its readiness and rows together. Add pagination controls; pin displayed cohort and preserve #91's reviewedCohort reset when the group changes/refuses. An old-hash409 must clear scope attestation and retry key, then deliberately reload first page without the old hash. Navigation never selects/excludes parties from preparation. Keep the server's unchanged full-group preparation route and transfer button disabled. No UI implementation by Codex in this delivery.
