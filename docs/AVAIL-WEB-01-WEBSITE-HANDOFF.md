# Website → CRM availability: integration handoff (S1, 2026-10-26)

Codex backend / Claude website. This delivery prepares the signed transport. **Do not switch the public website to CRM availability yet.** GET provides demand/unknown only; POST is503 `evaluation_unavailable`. Existing lead-intake connection stays unchanged. The complete target is [AVAIL-WEB-01-API.md](AVAIL-WEB-01-API.md).

## Configuration and wire agreement

- Current code allows local/testing/staging only; flag defaultsOFF. A deployed/reviewed crm-test version and separate authorized configuration are required for any host smoke test. Production is deliberately denied, even if a flag is accidentally set.
- Dedicated `WAOO_AVAILABILITY_SHARED_SECRET` on both PHP servers,32..512 raw bytes. Generate/store it privately via host configuration, independently from LEAD. Never place it in JS, HTML, Git, URLs, CLI arguments, issue comments or screenshots. No credential supplied or generated in this delivery.
- Website stores a fixed reviewed HTTPS CRM origin, not a destination from browser input. Do not follow redirects to other hosts or disable TLS verification. Maximum2seconds total request timeout; one bounded retry if still within that budget, otherwise fallback. Sign a fresh timestamp on a later retry; identical signed reads inside±300s may be retried without a mutation receipt.
- Build the exact target once (`/api/availability?` plus the encoded query), sign those same bytes and send them unchanged. No HTTP method override; GET body empty. Query order/percent encoding changes require a new signature. Input is date, optional ZIP, active mapped variant ID and alts0/1 only. Do not send a name/contact/address/event ID. Catalog mapping must be reviewed rather than guessing an ID from a label.

```php
$timestamp = (string) time();
$target = '/api/availability?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
$signature = 'sha256='.hash_hmac('sha256', $timestamp."\nGET\n".$target."\n", $dedicatedSecret);
// Headers: X-Waoo-Timestamp: $timestamp; X-Waoo-Signature: $signature
// Send GET to the configured trusted HTTPS origin + the exact $target above.
```

Synthetic cross-language test vector (public fixture only, deliberately not a credential):

- secret: `synthetic-availability-example-not-a-real-secret`
- timestamp: `1793034000`
- target: `/api/availability?date=2026-11-14&zip=77493&alts=1`
- body: empty; method: `GET`; no trailing whitespace after target, one final newline before empty body
- expected signature: `sha256=92714e66748f2dce2588ff1c3ffff2fdf900de219943730e7894d2c181eb36b2`

## Response and fallback

S1: exact closed200 schema, scope=day_signal, capacity_state=unknown, slots/alts=[], reservation_created=false. level may be filling for demand on the next8 weekend dates, otherwise unknown; it is not a scarcity/stock finding. requires_owner_approval conservatively compares the earliest8a.m. start that day with14 local days; candidate-specific approval belongs to S2. checked_at=expires_at intentionally prohibits caching this provisional estimate.

A successful HMAC or200 response proves transport only. It does not prove resources, a free time, a held date, customer booking, payment or a completed full AVAIL feature. POST503 remains expected even with a valid signature and JSON media type. Neither route creates a lead or sends a message.

Preserve the website's existing nonblocking request form and existing approved copy. Unknown, timeout, non200, malformed schema or unavailable evaluator must never become available slots/open or prevent a request. Current iCal fallback is demand/unknown evidence only, not verified CRM capacity. No raw request/response/error/coordinates/headers in logs; diagnostic status/timing counters only. No browser CORS permission or cookies for these routes.

## Still required before website activation

1. Reviewed public website-to-CRM variant/character mapping and matching request context.
2. Full read-only combined resource/skills/wardrobe0/equipment/driver/vehicle/cargo evaluator, actual-date traffic and both neighboring transfers. Missing evidence stays unknown; no ConfirmCapacity+rollback simulation.
3. Candidate validator including Houston DST, operational-day rollover, closing boundary, setup/breakdown,45min contingency once,14day exact comparison and approved exceptions. Actual daily10 limit and concurrent acceptance recheck; HoneyBook operational imports, paid/unpaid/pending-Zelle commitments preserved.
4. Private projections and snapshot consistency/cache invalidation tests, provider failure budgets and the rest of the contract matrix. No positive cache until its generation is trustworthy.
5. Cross-review, green CI, authorized crm-test deploy and synthetic signed smoke test from the website host. Then review Claude's disabled-by-default website adapter and fallback tests; authorize public connection separately. Nothing here activates production or changes live-provider configuration.
