# ANNIV-01 — preparation only, v1 (2026-10-21)

`ReadBirthdayCampaignDraft::execute(User $actor, array $input): array`.
Input `as_of` strict Houston date. Owner admin + confirmed 2FA + secure transport;
`WAOO_SANDBOX_ANNIVERSARY=false` and history flags required. No writes, outbound transport,
scheduler, codes, coupon redemption, or outbox insertion.

Reads current historical revisions and completed CRM birthdays only, taking the latest completed event
per customer. Requires a reliable past date. A later noncancelled operational event suppresses the invitation.
Returns owner-only customer_id/anchor/date/anniversary/proposed_review_on/status/reasons. No contact details
in this projection; customer file is already separately authorized. Unknown historical status is excluded.

The proposed review date is one calendar month before the next anniversary, per the latest request.
This is a preparation date, not a send date; the older six-week sending policy is not modified.
February 29 in a nonleap anniversary year is flagged for owner review instead of guessed.

No promotional permission ledger exists in the current CRM. Therefore each otherwise eligible draft has
`marketing_permission_unverified` and `sendable=false`, regardless of the raw HoneyBook SMS field.
This is a blocker visible to the owner, not inferred consent. No approved recipient list is claimed.
A future consent implementation must check channel, scope, withdrawal, suppression and evidence at send time.
Draft ES/EN copy is returned with `approved=false`; no real discount code is issued.
