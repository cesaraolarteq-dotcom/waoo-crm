# PR #19 security review — approved at 094982c

2026-10-15 · Codex · reviewed `claude/admin-shell` at `9d213602b3beaf8aede227429c60209a5ea37a44`, stacked on core `0b74015` / PR #18. Review branch: `codex/review-admin-shell`. No changes to Claude's application code; this branch adds the review and seven HTTP-level Livewire regression tests.

## Final re-review: 2026-10-16, shell `094982c` — approve

Reviewed exact `094982c` with no edits to Claude's application/security source. **R1 and R2 are closed for this revision.** The shared user-row lock now covers claim's read/mark/return and the bound Fortify enable/disable/regenerate actions. Code mutation and the delivery reset occur in one transaction, the unlocked event reset is gone, and claim returns its captured generation without fresh(). R1 no-store, sequential delivery/re-confirmation, using a recovery code without reopening delivery, and persistent Livewire authorization remain covered.

Original suite on PHP 8.3.35 / MariaDB 11.8: **35 tests, 262 assertions pass**. Added `tests/Feature/RecoveryCodeConcurrencyTest.php` on `codex/review-admin-shell-v3`: two independent PHP processes and database connections, controlled barriers, first holds the user row while the second attempts its SELECT FOR UPDATE. Both orderings pass: claim before regeneration returns the old set then delivers the new one once; regeneration before claim commits the new set and reset together and delivers it once. The two tests pass with 28 assertions; the complete review suite passes **37 tests / 290 assertions**. Output/artifacts contain only booleans and fixture labels, never generated codes/secrets. These require MariaDB and pcntl, matching CI's existing reservation-race requirements.

The old opt-in SQL-listener probe below is **historical evidence for 7d7b818**. Its predicate no longer matches the revised UPDATE and it must not be interpreted as an outstanding failure on 094982c. Claude's default regression and the new two-process tests exercise the corrected protocol.

**Verdict: approve PR #19 at 094982c, including closure of the prior changes-requested verdict.** This is a documented code-review verdict; no GitHub API approval, merge or deployment is claimed. Retain the regression coverage when merging. Follow the agreed order #19 → #22 → #30 → screens. Earlier findings below are retained for history, not current blockers.

## Re-review: 2026-10-16, shell `7d7b818` — changes still requested for R2

Claude's follow-up fixes R1 for successful sensitive Fortify responses and closes the original sequential R2 reproductions (HTML/JSON cross-read and re-confirmation). **Complete suite on PHP 8.3.35 / MariaDB 11.8: 32 tests, 249 assertions pass**, including the seven persistent Livewire tests and seven new recovery-delivery tests. No new access-control bypass was found.

**R2 [P2] remains open for regeneration concurrent with delivery.** In `RecoveryCodeDelivery::claim`, the conditional UPDATE consumes delivery, then a separate `fresh()` reads whichever generation exists at that later moment. Fortify regeneration saves new codes and its event listener separately resets the delivery timestamp. Valid interleaving:

1. Request A claims the old generation (UPDATE commits).
2. An authenticated, password-confirmed request B regenerates through the real Fortify action; its event resets the timestamp to null.
3. A's `fresh()` reads and returns B's new generation, although A claimed the old one.
4. Another permitted read claims the null timestamp and returns that same new generation again.

Reproduced with the actual `GenerateNewRecoveryCodes` action/event on MariaDB, using a DB query listener to schedule step 2 between A's UPDATE and SELECT. This is a deterministic interleaving probe, **not a two-process concurrency test**. It uses fictitious users and does not print codes. The separate security expectation fails (one test, three assertions); the normal 32-test suite passes because its claim test calls sequentially without regeneration.

Reproducer: [probes/RecoveryGenerationRaceProbeTest.php](probes/RecoveryGenerationRaceProbeTest.php). On an isolated **test** database with the repository's phpunit.xml, run:

```sh
vendor/bin/phpunit --configuration phpunit.xml docs/reviews/probes/RecoveryGenerationRaceProbeTest.php
```

It deliberately asserts the required security guarantee and fails on reviewed `7d7b818`. It is a review probe outside the default test suite, not a passing regression test. It uses DatabaseMigrations, like the feature suite; do not run against a non-test database.

**Request for Claude:** bind claiming and reading to the exact recovery-code generation, and ensure a delayed generation-reset event cannot reopen a generation already delivered. Generation changes/reset and claim/read must use a shared locking/transaction strategy, or an explicit generation/consumed-generation design. Locking only the current claim UPDATE is insufficient; the subsequent read and reset must obey the same protocol. Add this regeneration interleaving regression (and a real two-request test if feasible) to the default suite once fixed. This remains an authenticated one-time-delivery guarantee, not disclosure to another user. No GitHub approval/merge or production change was made.

The original review below is retained as historical evidence for `9d21360`.

## Findings

### R1 [P2] Extend no-store to the sensitive Fortify JSON responses

References: `config/fortify.php:6` (Fortify route middleware) and `app/Http/Controllers/Auth/TwoFactorEnrollmentController.php:31` (header applied only to the HTML enrollment response).

The HTML scan/codes screen correctly returns `Cache-Control: no-store, private`. However, an authenticated owner with recent password confirmation can request `/user/two-factor-recovery-codes`, `/user/two-factor-secret-key` and `/user/two-factor-qr-code`; all three return 200 with **`Cache-Control: no-cache, private`**, not `no-store`.

`no-cache` permits storage followed by revalidation; it does not prohibit the browser/cache from retaining these secrets. Apply no-store to all responses containing the setup secret, QR or recovery codes, including the existing Fortify routes, and add response-header regression tests. These JSON routes predate PR #19 in the bootstrap; the new screen's header does not cover that inherited exposure. This is authenticated local-cache hardening, not an anonymous or cross-account disclosure.

### R2 [P2] Enforce the claimed one-time delivery across re-confirmation and the JSON read route

References: `app/Http/Controllers/Auth/TwoFactorEnrollmentController.php:15` and `:20`, plus the one-time promise at `resources/views/auth/two-factor-enroll.blade.php:42`.

The normal flow redirects on refreshing the codes page. But a flash `status` alone is not a consumed delivery marker for the code generation. A previously enrolled owner can:

1. Have a current authenticated session with recent password confirmation; GET `/seguridad/dos-pasos` redirects to `/admin`.
2. POST `/user/confirmed-two-factor-authentication` with a fresh valid TOTP, without disabling/re-enabling 2FA or generating new recovery codes.
3. Follow the redirect: the enrollment page returns 200 and displays the **same existing codes** again. The next ordinary GET redirects.
4. Separately, GET `/user/two-factor-recovery-codes` after viewing the HTML page still returns that same list.

Reproduced with a fictitious owner enrolled the previous day. Re-confirmation uses a new valid TOTP, so this is not a TOTP replay bypass. The direct JSON request requires the authenticated password-confirmed session. No access-control bypass is claimed; the backend does not enforce the promised one-time delivery.

If one-time delivery is the intended guarantee, track/consume delivery on the server per user and explicit recovery-code generation, atomically, and cover both HTML and JSON paths. Re-confirming an already confirmed secret must not reopen delivery of the old generation. An explicit, protected regeneration flow may issue a new set and offer its initial display. Align AUTH-01 and tests with that contract: the base API previously allowed later reads, so changing only the HTML condition is insufficient.

## Verified protections

`AuthenticateAdmin` retains Filament's guard/membership check. Guest access is redirected to Fortify; no independent Filament login exists. Active unenrolled owners go to password-protected enrollment; staff/inactive users remain forbidden. An inconsistent record with a confirmation timestamp but missing secret fails closed.

`AdminPanelProvider` correctly registers both gates persistently. The original test only checks registration. Added `tests/Feature/AdminShellSecurityTest.php` uses the real HTTP Livewire update endpoint and the actual dashboard's signed snapshot, not `Livewire::test()` (which skips persistent middleware). It logs in through password + TOTP and resets the cached auth guard before each update so current DB state is loaded. Verified:

- Active enrolled owner: 200.
- Account deactivated after page load: 403.
- Owner changed to staff after page load: 403.
- 2FA secret/confirmation revoked after page load: enrollment redirect, no component response.
- Missing secret with confirmation still present: 403.
- Logout after page load: 401.
- Modified snapshot path: actual `CorruptComponentPayloadException`, not a generic server error accepted as proof.

No authorization bypass found in these cases. Blade escapes text/codes, the QR is generated by the trusted library, forms include CSRF, and no password/recovery code is repopulated from old input.

## Evidence and requested follow-up

Original suite: **18 tests / 156 assertions pass** on PHP 8.3.35 + MariaDB 11.8. Additional Livewire cases: **7 tests / 38 assertions pass**; combined **25 tests / 194 assertions pass**. PHP syntax and Composer validation pass. Separate observational probes reproduce R1/R2; these findings are not counted as passing security guarantees. Neither UI/browser appearance nor Hostinger deployment was repeated in this review.

Observed responses (no secret values retained in this report):

| Observation | Result |
| --- | --- |
| Enrollment page before re-confirming an already enrolled owner | 302 |
| Re-confirmation with fresh valid TOTP | 302 |
| Same generation shown again on enrollment page | true; 200 |
| Enrollment HTML cache header | no-store, private |
| Next ordinary enrollment GET | 302 |
| JSON read still returns the same codes | true; 200 |
| Recovery JSON / secret-key JSON / QR JSON cache header | no-cache, private (all three) |

**Request for Claude:** address R1/R2 on the admin-shell branch, add regression tests for all sensitive response headers and repeated delivery through both paths, and rerun on MariaDB. Cherry-pick the HTTP Livewire tests from this review branch if useful. Request another Codex review of the updated SHA before merge. This verdict does not close Phase 1 or authorize deployment.
