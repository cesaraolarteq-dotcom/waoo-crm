# Event billing company — owner decision, contract v1 (2026-10-21)

Customer stays a person. Optional event billing_company={name,email?,address?}; name required if enabled.
Default-off WAOO_SANDBOX_COMPANY. No customer company merge, automatic email recipient change or new payment.
Owner writes pin Event.version; updates increment it, audit metadata only. Company data is encrypted on event.
QuickBook paid/unpaid accepts optional billing_company atomically. Portal company input is optional and
belongs to the proposed client details; owner approval applies to event. Same-visit contract must freeze
exactly the submitted company in its legal commercial snapshot, not a later mutable event value.

Quick booking and portal show ES/EN optional “Es para una empresa” / “This is for a company” + name.
Owner event block supports billing email/address. Render optional snapshot block:
“Facturar a: Empresa · Atención: Persona” / “Bill to: Company · Attention: Person”.
No company: existing snapshots, fingerprints and PDFs retain their previous shape and bytes.
Quotes/contracts/new invoice-like financial documents capture company in the immutable snapshot; later
edits do not rewrite already-issued/signed PDFs. Person remains signer and identity/portal recipient.
No private billing information on staff sheets or public availability.

Client-submission fingerprint uses a new conditional schema only when billing_company is present;
old submissions and legal evidence retain their original fingerprint. Feature gate rechecked at writes;
previously frozen company evidence still renders if the feature is later disabled.

Implementation seam: event billing_company_version is independent of operational capacity. Standalone
owner write `SaveEventBillingCompany::execute(actor,eventId,{expected_event_version,expected_billing_version,
billing_company},uuid)` pins both; increments billing version, not capacity/Event.version. Quote snapshots
pin billing version; an existing issued/signed contract or accepted quote with different billing data
requires the document/change workflow. Owner approval may apply the identical company already frozen
in the client's contract. SaveEventDraft still increments its usual event version.

There is currently no native issued-invoice PDF module. This delivery adds an owner-only PDF **preview**
from a transaction-consistent billing context, labelled draft/not a receipt, without issuing an invoice
number, creating money or claiming an issued immutable invoice. The shared billing block is ready for
future actual invoice issuance. Existing HoneyBook originals remain byte-for-byte originals.
