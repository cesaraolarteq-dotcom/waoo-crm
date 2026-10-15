# Hostinger inventory (DISC-01-HOST evidence)

Date: 2026-10-15. Author: Claude Code. Method: **read-only** SSH session with a dedicated key that the owner added in hPanel (key name `claude-waoo-crm`), plus HTTPS checks. Nothing was created, changed or deleted on the server. Host, port and username are kept out of this repo on purpose.

| Item | Observed | Meaning for ARCH-01 |
| --- | --- | --- |
| Plan | Business Web Hosting (owner, hPanel) | Shared hosting, not a VPS |
| PHP (CLI) | **8.3.35**; hPanel offers 8.2–8.5 (site set to 8.3) | Supported by current Laravel/Filament. Do not change the site version (it restarts the site) |
| PHP extensions | bcmath, ctype, curl, dom, fileinfo, gd, imagick, intl, mbstring, openssl, pcntl, PDO, pdo_mysql, mysqli, redis, memcached, sodium **not listed**, tokenizer, xml/xmlwriter, zip, OPcache | Laravel requirements met (sodium optional) |
| disable_functions | `system, exec, shell_exec, passthru, popen, symlink, link, dl, ini_alter, chgrp, virtual, mb_send_mail, …` (`proc_open` not disabled) | No PDF engine that needs exec (no wkhtmltopdf/Browsershot): use a pure-PHP PDF lib (dompdf). PHP `symlink()` is blocked, so `artisan storage:link` fails; serve private files through controllers (needed anyway for authorization) |
| Composer | 2.9.8 | Dependencies can be installed on the server |
| Git | 2.43.7 | Pull-based deploy is possible |
| Node.js | **not available** | Front-end assets must be built before deploy (or use prebuilt Filament assets) |
| Database | MariaDB 11.8 client present. Server version and the new DB `waoo_crm_test` are **not verified** (no credentials used) | Verify transactions/row locks in DISC-02 with test credentials set by the owner on the server |
| Cron | `crontab` command not available | Cron is configured in hPanel (Advanced → Cron Jobs). Plan: `schedule:run` every minute + `queue:work --stop-when-empty` (no permanent worker assumed) |
| Background processes | `nohup` present; persistence not guaranteed on shared hosting | Do not rely on a permanent worker; DISC-02 measures cron-driven job latency |
| Outbound HTTPS | api.stripe.com, api.resend.com, api.boldsign.com reachable | Integrations can be called from the server |
| Disk | ~371 MB used in the account | Plenty of room for docs/PDFs; backups must still go off-host |
| Subdomain | `crm-test.waooparty.com` created by the owner; **valid HTTPS (200)**; docroot = `public_html/crm-test/` (inside the main site's web root, holds only Hostinger's `default.php`) | Put the app **outside** the web root (for example `~/domains/waooparty.com/crm-test-app/`) and serve only its `public/` front controller from `public_html/crm-test/`. Never put `.env`, storage or vendor inside `public_html` |
| Writable outside the web root | Yes (`~/domains/waooparty.com/`) | Private storage and `.env` can live outside public access |

## Not verified yet (needs owner authorization for writes)
DB connection/transactions, cron execution, Stripe test webhook, job retry, private PDF, backup restore: these are **DISC-02**, which writes test files only under `crm-test` and a private app folder, never touching the live site files.
