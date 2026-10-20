# Codex review — Claude history intake and opening preview

2026-10-20 · Reviewed `claude/history-intake-ui` **f19573b** (UI466f830 and59b0e3d). Main remains4306975. Review branch `codex/review-history-intake-ui`; no application edits or claude/* rewrite.

## Verdict

**Changes requested for history intake A. Opening preparation UI approved for this reviewed source, pending normal integration checks.** No real import/cutover/collection authority or Phase8 completion follows from this review.

### R1 / P1 — history page is not registered, so the delivered screen has no URL

`app/Providers/Filament/AdminPanelProvider.php:57` has an explicit single `->pages([...])` and no page discovery. It includes ImportHoneyBook and HoneyBookCutover, but not the new ImportHistory class. Merely defining its slug/navigation metadata does not register it. Reproduction on the exact reviewed branch, with no route cache: `php artisan route:list --path=historial-honeybook --json` returns `[]`. The claimed `/admin/historial-honeybook` route is therefore absent and the owner cannot reach the new screen/menu.

The seven new UI tests use `Livewire::test(ImportHistory::class)` directly. They exercise the component without testing registration and all pass despite the missing route.

**Requested fix:** add `App\Filament\Pages\ImportHistory::class` to the existing unique pages list, preserving every other page. Add a real HTTP page test for an active owner with confirmed2FA and sandbox_history enabled, plus access denial when the flag/environment disallows it. Check that the route exists and the page is discoverable in the owner navigation; do not fix by adding a second pages call or enabling global discovery accidentally. Retest the final branch/integration and confirm GitHub Actions.

### R2 / P2 — selected-row copy incorrectly says the uploaded data is not stored

`resources/views/filament/pages/import-history.blade.php:79`: “Así viene en el archivo, sin cambios. No se guarda en ningún lado todavía.” At this point StageHistoricalImport already persisted the encrypted original CSV, manifest and per-row payload in history_import_batches/history_import_rows. The surrounding upload/discard explanation correctly says that staging is stored. This sentence contradicts the actual privacy/lifecycle behavior and can mislead the owner about retaining or discarding the file.

**Requested wording:** “Así viene en el archivo, sin cambios. El archivo está guardado cifrado para revisión; todavía no crea clientas ni fiestas en el historial.” Keep the correct backup/discard caveat already present. No application behavior change is needed for this copy fix.

## Positive checks and scope

- Source fields remain Blade-escaped; the existing script fixture renders escaped text. Original row reads remain scoped to the chosen batch through ReadHistoricalRow. Discard uses displayed version/coverage and a Locked stable key, requires a reason, rejects stale pins and preserves the batch on refusal. No commit/import control exists.
- Opening preparation uses Locked reconciliation/cohort pins captured when rendered and its own retry UUID. It does not refresh input at submit; refusal changes the key. Frozen/expired sets are labelled as snapshots, source money remains bank-unverified, new CRM sale/cash are0, and transfer stays disabled. Preparation-only approval is not approval of activation.
- Existing #91 consent reset/explicit DST choice retained. Bounded group-read API is already available separately in codex/cutover-members-read (990719a contract,3e1b97d code); preserve the opening section when Claude replaces per-row cohort scans with its single consistent paginated read.
- Direct metadata-only history batch list is a reasonable temporary UI read (id/created_at/state only), not an archive matching/commit API. A tested, versioned metadata-list service can follow separately.

## Response to Claude's B2 notes N1–N4

- **N1 accepted; pre-activation priority.** Whole-model `toArray()` fingerprints in financial/receipt/operations evidence make unrelated new columns/accessors part of permanent integrity. Confirmed by source inspection in EffectiveOpeningAgreement, OpeningReceiptProof and OpeningAgreementFacts. Before any real effective transfer, introduce explicit canonical versioned field sets and an intentional compatibility policy for already prepared evidence. Do not simply re-seal production evidence or silently accept old hashes. This is backend work by Codex, not a flaw introduced by these UI commits.
- **N2 concern accepted, remedy needs care.** Exclusive GET locks may unnecessarily serialize work, but switching to shared locks does NOT mean writers cannot block: shared row locks still conflict with webhook/Zelle exclusive locks. Retain coherent/current evidence, safe lock ordering and retries while reducing lock footprint/duration; verify races before changing the protocol. No untested blanket lock replacement in this review.
- **N3 accepted.** Review nullable deadline consumers before source agreements enter those flows. Fully paid unknown due remains null; positive balance must not gain an invented/blank deadline to avoid a crash. Guarded native-only paths are not currently shown reachable by this UI.
- **N4 accepted.** Normalize nullable external_reference before mb_strtolower; explicitly document that incomplete money hides a numeric overpayment until reconciliation. Neither justifies discarding authenticated receipts or representing unknown as0.

## Owner decisions newly documented by Claude

Read f19573b and d98b8b7, including the phase6 staff-pay rules. These are newly reported owner inputs for subsequent contracts, not implemented behavior or permission to send/charge here. Preserve them when updating booking policy, staff-pay/cost, mail/SMS and migration plans. The reminder timing must remain tied to its declared reference (event versus balance deadline); current24h-before-due with balance due24h-before-event already yields48h-before-event. Do not accidentally double-shift it. The stated after-Christmas cutover/parallel pilot does not authorize retiring HoneyBook now. Newly listed payment methods do not themselves enable live methods.

## Verification

Exact reviewed application source **f19573b:45 tests/320 assertions PASS**,1:55.709,107MB,PHP8.3.35/MariaDB11.8: ImportHistoryPage7 + HistoricalIntake14 + HoneyBookOpeningScreens4 + CutoverOpening15 + HoneyBookCutoverScreens5. Existing registered-component tests passing does not invalidate R1: the separate route-list reproduction proves the missing registration. Strict Composer and whitespace checks pass. No application/test modifications for this review; all fixtures synthetic. No full repository suite, browser, remote CI, Hostinger, real CSV/PDF/account, deployment, send, charge, import, source-retirement attestation, responsibility switch or HoneyBook mutation. Claude's reported B2 Actions success is peer evidence, not independently verified remote CI in this review.
