# Owner night walkthrough — contracts and verification, 2026-10-20

Priority source: PR103, claude/codex-handoff-1020 21ac755, night before evening. Codex owns backend/screens/tests. Existing approved-copy delivery is preserved on codex/approved-client-flow. No deployment, provider activation, charge/send/import or HoneyBook write.

## 1. Google Maps
Read-only crm-test checks blocked from this environment: public JS HTTP403 in sandbox and approved outside retry; browser GET /admin/reserva-rapida ERR_TUNNEL_CONNECTION_FAILED. No authenticated console/session available; actual staging success remains unverified. No key read/printed or provider query made.

Synthetic Chromium reproduces wrapper clipping: old7a4cd10 placement is not hit-test visible; Claude21ac755 body placement is visible. Preserve his fix/CSS and stale-ZIP behavior. Additional regression found late suggestions reopening after Escape; generation cancellation also handles blur, stale selection and manual fallback. Browser checks cover desktop overlay hit visibility, mobile tap, keyboard, missing ZIP clears old fee input without calculating, late Escape/blur, and provider failure. Only synthetic intercepted provider requests; no real client/event save.

Run with Playwright available: `WAOO_BROWSER_EXECUTABLE=/usr/bin/chromium node --test tests/Browser/places-autocomplete.cjs` (or omit executable for bundled Chromium). This is local evidence, not a deployed/provider check. Need owner-approved deployment later before checking this new source on crm-test.

## 2. Characters — executable form contract before code
No change to Records/QuickBook HTTP services[] contract: each selection remains `{character_id,quantity,outfit,note}` grouped by price_version_id. Form renders Cuántos first and one explicit character/optional wardrobe per slot. Each new UI row has quantity1; expanded editing preserves existing quantities/wardrobes/notes as individual slots. Shared helper normalizes both Reserva rápida and event form. Before the unchanged Records API, identical character+wardrobe+note slots coalesce into one quantity row (required by the existing recipe uniqueness rule); different wardrobes remain separate. Conflicting private notes are never silently dropped. Blank unchosen slots are omitted so an incomplete choice can remain a draft without a fake reservation. Do not silently switch selected characters, rename existing events or reprice packages.

When exactly one selected service takes characters, fill its price_version_id and hide Para (including the single-character service plus equipment-only extras case). With multiple eligible services, ask Para per row. Remove Mostrar from these forms (name search and category/show groups remain); no audience restrictions lost from the catalog. Quantity edits resize explicit slots, preserve retained choices, clear removed rows; existing API clients with quantity>1 remain supported. Incomplete recipe stays a draft, never a fake availability or paid hold.

Suggested event titles use package + character names + date + client, without outfit/version; internal selections/staff snapshots keep the exact outfit including0. Existing manual/stored/signed titles are not mass-rewritten.

## 3. Time
Use shared non-native Filament time picker, seconds off, 15-minute control increment; retain explicit Houston offset/DST validation and existing agreed minute values. No new opening-hours, booking-limit or pricing policy.

Items4–10 (one-field client selection, editable auto50% deposit, optional Zelle duplicate guard, server-derived receipt time, short link, portal choice buttons, full Stripe flow) remain next; their precise API changes are published before backend changes. Evening immediate-signature contract remains docs/APPROVED-CLIENT-FLOW-API.md B1, not yet implemented.

## 4. Single customer lookup and provisional identity — freeze before code

Owner form replaces the mode radio with one `client_lookup` searchable picker. Matches show name, phone, email, event count via possibleMatches; creation is an explicit option in that same picker, not an automatic merge. Internal customer_mode/customer_id state stays for the existing QuickBook service. Selection still pins id/version/contact in Locked server state, and registered contact updates remain inside QuickBook's audited transaction (no overwrite on stale identity). No new unauthenticated search route.

`App\Services\Booking\SaveQuickBookingCustomer::execute(User $actor,array $input,?int $customerId=null):Customer` is a narrow owner/finance-sandbox service for QuickBook (future Stripe reuses it). Input name nullable/blank, phone/email/locale, expected_version required on updates. Phone OR valid email required on creation and when remaining provisional; existing named contact-update semantics stay unchanged. A blank name is stored as null, never a fabricated person or display placeholder. Name present uses the existing audited SaveCustomer path. Only an already provisional (null-name) customer may stay nameless on update; a named record cannot have its name silently cleared. All contact writes versioned and transactional. No change to global SaveCustomer's name-required contract.

QuickBook `new_customer.name` becomes nullable; new_customer requires phone or email. `customer_update.name` may be null only for an already provisional customer, with the same Locked expected_version. Named-only customers elsewhere remain supported by their existing APIs. Migration makes customers.name nullable, no data backfill; down refuses to tighten the schema while real null-name records remain, rather than inventing names.

Displayed labels may say Nombre pendiente (owner UI only). Actual portal submission still collects first/last name; owner's explicit approval applies it through SaveCustomer. Existing consent/source/contract/payment rules remain; no claim that entering a phone verifies it, sends a message, creates a payment or reserves staff. Mail requiring first_name remains fail-closed until an actual name is available.

UI tests: one lookup/no mode radio, name/accent/phone/email matches with complete labels/count, explicit create prefill, pick captures immutable versions, phone-only/email-only creation with null name, neither contact rejected, named clear rejected, provisional contact update/version conflict, failed booking/audit rolls back customer, and independent backend lookup/portal regressions. No real client data in fixtures.
