# Record forms + real services integration

2026-10-16 · Codex · branch `codex/integrate-record-forms`.

Combined Claude's exact `claude/admin-screens` revision `7dc33c0` with the shared services and the four contract notes from main PR #29. This is a review branch, not a main merge or deployment. It includes shell `7d7b818` unchanged; the remaining security finding below must be handled before shell approval.

## Accepted notes

1. Customers/characters remain last write wins in this extraction. Follow-up [RECORD-VERSION](../tickets/RECORD-VERSION.md) defines additive versions, displayed-version UI handling and API/service concurrency tests. No migration or new version requirement was silently added.
2. Filament panel explicitly uses `databaseTransactions(false)`. Existing form schemas use options/repeaters, not model relationship writers; page handlers delegate writes. Real persistence/audit tests supplement the recording fakes.
3. `RecordWriteRejected` now exposes `getStatusCode(): int`, preserving its readonly `status` property and constructor `__construct(int $status, string $message)`. Filament can offer reload/reconciliation on 409. Current notification behavior is unchanged; no reload button is claimed implemented.
4. The later event draft form will serialize an explicit America/Chicago offset, asking CDT/CST for the repeated hour. The backend contract already requires explicit offsets. No event draft/block form or automatic DST guess was added here.

## Integration adjustments and evidence

The first combined suite exposed three test assumptions, not failed production saves: two tests assumed classes were absent, and one used the temporary exception's string-only constructor. The integration branch now uses the documented force-off config to verify read-only mode even with services present, removes the temporary eval exception, and supplies the actual 409 constructor in the fake rejection test. Claude's application/UI files are unchanged.

Added six Livewire tests using the actual services and MariaDB, with no recording fakes. They verify automatic form activation/force-off, real client/character/costume saves, one audit per successful operation, actor attribution, stale resource edit rejection and a successful reloaded edit, append-only exact-cent prices and failed catalog activation preserving the form/database. Fourteen focused adapter/integration tests pass (144 assertions). Full combined suite: **90 tests, 689 assertions pass on PHP 8.3.35 / MariaDB 11.8**, including the real reservation race. Vite build, strict Composer validation and diff checks pass. These are HTTP/Livewire checks, not a new visual browser review.

## Exact shell revision and verdict

`git diff 7d7b818 -- app/Services/RecoveryCodeDelivery.php app/Http/Middleware/SensitiveAuthResponses.php app/Http/Controllers/Auth/TwoFactorEnrollmentController.php app/Providers/FortifyServiceProvider.php config/fortify.php` is empty on this integration branch. R1 and the original sequential R2 cases pass. PR #29 replied to the earlier interface entry `938c2bb`; the subsequent review `f83750e` already reviewed the corrected shell and reported a narrower R2 regeneration interleaving, not the original stale-code findings.

Re-ran the [opt-in generation probe](probes/RecoveryGenerationRaceProbeTest.php) against that unchanged implementation: the security expectation still fails (one test, three assertions). Claim's committed UPDATE can consume the old set, regeneration/reset can intervene, then fresh() returns the new set while its marker remains available for a second delivery. This requires authenticated recovery operations; no cross-account/anonymous disclosure is claimed. It is a deterministic interleaving, not two real parallel requests.

**Verdict:** forms work with the real shared services after the test-only integration adjustments. Shell R1 is closed and original sequential R2 cases are closed; R2 generation concurrency remains changes requested. See [ADMIN-SHELL-SECURITY.md](ADMIN-SHELL-SECURITY.md). No GitHub approval or main merge is implied by passing default CI.
