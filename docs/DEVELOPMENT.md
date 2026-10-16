# Development — Phase 1

Read AGENTS.md and HANDOFF.md first. Laravel 13 / PHP 8.3 / MariaDB 11.8 InnoDB, Fortify session authentication and Filament 5 (resolved versions in composer.lock). Build assets off-host; Node 24 used here. `public/` is the only web root. Keep .env, vendor, storage and repository contents outside any public directory. No `storage:link` or public document disk is needed.

## Standard local setup

PHP extensions: PDO MySQL, mbstring, intl, bcmath, DOM/XML, curl, sodium, zip; pcntl for the concurrency test and bounded queue worker. Composer 2, Node >=22.12, MariaDB 11.8. Never use the production database for tests.

```bash
composer install --no-interaction --prefer-dist
cp .env.example .env # only when absent; preserve an existing .env
php artisan key:generate # new environment only; never rotate an existing key casually
# Set private DB credentials in .env; APP_URL must match the development origin.
# SESSION_SECURE_COOKIE=false only for local HTTP; true for HTTPS staging/production.
php artisan migrate
npm ci --ignore-scripts
npm run build
php artisan serve --host=127.0.0.1 --port=8081
```

No default accounts or prices are seeded. Optional `php artisan db:seed --class=DemoSeeder` is limited to APP_ENV=local/testing, creates clearly fictitious draft inventory and can be repeated. It does not create prices, bookings, payments or accounts.

## AUTH-01 owner provisioning

The owner confirmed two equivalent administrators: César `cesar@waooparty.com`, Maybeli `maybe@waooparty.com`. On the approved test site, an operator runs:

```bash
php artisan waoo:admin cesar@waooparty.com --name="César"
php artisan waoo:admin maybe@waooparty.com --name="Maybeli"
```

Each command asks privately for a unique strong password; do not put it in arguments, Git, logs or chat. Both accounts must enroll/confirm 2FA on first use. No accounts were created on Hostinger as part of this delivery. CLI creates no email or messages. Account setup is separate from deploying the application and integrating Claude's login/enrollment screens.

## Checks

Create a dedicated MariaDB database `waoo_phase1_test`, grant the development user access, then run:

```bash
composer validate --strict
composer audit --locked
find app bootstrap config database routes tests -name '*.php' -print0 | xargs -0 -n1 php -l
php artisan test
npm ci --ignore-scripts
npm run build
```

phpunit.xml forces the test database name to prevent accidental use of the normal app DB. The suite migrates and rolls back its test database and uses fake fixtures only. Never run two suites concurrently against that same database. The operational increment adds a resource concurrency test with two actual PHP processes on MariaDB and requires pcntl. CI starts a disposable MariaDB service. This is backend coverage; desktop/mobile UI acceptance remains Claude + owner work.

## Hostinger constraints carried forward

The probes established feasibility, not a deployment of this application. Use DB_HOST=localhost on Hostinger per grants. Storage local disk is private and its automatic signed serving is disabled: later document routes must enforce event authorization as well as signed-link expiry. No outbound provider configured. Queue uses database, after_commit=true and retry_after=120; no tasks are scheduled yet.

When actual jobs are introduced, configure hPanel cron every minute with scheduler locking and a bounded queue invocation such as `queue:work database --stop-when-empty --max-time=50 --timeout=40 --tries=3`. Every job timeout must remain below retry_after, and jobs need idempotency. `--max-time` alone is not a hard timeout. Do not enable production cron, cleanup probe artifacts or deploy without the owner's separate approval.

## Cloud workspace used for this delivery

Isolated containers: `waoo-phase1-runtime` (PHP 8.3.35), `waoo-phase1-db` (MariaDB 11.8), network `waoo-phase1`, no DB port exposed. Repository mounted at /app. Local credentials and caches live outside Git under /workspace/setup/waoo-phase1. Run checks with `docker exec waoo-phase1-runtime ...`; Node build runs from the checkout. Source installs use the proxy and existing trust bundle without disabling TLS, because GitHub ZIP downloads were denied here. Application setup instructions in the saved cloud draft recreate/start these services; processes must restart after snapshots. Existing phase0 website audit is independent and remains read-only.
