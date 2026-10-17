# Bound resource-history loading without changing booking checks

Head: `codex/reservation-neighborhood`. Base: `codex/integrate-42-43-fixes` (702b5e4), then retarget main after the integration lands. This is the separate non-blocking RESV-A-PERF follow-up; no UI change or schema migration.

Confirming a resource previously loaded every historical reservation and its event into PHP while holding the resource lock. ConfirmBooking now checks busy-interval overlap with EXISTS and loads only the preceding/following events needed by the existing transfer checks. It still considers long events, midnight preparation and distant neighbors; no fixed date window or maximum duration is assumed. Existing sorted resource locks, versioned travel evidence, 45-minute margin, deposit/short-notice checks, matching, idempotency and auditing are retained. Blocks and trip checks are unchanged.

Interface/design commit a01a5ca before source **83b2709**. Internal helper: App\Services\Scheduling\ReservationNeighborhood::forResource. MariaDB and SQLite use their own equivalent busy-interval expressions; the original start-only reservation ordering and existing indexes are retained. At most three SQL statements / two Event models per resource (one existence statement / zero models on conflict). This trades one extra small query for bounded PHP loading; it does not promise constant database CPU or fewer SQL statements. The SQL optimizer can still examine historical rows.

## Verification

- Fresh full integration: **262 tests / 2362 assertions pass** on PHP8.3 / MariaDB11.8, including the existing real competing-confirmation race and all portal/payment/signature/record-version tests.
- Existing CoreTest first passed31/155. Focused new tests pass8/34: old-scan oracle, 45-day overlap, midnight setup, half-open boundaries, unrelated resource, distant neighbors both sides, stale/missing transfer review and idempotent confirmation; isolated SQLite checks cover equivalent date expressions.
- Strict Composer, changed PHP syntax and diff checks pass. No dependency/frontend change; the parent integration's Vite build remains valid. No source changed after the final full run. Remote CI for this new branch has not been observed; Composer advisory audit remains previously blocked, not claimed passed.

## Fictional benchmark from the final full run

| Metric | Previous scan | Bounded query |
| --- | ---: | ---: |
| Historical reservations | 1,201 | 1,201 |
| Event models hydrated | 1201 | 2 |
| Reservation models hydrated | 1201 | 0 |
| SQL statements | 2 | 3 |
| Observed loaded heap bytes | 5,066,248 | 11,072 |
| Observed elapsed milliseconds | 264.86 | 4.60 |

Single local fictional fixture; timings/heap are observations, not performance assertions or a production guarantee. Tests assert decisions, query/model bounds and unchanged history. EXPLAIN on the measured neighbor queries uses the existing resource/start/end index; removing an unnecessary ID tie-breaker removed filesort in the observed plan. Overlap existence can scan inside MariaDB; SQL scan cost remains a documented limit.

Reproduce on the isolated test DB only (sequentially, no concurrent suite):

```sh
docker exec waoo-phase1-runtime php -d memory_limit=512M vendor/bin/phpunit --filter=test_large_history_hydrates_two_neighbors tests/Feature/ReservationNeighborhoodTest.php
```

The current-run JSON and EXPLAIN metrics are generated under ignored storage/framework/testing/reservation-neighborhood-benchmark.json. No real client data, database dump or sensitive values are included. Rollback restores the prior scan; no data/index migration, resource release, live provider request, deployment or client message.

Claude reviews the source/query plan and opens this prepared stacked PR. Original integration and RECORD-VERSION approvals/acceptance remain separate; this new performance increment is not self-approved.
