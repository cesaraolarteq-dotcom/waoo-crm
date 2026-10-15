# Phase 0 feasibility probes (DISC-02)

Throwaway code to prove the platform, **not CRM code**. It's deployed only to the private test app `~/domains/waooparty.com/crm-test-app` (outside the web root), served at https://crm-test.waooparty.com through `public_html/crm-test/index.php`. The owner authorized this on 2026-10-15. Delete it all when Phase 0 closes.

| Probe | How | Pass criteria |
| --- | --- | --- |
| P1 retry job | `php artisan probe:job` queues `ProbeFlakyJob` (fails on attempt 1, succeeds on attempt 2); hPanel cron runs `schedule:run`, which runs `queue:work --stop-when-empty` | `storage/logs/probe.log` shows attempt 1 failed, attempt 2 succeeded, exactly 1 success; latency measured |
| P2 private PDF | `php artisan probe:pdf` writes `storage/app/private/probe.pdf` (dompdf); `GET /probe/pdf` needs a temporary signed URL | Unsigned → 403; signed → 200 application/pdf; the file is not reachable by any public path |
| P3 webhook | `POST /stripe/webhook` checks the Stripe signature over the raw body (stripe-php) and stores the event id under a UNIQUE key | Bad signature → 400; valid → 200 stored once; replay → 200 not duplicated. Phase A uses a locally signed payload with a test secret; phase B uses a real Stripe **test-mode** event once the owner sets the test keys on the server |
| P4 DB locks | MySQL: two concurrent `probe:lock` processes `SELECT … FOR UPDATE` the same row | The second waits for the first; final counter = 2 (no lost update) |
| P5 backup/restore | `mysqldump` of the test DB → gzip + AES-256 (openssl) → copied off-host to the owner's Mac → restored into a second test DB | Row counts and checksums match; time measured |

## Results (2026-10-15, Claude, owner-authorized)

| Probe | Result | Evidence |
| --- | --- | --- |
| Setup | Laravel **13.36.0** on PHP 8.3.35, app outside the web root, front controller `public_html/crm-test/index.php` (`usePublicPath`), `X-Robots-Tag: noindex`, robots Disallow; the app is reachable **only** on crm-test.waooparty.com (waooparty.com/crm-test → 404) | curl: subdomain 200, main site 200, /packages 200 |
| P2 private PDF | **PASS** | Signed URL → 200 application/pdf; unsigned → 403; tampered signature → 403; direct storage paths and `.env` → 403/404 |
| P3 webhook (phase A, locally signed with a throwaway test secret) | **PASS** | Valid → "stored" (200); same event replayed → "duplicate ignored" (200); bad signature → 400; altered body → 400; 1 row in `provider_events` |
| P1 retry job (manual worker runs) | **PASS** | Attempt 1 failed on purpose, attempt 2 succeeded after a 5 s backoff; jobs=0, failed=0 |
| P1 via hPanel cron (`* * * * *` schedule:run) | **PASS** | Dispatched 17:18:30 → attempt 1 (intentional fail) 17:19:05 (35 s) → attempt 2 SUCCESS 17:20:05 (next cron tick); jobs=0, failed=0. **Latency ≈ up to 1 min per attempt** with cron-driven workers: fine for emails, receipts and reminders. A webhook must persist the event inline (P3 already does) and never wait on the queue |
| P3 phase B (real Stripe test mode) | pending | Needs the owner's Stripe **test** keys set on the server |
| P4 DB row locks (MySQL) | pending | Needs MySQL credentials set by the owner on the server |
| P5 backup/restore | pending | Needs MySQL + a second test DB |

Notes for ARCH-01: Laravel 13's local disk exposes a signed `storage/{path}` route (`serve`); the CRM must keep private documents behind its own authorization (signed URL plus server-side permission), as P2 does. Probe bug found and fixed: `Cache::increment` on a missing key returns false with the database cache store, so the job now uses `$this->attempts()`.
