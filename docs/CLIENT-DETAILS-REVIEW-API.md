# Owner confirmation after same-visit signature — B1c API proposal

Contract before implementation; follow-up to APPROVED-CLIENT-FLOW-API.md B1b. This is a proposal, not a service, route or completed feature. The current owner approval service rebuilds the draft agreement and deliberately refuses signed contracts. Do not remove that protection to make a new button work.

Owner intent already established: a provisional customer can start with phone or email, supplies her real name through her link, and the owner confirms the details. B1b lets her sign the original agreement in that visit without fabricating an owner review. B1c supplies the separate, genuine owner confirmation afterward. The customer-facing signature, consent, email-unverified identity method and signed bytes remain immutable.

## Read once, pin the displayed intent

`App\Services\Booking\ReadClientDetailsReview::execute(User $actor, int $eventId): array`

Owner/2FA, finance/documents sandbox and existing HTTPS protections. Read the current event, customer, financial revision, latest immutable submission, actual owner review and signed contract/signature evidence coherently. No writes, grant renewal, activation, message or bank inference.

Proposed owner path: `GET /admin/api/events/{event}/client-details/review`. Existing routes remain unchanged until implementation.

Closed response:

```text
schema_version: 1
event_id, event_version, customer_version, financial_revision_id
submission: {id, revision, first_name, last_name, email, phone, locale}
current_customer: {name, email, phone, locale}
contract: {id, version, state, presented_pdf_sha256} | null
reviewed: boolean
intent: {
  mode: confirm_signed_identity | existing_unsigned_flow | unavailable,
  source_sha256: lowercase SHA256 | null,
  reasons: [closed reason codes]
}
```

Only an actual current review makes `reviewed=true`. The owner sees the current contact data beside the immutable proposed identity and exactly which fields confirmation would apply. Capture versions, identifiers and source hash when opening that review; keep them Locked for the action. A render or subsequent click must not silently substitute a newer submission, customer or signed document.

`confirm_signed_identity` is available only for the latest signed B1 native paid QuickBook document with matching closed submission provenance and genuine signature evidence, current unchanged event/finance and no existing review. The evidence must bind the same contract, customer, submission's grant and presented hash. A signed state flag alone is insufficient. Imported/source-original, quoted and other signed documents are unavailable to this narrow action; use their established review/change workflow.

The owner's review does not require the old client grant to remain usable: expiry/revocation must still block every client access, but cannot erase historical evidence or prevent an authorized owner from reviewing it. Do not fabricate a PortalContext or impersonate the customer.

A genuine linked capacity confirmation may advance the event's operational version after signature without changing the agreed date/address/price. Pin the current displayed version and compare the explicit agreement fields; do not rewrite the signed provenance or reject identity review solely because that operational version advanced. An actual agreement change remains outside this action.

## Confirm only identity; preserve the signed agreement

`App\Services\Booking\ConfirmSignedClientDetails::execute(User $actor, int $eventId, array $input, string $key): ClientDetailsReview`

Proposed owner path: `POST /admin/api/events/{event}/client-details/confirm-signed`. Stable UUID in `Idempotency-Key`; dedicated operation scope, distinct from the existing unsigned approval and client preparation scopes. Closed input:

```text
expected_event_version: positive int
expected_customer_version: positive int
submission_id: positive int
expected_revision: positive int
contract_id: UUID
expected_contract_version: positive int
financial_revision_id: positive int
source_sha256: displayed lowercase SHA256
review_note: owner text, 10..2000 characters
```

Authorize owner, then lock owner→event→customer→contract and recheck the displayed source, latest submission, genuine signature evidence and current financial revision. Source hashes use explicit versioned field lists: event identity/version/status/window; customer identity/version and displayed contact fields; financial revision identity/revision/event version; submission identity/revision and ClientSubmissionFingerprint; contract identity/version/state/financial revision/presented hash; signature identity/contract/customer/grant/presented hash/accepted time. Never hash/dump whole models or tokens.

Apply only the exact submitted first+last name, phone, email and locale to the customer through the audited customer service, using the pinned current customer version. The owner explicitly compares current and proposed fields, so this is not an invisible overwrite from an old base-customer snapshot. If the current customer changed after the review opened, return409 and require another displayed review.

Append a genuine ClientDetailsReview with the current event/finance, actual reviewer, actual review time and note. Keep confirmation after signature distinguishable in audit metadata; never backdate it to the client's submission/signature or claim an email was verified. The actual confirmed agreement's address/ZIP/time must still match the B1 provenance. Do not accept a pending change of location/time through this identity-only operation.

No event, services, duration, money, financial revision, receipt, resource reservation, submission, contract content/state, presented/final artifact, signature evidence or grant mutation. In particular: no supersession, reissue, repricing, new finance revision, refund, capacity reconfirmation, contract-ready email or automatic message. Address/time/price changes still require CHANGE-A. Confirmation is not a bank reconciliation or proof of payment.

Customer update, review, owner audit and mutation receipt are one transaction. An audit/storage failure rolls back the contact update and review together. Same-key exact replay returns the original genuine review without another update; changed intent conflicts. A concurrent/new UUID must not create a second review or update the customer twice. No implicit merge of customer records.

## Screens and privacy

In the owner event's contract page, signed B1 + unreviewed latest submission offers **Confirmar datos de la clienta**, with the side-by-side comparison, owner note and displayed pins above. After a successful genuine review, the existing reviewed badge may become **Aprobados · firmado**. Beforeward it remains **Recibidos · sin revisión del dueño**. Hide the old financially rebuilding approval action on signed contracts; do not make it an alias for this service.

The customer still proceeds to balance/done immediately after signing. No new customer/staff permission, money disclosure or personal-link sharing. Owner responses are private/no-store; audit entries contain identifiers, field-change evidence under the existing audited customer policy and source hashes, without request/exception logging or secrets. Real customer data never enters fixtures/Git.

## Required tests before readiness

Actual same-visit client details→PDF→signature, followed by an independent owner confirmation: customer name changes only at owner confirmation; reviewed changes only after the genuine review; reviewer/time are real; event/finance/receipts/reservations/signed artifacts and signature bytes/hashes stay identical. Also stale displayed customer/submission/event/finance/contract/hash, forged signed flag without evidence, another customer's contract, imported/quoted source, existing owner-reviewed workflow, two-owner race, exact replay versus changed intent, audit rollback of customer+review, revoked/expired client grant with authorized owner review, client/staff denial, flags/2FA/transport/CSRF, and no provider/message call.
