# QuickBook: atomic existing-customer contact corrections

Published before implementation. Follow-up to #49 P1; backend only, on the LEAD → ROUTE → OUTFIT stack. Claude owns the UI adapter. Existing `QuickBook::execute(User $actor, array $input, string $key): QuickBooking` and POST `/admin/api/quick-bookings` remain; owner/2FA, sandbox and idempotency guards unchanged. Lead conversion delegates to the same API.

For an existing customer, optionally include the whole proposed contact record:

```json
{
  "customer_id": 123,
  "customer_update": {
    "expected_version": 4,
    "name": "Fixture Customer",
    "email": "fixture@example.test",
    "phone": null,
    "locale": "es"
  }
}
```

This is a fragment: all existing booking/deposit fields remain required. IDs/versions above are fictional. `customer_update` cannot accompany `new_customer`; cannot be null; allows only expected_version/name/email/phone/locale. Name/version/locale required, email/phone keys required but nullable. Existing SaveCustomer lengths/email/locale rules apply. Omission preserves the original existing-customer booking path; no customer update is inferred from lead or client data. No SMS consent mutation.

Within QuickBook's existing core transaction: lock owner → look up idempotent result → optional lead lock → lock existing customer → check displayed expected_version → save changed contact through audited SaveCustomer → draft/financial revision/verified payment/booking record/lead link/audits/idempotency receipt. A failure rolls back ALL these writes, including contact/version/audit. Stale customer409; invalid nested values422 with customer_update.* paths. Unchanged normalized contact checks the version but does not increment it or create a customer-update audit. Actual changes increment exactly once. No outer transaction is added: post-core portal-grant creation/recovery stays separate. A portal-link failure returns the committed booking/payment/contact with link_issue_pending; never claims rollback.

Same owner/key/identical payload retries return the original booking before rechecking the old customer version, do not reapply contact or emit another update audit, and never disclose another portal token. Same key/different payload409. A later legitimate customer correction is not undone by an old successful booking retry. Two owners submitting changed contact from the same version: one whole booking succeeds, the other409 without contact/event/payment residue.

Request for Claude (#49): remove updateCustomerIfChanged and its pre-booking SaveCustomer call. When editing an existing customer's contact, send customer_update in the one QuickBook call. Capture displayed customer ID/version in Locked server-side component state at selection, not a mutable Hidden field or a fresh version read during save. Do not replace the key/payload on an uncertain timeout retry; keep the same proposed contact/version. Map `customer_update.name|email|phone|locale` errors to c_name|c_email|c_phone|c_locale. A stale conflict retains unsaved form values and asks for reload. Existing page code is deliberately not rewritten on claude/*; #49 remains blocked until its adapter is connected and tested. Existing event-character mappers must also preserve outfit/note per OUTFIT API.
