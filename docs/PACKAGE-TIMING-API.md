# Package setup/breakdown — owner walkthrough item 2

2026-10-20 · Codex · contract before code · based on claude/walkthrough-fixes d36f51f / #100. Independent of the pending financial-bridge stack; no source transfer or deployment.

## Records and writes

Add nullable integer `service_variants.setup_minutes` and `breakdown_minutes` (migration520000). Values are whole minutes0–720;0 means explicitly no time, null means not yet defined. Existing packages start null, never a guessed15/30. Existing event times, service duration, prices, financial/contract snapshots and reservations are not backfilled or changed.

`SaveVariant::execute(User,array,?int):ServiceVariant` and existing owner `/admin/api/variants` create/update/read keep their signatures. Optional `setup_minutes`/`breakdown_minutes`, each nullable integer0–720. Omitted fields preserve existing values; null clears to unknown;0 stays0. Existing `expected_version`/variant.version and audit path cover changes, stale writes409. There is no separate RecordVersion model in this repository.

Catalog `waoo:import-catalog`: source `setup_min`→`setup_minutes`, `breakdown_min`→`breakdown_minutes`. Validate before converting (reject fractions/negative/out-of-range), including dry-run. Numeric integer strings normalize to integers for repeat-import equality. Missing keys preserve existing values; explicit null/blank is unknown;0 is retained. Same export repeats without new variant version, audit or price; a timing-only change uses SaveVariant once and never creates another price.

## Defaults and UI contract

`App\Services\Records\PackageTimingDefaults::forPriceIds(array $priceIds):array` supplies read-only UI hints `{setup_minutes:?int,breakdown_minutes:?int}`. Resolve distinct package versions from the price IDs. For each field independently use the **maximum**, not the sum, only when every selected variant has a defined value; missing/invalid price or any unknown value yields null for that field. Empty selection yields both null. No availability promise or provider call. Owners may override each value0–720.

`SaveEventDraft` applies the same defaults **on creation only**, under the already locked selected variants, for missing/null event fields. An explicit event0 or other valid value wins. Existing draft edits preserve existing/default-null input semantics; selecting packages in an existing form may offer hints but cannot silently rewrite times. Catalog edits never update saved events. QuickBook uses SaveEventDraft, so an API caller omitting timing receives the same safe defaults without relying on the browser. If unknown, the field stays null and confirmation still requires owner input.

**Request for Claude:** add timing fields to the Catalog form/toServiceInput; prefill Reserva rápida and new event forms from the helper when selected package/extras change, preserving owner overrides. Unknown must stay visibly blank, not0. Do not modify event duration from these buffers. Use selected price IDs, including extras; clear auto values when selection empties. No UI changes implemented by Codex in this delivery.

Verification: version/audit/stale input, explicit0/null/omission, max over several packages/extras, unknown per field, owner override, existing-event preservation, importer repeat/dry/invalid/timing-only change and empty/populated migration roundtrip. Synthetic catalog only; no real import or staging change.
