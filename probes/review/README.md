# Opt-in diagnostics for Claude PRs #42 / #43

These reproduce the findings in `docs/reviews/CLAUDE-42-43.md`. They are deliberately outside the normal PHPUnit suite. On reviewed head `a16166d`, all four checks below **fail** required-behavior assertions / exit nonzero; the ordinary suite still passes (235 tests). They are review evidence, not passing tests or a CI configuration change.

Use only an isolated development/test database: inherited tests use DatabaseMigrations and PHPUnit forces `waoo_phase1_test`. Never point this at production. Refresh Composer's optimized autoload after switching branches. Run PHP commands sequentially, with no other test suite using that database. All fixtures are fictional; no real payment/provider request occurs.

From repository root, with PHP 8.3+, installed dependencies and configured isolated MariaDB:

```sh
composer dump-autoload --no-interaction
php -d memory_limit=512M vendor/bin/phpunit --filter=test_review_ probes/review/Pr42ReviewProbeTest.php
php -d memory_limit=512M vendor/bin/phpunit --filter=test_client_notice_is_pending_until_the_owner_verifies_the_real_amount probes/review/Pr43ReviewProbeTest.php
node probes/review/pr42-dst-review.cjs
```

In this cloud workspace PHP runs in `waoo-phase1-runtime`, repository mounted at `/app`. Prefix each PHP command with `docker exec waoo-phase1-runtime` (run only one at a time). The full PDF suite additionally needs the existing CLI-only `PHP_INI_SCAN_DIR` memory override for spawned child processes; see `/workspace/setup/waoo-phase1/test.sh` locally.

Expected diagnostics before fixes:

- R1: one review saved for submission 2, with venue TWO, although the owner opened approval on venue ONE. Required assertion expects zero reviews.
- C1: configured approved SMS wording is missing from the rendered checkbox.
- R3: at 00:01 Houston, the existing today/00:05 receipt fixture fails the success notification. The probe restores both Carbon clocks afterward.
- R2: actual inline JavaScript expression sends 03:30 with `-05:00` on 2026-11-01; Houston displays 02:30. The probe extracts the current expression; revise the diagnostic when the encoding implementation changes and add proper regression coverage for the replacement.
