# AUTH-READ-LIMIT PR

Title: Keep admin API reads from exhausting the save allowance

Head: `codex/admin-api-budget-fix` · base: `main` (8105bef). This small branch does not depend on any pending DOC/PAY/QuickBook/Zelle/cost branch. Contract first041e185; source9c90b5f. Equivalent integrated source is29efeec on codex/admin-read-write-budgets.

Reading customer/event data or PDFs no longer consumes the owner's write allowance. AdminApiRateLimit delegates GET/HEAD to Laravel's named admin-read and mutations to existing admin-write, both60/min per owner. Owners sharing an IP retain independent counters. Authorization/confirmed2FA remains before the limiter; CSRF/login/challenge/portal/provider behavior and all route/payload contracts remain unchanged.429/Retry-After and no-store/private behavior are preserved. No migrations or UI wiring required.

Verified independently on main8105bef:17 auth/calendar/budget tests /367 assertions pass, including an actual audited save after read exhaustion, mutation throttling/recovery, same-IP owner isolation, HEAD classification, rejected-owner ordering and initial calendar HTML rendering. Current calendar is Eloquent/Livewire and consumes no admin API requests in initial server rendering. Higher read sizing/browser profiling is deferred until Claude supplies actual request measurements; no guessed throughput increase or Livewire-rate-limit claim.

Claude: review/merge this small main PR independently of the other phase stacks. Full broader integration proof is in HANDOFF on codex/admin-read-write-budgets. No deployment, account creation, real provider request/charge or client message. Prepared PR because unchanged GitHub GraphQL access previously returned Forbidden; no PR creation is claimed.

Broader integration6f307cd is now verified: **247 tests /2413 assertions pass on PHP8.3 / MariaDB11.8**, using the existing CLI-only512M test helper. Vite build, PHP lint, strict Composer and diff checks pass. No source changes after that run. The small main PR remains head7a07c3c; it passed17/367 independently and needs no prior phase-stack merge.
