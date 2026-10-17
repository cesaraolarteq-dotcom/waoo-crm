# QUOTE-01 A: versioned owner quotes from event drafts

Suggested title: `Add immutable draft quote revisions with explicit discounts and safe retries`

Base: `codex/quote-01-contract` (API2d90794 published first). Source88d53f3, `codex/quote-01`. Keep migration300000, models, services, flag, routes and regression tests together. Retarget after parent backend/API branches merge; this branch does not incorporate the blocked #59 UI or rewrite Claude history. No PR was opened via the unavailable GitHub write API.

The CRM could save a draft and record money, but could not keep independent quote revisions. This adds owner-only create/read/revise APIs over an existing audited draft, freezes catalog prices/characters/outfits/travel evidence, and computes explicit discount/final/deposit amounts server-side. Old offers remain immutable, concurrent owners get one winner, and a same-key retry returns its original revision even after later edits or expiry. A bounded expiry command rechecks state and never expires accepted quotes.

This is foundation A only: no client quote grant, send/accept/reject/PDF or accepted-to-finance conversion is enabled. It does not mutate the source draft/lead/contact or create money/contracts/reservations. QUOTE-01 remains incomplete until scoped portal/PDF/acceptance (B) and exact accepted-snapshot finance/capacity bridge (C) pass review. Default-off sandbox, production denied.

Verification: targeted19 tests/119 assertions pass, including real separate-process create/revise/retry races, monetary precision/discount boundaries, immutable history, audit rollback, fresh owner/2FA/CSRF/feature/production guards, API privacy/pagination and populated SQLite up/down/up. Full345 tests/3269 assertions pass (354.97s), PHP8.3/MariaDB11.8, source88d53f3. Guarded local-only migration300000 and /up HTTP200 pass; feature remains disabled by default. Strict Composer,15 PHP syntax checks and diff pass; no dependencies or frontend changed. The first targeted failure was only associative attribute-key order in the fixture comparison; canonical key order now still compares every stored value exactly.

Claude can build the owner quote list/details/create/revise forms using ReadQuotes/CreateQuote/ReviseQuote, Locked displayed quote/event versions, full discount list on revision and a stable UUID per uncertain attempt. Retain paginator navigation, and keep historical returned revision separate from the current header after replay. Offer source draft selection for A; do not hide unrelated contact saves inside a quote action or claim no data was saved if a separately saved draft already exists.

No deployment, real provider call, client message, real charge or template/policy change. Email templates remain an unapproved draft. Next ticket order remains QUOTE → IMPORT → MAIL/MSG → AVAIL-WEB.
