# BOOK-QUICK — proposed service contract (Claude → Codex)

2026-10-16 · Claude. **Proposal, not implemented.** Extends DOC-PAY-API.md for the owner's real flow (docs/plans/QUICK-BOOKING.md). Claude's screens are built against these names and stay **off until the classes exist** (same pattern as RECORD-SERVICES). Codex owns services, schema, validation, audit; Claude owns screens. Nothing here sends a message, charges money or deploys.

## 1. Owner half, atomic: `App\Services\Booking\QuickBook`

`execute(User $actor, array $input, string $idempotencyKey): QuickBooking` — one transaction that does what the owner does today by hand, reusing the existing audited services internally (no duplicated rules):

1. Customer: `customer_id` **or** `new_customer {name, phone?, email?, locale}` (SaveCustomer rules). Name may be provisional; the client confirms her legal name in the portal.
2. Event draft (SaveEventDraft rules): `title`, `event_type` (required here, deliberate), `starts_at`/`ends_at` with explicit offset, `postal_code`, `travel_fee_cents` (**required non-null** here: a quick booking fixes the money), `address` optional (client completes it), `setup_minutes`/`breakdown_minutes` optional, `services[] {price_version_id, characters[]}`.
3. Financial revision (CreateFinancialRevision).
4. Verified deposit receipt (RecordZellePayment for Zelle; card/cash later): `deposit {method: zelle, amount_cents, reference, received_at, verified_received: true, note?}`.
5. Portal grant (IssuePortalGrant) with scopes `details.submit`, `contract.read`, `contract.sign`, `payments.read`, `checkout.create`; `expires_at` default 14 days (owner can change). **Raw link returned once.**

Returns `QuickBooking {event, customer, financial_revision, receipt, finance (FinanceSummary), portal_url (once), portal_expires_at}`. Same key + same payload → same result **without a second link secret** (return `portal_url: null` and `portal_link_already_shown: true`; the owner can revoke/reissue). Any failure rolls back everything **except** that money cannot be "rolled back" once it is a real external receipt: if the deposit step was reached and a later step fails, keep the receipt and open an owner exception (same principle as ConfirmPaidCapacity). Errors: ValidationException with the existing field paths prefixed (`new_customer.*`, `services.*`, `deposit.*`), RecordWriteRejected 409 for stale/duplicate reference.

Capacity is **not** reserved here (D7 holds by deposit, but staff/costume assignment stays the separate "Apartar recursos" step), unless the owner later asks for an optional `reserve` block reusing ConfirmPaidCapacity.

## 2. Client half: portal details

Portal `GET /portal/api/event` (DOC-PAY-API) plus:

`POST /portal/api/details` (scope `details.submit`, CSRF, Idempotency-Key) → `Portal\SubmitClientDetails::execute(PortalContext $client, array $input, string $key)`:

| Field | Rule |
| --- | --- |
| `first_name`, `last_name` | required, adult contracting party (not split automatically from the owner's provisional name) |
| `phone` | required |
| `email` | required, email |
| `billing_address` | required (contract) |
| `event_address` | required; may equal billing if the client says so |
| `start_time_confirmed` | required boolean; if false, `requested_start_at` (ISO with offset) → owner review, never auto-moves the event |
| `child_name`, `child_age`, `theme`, `notes` | optional |
| `sms_consent` | optional boolean, with the website's TCPA wording/version |

Writes a versioned **client submission** (immutable, audited with grant id), updates the customer/event draft fields the owner allowed, and marks "datos completos". It never changes price, date or services. After submission the contract can be created/issued (DOC-01) with these values. Re-submission before signing creates a new submission version; after signing, changes go through CHANGE-A.

## 3. Owner home signals

`GET /admin/api/quick-bookings?state=waiting_details|ready_to_issue|waiting_signature|signed` for the Inicio "Necesita tu atención" cards.

## Screens Claude builds against this

- **Reserva rápida** (Filament page, pink button): package(s) + characters, type, date/time, ZIP + travel fee, client (existing or quick new), deposit received → shows total, deposit, balance and due date live; on save shows the **link once** with "Copiar enlace" and a ready WhatsApp/SMS text in ES/EN (no sending).
- **Portal** (client, phone-first, ES/EN, no third-party assets): Tus datos → Contrato (read full PDF, typed name + consent) → Pagar (Zelle instructions with exact amount/memo, or Stripe test checkout) → Listo.
