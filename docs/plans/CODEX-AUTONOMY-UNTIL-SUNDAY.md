# Codex autonomy while Claude is away (Thu 2026-10-22 → Sun 2026-10-25)

Owner decision (César, in chat, 2026-10-22): Claude is out of credits until Sunday. Codex may keep fixing everything César finds in the CRM, **in every area**, so work does not stop. Everything Codex changes in areas Claude normally leads is logged in `docs/CLAUDE-REVIEW-QUEUE.md` so Claude reviews exactly those items on Sunday.

This is temporary. It ends when Claude is back and has worked through the queue. `docs/COLLABORATION.md` roles return to normal after that.

## 1. What Codex may do (temporary)

| Area | Normal lead | Until Sunday |
| --- | --- | --- |
| Bugs César reports (any screen, portal, PDF, message) | Claude / Codex | **Codex fixes** |
| Admin screens, client portal, calendar, mobile layout (Filament/Blade/CSS) | Claude | **Codex may change**, logged as `UI` in the queue |
| Client-facing text (portal, PDF, emails, SMS/WhatsApp) | Claude | **Only César-approved text** (section 4). No new client wording without approval |
| Backend, data, integrations, tests, CI | Codex | Codex, as usual |
| Integration into `main` | Codex / Claude | César merges green PRs (section 3) |

## 2. What stays forbidden (unchanged)

- No deploy to crm-test or crm.waooparty.com. Claude deploys after reviewing the queue (or César asks Claude on Sunday).
- No real emails, SMS, WhatsApp sends, charges, refunds, real import or real data correction. Feature flags stay as they are; do not turn any flag on in code defaults.
- Never modify HoneyBook. Never commit `.env`, secrets, dumps, HoneyBook files or real client data. Test names are like María González, never "PRUEBA".
- `config/client-texts.php` is frozen. The contract terms text does not change.
- Never invent prices, discounts, durations, availability or policies.
- No force-push, no rewriting Claude's commits, no edits to `.github/workflows/*` (the token cannot push them; ask César).

## 3. Workflow for each fix

1. One small branch per fix or tight group: `codex/fix-<short-name>` from the newest `main`.
2. Write or update a test that fails before the fix. Run the affected tests.
3. Push. CI runs automatically on `codex/**` (5 shards, about 9 minutes).
4. Add an entry to `docs/CLAUDE-REVIEW-QUEUE.md` **in the same branch** (template in that file) and a short line in `docs/HANDOFF.md`.
5. Tell César in Spanish, in one or two sentences: what was wrong, what changed, and the compare link. César opens the PR with GitHub's "Compare & pull request" button and merges **only when CI is green**.
6. Before the next branch, update from `main` so fixes do not conflict.

If a fix needs a decision only César can make (price, policy, wording, what a screen should do), ask him in plain Spanish and wait. Do not guess.

## 4. Approved texts from the "Mesa de aprobación" page

César reviews real previews of emails, WhatsApp/SMS messages and new portal texts in a private page and pastes the result to Codex. The pasted text lists each item as APROBADO, PEDIR CAMBIO (with his note) or SIN REVISAR, by id (for example `[portal_reserved]`).

- **APROBADO, text already in the code** (emails, Stripe messages, quick booking messages): nothing to change; record the confirmation in the queue.
- **APROBADO, new text** (`quote_link_msg`, `portal_reserved`, `portal_zelle_memo`, `portal_stripe_confirming`, `portal_owner_notice`, `pdf_bill_to`): add it to a **new addendum version** following `config/client-texts-addendum.php` (new `docs/templates/APPROVED-CLIENT-TEXTS-<date>.json` source, version `client-texts-<date>`, `approved_by` quoting César's paste). Read the text from the addendum where the code allows it without a risky refactor; otherwise record in the queue that the string is approved but still inline.
- **PEDIR CAMBIO**: apply César's note exactly, keep ES and EN equivalent, and log it as `TEXT` in the queue so César sees the new version on Sunday. It is not approved until he approves the new wording.
- **Decisions** (`dec_*`): "Sí, hazlo" items:
  - `dec_mail_cst`: drop the time-zone abbreviation from `MailDates::full` in ES and EN (keep the time), with a test.
  - `dec_glitter_90`: add a 90-minute $350 price option for Glitter Mobile in the catalog seed/config used by crm-test, without changing the 2-hour price.
  - `dec_babyshower_setup`: if César says the times are different, update the variant's setup/breakdown; if he confirms 15/15, only log it.
  - Flag decisions (`dec_flag_*`), `dec_nameless_leads` and `dec_google_calendar` need host access: **only log them** for Claude.
- **SIN REVISAR**: do nothing.
- `birthday_draft` never goes to clients while it says "Borrador"; wait for César's real wording and percentage.

## 5. Work queue when César has no bug for you (in order)

1. **Verifier decoder amendment** per `docs/reviews/HONEYBOOK-CAPTURE-SHAPES.md` (status vocabulary mapping; one project id appearing in several capture files is not a duplicate). Keep unknowns unverified. Claude reruns on the real capture on Sunday.
2. Text decisions from section 4 as César sends them.
3. **AVAIL-WEB-01** published API contract only (no web connection), per item 12 of `CODEX-PENDING-2026-10-20.md`.
4. **CHANGE-A A1–A3** per `docs/CHANGE-A-API.md`, flag OFF.
5. Package cost and margin read (owner-entered costs only, no invented numbers), flag OFF.

## 6. What Claude does on Sunday

1. Read `docs/CLAUDE-REVIEW-QUEUE.md` top to bottom; review each diff, re-run its tests and check the screen.
2. Fix or revert anything that breaks the UI rules or client wording, then mark each entry `REVIEWED` or `REWORK`.
3. Deploy the reviewed `main` to crm-test after César says "súbelo".
