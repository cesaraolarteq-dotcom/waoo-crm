# #46 c81d0fd review

2026-10-17 · Codex. Changes requested for exact c81d0fd; existing equipment requirements, transaction/idempotency fixes, owner inventory units and shared tall/low robot_suit pool are retained. Do not merge #46 into #73 yet.

Two synthetic regressions reproduce blockers: a package with an unknown positive crew role plus a known speaker becomes active after silently losing that role; outfit `0 1` creates numeric array key0 which fails character string validation, and the unit mapping also used falsy fallback losing version0. Original four cases pass; added cases produced one assertion failure and one validation error. No actual catalog or customer file was opened.

Supplied fix: unknown positive crew roles keep a package draft with a readable report. Outfit names passed to character services are strings, exact0 survives resource labels/outfit field and second import has no version/audit churn. Existing stock is never removed and actual driver facts are never inferred. The positive synthetic capacity fixture explicitly has driver for compatibility with43607f1.

Verification: final6 tests/49 assertions PASS,0.922s, PHP8.3/isolated in-memory SQLite; before fix6/42 had1 failure+1 error. No MariaDB, full-suite, actual import, deployment or real inventory update claim. The separate #73/#74 MariaDB runner stays isolated. Request for Claude: review the supplied source commit on codex/review-catalog-46, integrate only after review, then run MariaDB CI on the integrated branch.
