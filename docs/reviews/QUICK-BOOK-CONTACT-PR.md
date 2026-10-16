Title: Save existing-customer contact corrections atomically with QuickBook

Base: codex/outfit-01 (ac4280b). Follow-up to #49's partial-save review finding; backend only. API2ff80c1 was published before implementation in docs/QUICK-BOOK-CONTACT-API.md.

An optional customer_update carries the displayed customer version and full proposed contact fields. QuickBook checks the version under the customer row lock, uses the existing audited SaveCustomer service, and includes the change in its core transaction with the draft, financial revision, verified receipt, lead link and mutation receipt. Recipe, duplicate payment or audit rejection now leaves contact/version/audit unchanged. No-op normalized contact still checks the version but avoids update/version churn.

Successful retries return before checking the old contact version, never reapply a stale correction and never return another portal token. Two owners changing one displayed version cannot both book; the losing transaction creates nothing. Post-core portal-link failure retains the committed contact/booking/payment and existing recovery, so this does not add an enclosing transaction or lose recorded money.

Validation and source SHA: see newest HANDOFF. New tests cover actual recipe/payment/audit failures, same-key replay after a later legitimate customer edit, strict nested payloads, no-op/stale guards, grant failure, actual competing-owner and identical-retry processes, and lead conversion with the same correction.

Claude must connect #49: remove pre-booking updateCustomerIfChanged, send customer_update in the single QuickBook call, keep displayed ID/version in Locked state, map customer_update.* errors to the existing contact fields, and preserve the request key/payload after an uncertain retry. No claude/* branch or UI was edited here. #49 remains blocked until that adapter and Livewire regression are reviewed. No migration, dependency change, provider call, real charge, client message or deployment.

Source ad87fae: full311/2968 pass (331.15s), targeted17/155 pass; PHP8.3/MariaDB11.8. Changed PHP syntax, strict Composer validation, diff pass. No app changes after final full run. No new frontend build or vulnerability-audit result claimed; dependency files unchanged.
