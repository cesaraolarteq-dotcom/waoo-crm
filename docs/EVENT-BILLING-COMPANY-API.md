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
