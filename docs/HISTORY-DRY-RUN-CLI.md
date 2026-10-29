# Existing history review CLI · 2026-10-28

`php artisan waoo:history:import --owner=<owner-id> --batch=<existing-batch-id> --dry-run`

This completes the requested missing aggregate-only CLI wrapper; it does not introduce another importer. Existing records and source artifacts remain authoritative. No source filename, path or new file is accepted, and omitting `--dry-run` is an error. There is deliberately no write mode. Do not re-import history already copied on crm-test.

The command reuses `ReadHistoricalImport` (the current matching/bulk plan) and `VerifyHistoricalImport` (preserved source vs staging/destination), under one existing import lock/transaction. No staging, commit, decision, customer, ledger, audit, receipt or domain write, even temporarily followed by rollback. It retains sandbox-history, owner/2FA and transport gates. CLI bootstrap on staging must use the existing secure APP_URL/session configuration; no request-forging or gate bypass is added.

Output is one closed aggregate JSON object: source-row, eligible, review-required, imported, excluded, pending and difference counts; fixed verdict, dry-run/domain-write markers and independent-human-review=false. No project/customer identifiers, names, emails, amounts, hashes, raw fields, error chain or original PDFs. Generic failure only. Exit0 means copied fields match with no review rows,2 means differences/pending/review,1 means failure or unauthorized/unconfigured invocation. Exit0 is not human acceptance or permission to transfer responsibility.

This is a report, not the actual HoneyBook cutoff, provider activation, email authorization or real import. No production flags or host changes are part of this delivery.
