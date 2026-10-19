# CHANGE-A A1–A3 — proposed API for review

2026-10-19 · Codex · design only; **no change backend, reservations, money revision or cancellation implemented**. Implementation follows C2/C3 and the history slice. Sources: PHASE-7.md, BACKLOG CHANGE-A and tickets/CHANGE-A-CAPACITY.md. Claude review is required before coding under Phase7's published rule.

## Decisions preserved and unresolved

A recorded request is not a changed booking. The $100 change fee may be waived by the owner with a reason; original signed-contract limits (one reschedule, within60days) must be read from the actual contract version, not today's template. Whether new-date prices retain the original agreement or use current prices remains César's decision; no default is selected. Unknown/missing/imported contract terms require review rather than applying today's policy retroactively.

**A2 plan conflict to resolve with Claude:** PHASE-7 says "holds the new slot"; CHANGE-A-CAPACITY says to follow the accepted lifecycle "rather than adding a new pre-payment hold". Proposed first A2 is a current resource/logistics check, explicitly **not a guaranteed hold**; old reservations remain. A4 acceptance rechecks and atomically exchanges under the existing reservation locks. Do not silently create a hold with an invented duration. If a guaranteed proposal hold is required, freeze its lease/release/expiry policy and shared scheduler representation before implementation; an expiring lease must never release the original booking. This proposal does not claim either behavior is already approved.

## Proposed records

`event_changes`: id,event,kind(reschedule/cancel),state(draft/proposed/accepted/declined/void),version,owner,reason,displayed event/customer/financial/contract IDs+versions+hashes, immutable base operations/contract/money fingerprint, created_at. Freeze accepted/terminal records; corrections append evidence, never rewrite the old agreement. Multiple drafts may exist; only one can apply against a given original agreement/version.

`event_change_revisions`: immutable proposal snapshot with proposed local dates/timezone/per-time CDT-CST selection and normalized UTC instants, address/location snapshot, existing service/character/outfit identities and assignments, logistics/review evidence hashes, quote of travel fee, current check ID, proposal financial snapshot ID, author/reason/version. Child/contact fields remain unchanged by this reschedule path. Changing a proposal increments its version and invalidates old check/money/addendum evidence without touching the live event.

`event_change_checks`: immutable check snapshot/result bound to the exact base+proposal versions and neighboring event/resource revisions; no old reservation deletion. Validity is rechecked at acceptance, not a promise for the UI. Closed issue codes and role/costume/equipment/driver coverage; safe owner-only logistics evidence, no private staff contact spill into a client projection.

`event_change_financial_proposals`: immutable before/after lines and policy evidence, original total/paid/refunds/credit, retained original price IDs or explicitly selected approved new-price IDs, change fee10000 or0 with waiver reason, old/new travel fee and delta, total/difference/proposed balance. **Separate from effective FinancialRevision**: current consumers select the latest revision, so inserting a proposal there would silently replace the actual agreement. Only accepted A4 can create/bind the effective new revision. A3 never edits ledger, moves payments or assumes a refund occurred.

A1/A2/A3 audits contain internal IDs, versions and hashes; reason/private snapshot storage uses the existing private evidence conventions. No raw customer addresses, phone, token or provider payload in application logs. No file, mail or provider action from a proposal write.

## Proposed services and routes

Namespace `App\Services\Changes`. Owner/2FA, sandbox-only `waoo.sandbox_changes`, staging HTTPS, CSRF/origin, private no-store; closed inputs. Writes need UUID Idempotency-Key with exact payload replay; source/event/resource/current request receipts use locking reads under MariaDB REPEATABLE READ.

```php
CreateEventChange::execute(User $actor, int $eventId, array $input, string $key): array
ReadEventChange::execute(User $actor, int $changeId): array
SaveRescheduleProposal::execute(User $actor, int $changeId, array $input, string $key): array
CheckRescheduleProposal::execute(User $actor, int $changeId, array $input, string $key): array
PrepareChangeMoney::execute(User $actor, int $changeId, array $input, string $key): array
VoidEventChange::execute(User $actor, int $changeId, array $input, string $key): array
```

POST `/admin/api/events/{event}/changes`; GET `/admin/api/changes/{change}`; POST `/admin/api/changes/{change}/proposal`, `/check`, `/money`, `/void`.

A1 input `{kind,reason,expected_event_version,financial_revision_id,contract_id,contract_version,expected_agreement_sha256}` comes from one authoritative event-agreement read. That read must distinguish native vs reviewed HoneyBook opening; no finance/contract ID supplied by the browser can switch source authority. A1 may record a source-blocked request with explicit status, but no HoneyBook execution/collection/notice bypasses C1 or an absent financial bridge. Draft/unconfirmed events continue using draft editing, not this lifecycle.

A2 save input `{expected_change_version,expected_event_version,starts_at_local,ends_at_local,timezone:"America/Chicago",start_offset_choice?,end_offset_choice?,address,postal_code,location_latitude?,location_longitude?,setup_minutes,breakdown_minutes,resource_ids,review}`. Use existing server Houston parser and current scheduling/logistics rules, including equipment, outfit compatibility, team-driver constraint and approved travel buffers. Do not invent extra staff or reprice a package. Client-supplied computed durations/distances/fees/availability are never authoritative. A2 check pins the displayed change version and proposal hash; returns a new check snapshot or a blocked result while retaining all old reservations.

A3 input `{expected_change_version,expected_proposal_sha256,check_id,expected_check_sha256,price_policy_id,waive_change_fee,waiver_reason?}`. price_policy_id must refer to César's approved versioned policy; when absent return `price_policy_required`, no implicit original/current choice. Re-run authoritative travel fee against the new location/party context; original immutable total remains separate. Original payments are displayed as actual net paid and preserved, not recomputed from a50% assumption. Unknown collection/refund outcome blocks a client-payable proposal. Discounts follow their original rule/policy evidence; do not silently reapply an expired promotion or stack a new one. Negative difference is a possible credit/refund obligation, never an automatic payout or negative receipt.

Read model:

```text
change:{id,version,kind,state,base_event_version,agreement_kind,agreement_sha256}
current:{date,start,end,timezone,package,characters,resources,total_cents,net_paid_cents,balance_cents}
proposal:{revision_id,sha256,date,start,end,timezone,location,...}|null
check:{id,sha256,state:clear|blocked,guaranteed_hold:false,issues,coverage,checked_at}|null
money:{id,sha256,policy_id,before_total_cents,change_fee_cents,fee_waived,
       old_travel_cents,new_travel_cents,difference_cents,after_total_cents,
       actual_net_paid_cents,proposed_balance_cents,possible_credit_cents}|null
blockers:[{code,field?}]
actions:{can_edit,can_check,can_prepare_money,can_void,can_accept:false,can_cancel:false}
```

Amounts, labels and decision flags come from the server. Claude UI should say "Disponibilidad revisada; se confirma al aceptar" for check-only, show Antes/Propuesta/Diferencia and preserve the displayed versions through submit. A4/A6 controls remain disabled until their services/contracts are implemented; a preview is not an executable action. Repeat same key/body returns the original result without new fee/check/audit; a changed body/key mismatch or stale source =>409 and requires reload. Void draft/proposed only; no resource or money effect.

## Acceptance/cancellation boundary for later slices

A4 must acquire relevant grants and original/proposed event neighborhood/resource locks using the shared reservation protocol in stable order, then revalidate original agreement, change/proposal/money/addendum signature/current payment and neighboring logistics. Resource matching must exclude only the same event's replaced reservations; it cannot ignore another event or simply reuse ReservationNeighborhood against its own old slot. A failed recheck retains the entire original assignment. Success atomically swaps reservations, binds effective money/document evidence and increments operating state once. Never release old capacity before the new slot is secured.

A6 requires a separate approved contract/outcome read and explicit owner action. Deposit non-refundable/within48h **up to**100% is not an automatic100% retention decision. Weather50% credit/60days applies only to the source policy's paid-in-full conditions; no generic double charge or refund. Show the supported calculation/range and require an owner decision/override reason where the policy is discretionary or source evidence missing. Keep money and signatures, record actual credit separately, release capacity once and close pending payment collection with verified remote outcomes; real in-flight money is retained for reconciliation. Refunds need their separate owner/provider workflow. No cancellation notice until MAIL transport is separately approved/active.

A5 invalidates/replans date-bound mail occurrences after commit with stable identities; do not dispatch old reminders between acceptance and replan, nor replay missed reminders in a burst. Existing TIP links/unknown collections and cutover states must be considered explicitly before changing an ended party's authority; no history/receipt deletion. Staff portal reflects only the effective booking and current reservation; a proposal never changes its week sheet.

## Required tests for A1–A3

No event/reservation/ledger/mail/grant changes from record/proposal/check/money; original snapshots retained; stale event/customer/contract/finance/change/proposal/check conflicts; signed/changed/imported contracts; unknown policy refusal;100 fee and explicit waiver; exact actual-payment/discount/travel/credit math; DST/overnight and preparation buffers; all role/costume/driver constraints; neighboring event races and same-event old-slot exclusion; same-key current-read races; audit rollback; no client/staff API access. A4 atomic acceptance and A6 cancellation need their own full tests before either action is exposed.
