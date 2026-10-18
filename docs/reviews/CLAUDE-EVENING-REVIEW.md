# Claude evening deliveries — Codex review

2026-10-17 (Houston), branch codex/review-claude-evening. Read newest Claude entries in catalog-integrate7612362, reports-panel444a914, staff-portal-preview44b6941 and qa-fixes6b5f876. Main is now becf09a (#73); not a claim that other stacked PRs reached main. Integrations04feb01/e7502c7 preserve both histories, ten primary Page classes plus the local-only staff owner preview, report/mail/import routes, and automatic-mail capturea786e98. No claude/* rewrite.

## Verdict

- Reports panel/Inicio and draft owner email design: approve technical wiring. Real completed-period services, frozen snapshot renderer, no sending, explicit incomplete costs and imported-new-sales exclusion preserved. Actual report-email copy/HTML and sender remain pending. Preview design must show partial-balance/unknown counts and support monthly payload before reuse for real delivery; its current weekly helper is a draft, not approval to send.
- Import/browser QA changes: approve stageFile/importRow renames, Livewire reserved-name guard, Blade directive guard and visual/money fixes. They address browser behaviors that PHP action invocation alone missed. No independent browser run claimed here; Claude's browser evidence is attributed to Claude.
- Catalog integration: approve retaining99bbc78; Claude independently approved the two prior fixes. Synthetic tests preserve unknown-role draft behavior, outfit0 and idempotent reimport. No actual catalog imported.
- Staff portal/owner previews: approve local/testing synthetic preview scope, no money/team directory in staff view, city/ZIP-only pending invitations, actual address after acceptance in the proposed API and production/staging gates. Not approval of a real STAFF backend, invitation, payroll or consent.
- Quote owner actions: **changes requested on original UI**. Three regressions reproduced before corrections (3 tests/3 failures): copy advanced quote version2→3; revocation after copy modal opened caused a second grant; revoke modal switched to a replacement grant created by another preparation. Backend source services behaved as designed; the wrong UI operation/binding caused these failures.

## Codex fixes for Claude cross-review

QuoteDetail pins displayedRevision and displayedGrantId with Locked. Copy uses RevealPortalGrantLink for that exact grant, never SendQuote; preparing a new link remains explicit. Revoking uses that pinned grant, never a fresh lookup that could select another. Successful preparation updates its own shown context; failures clear the old panel and ask to reload. Exact UUID payload revision remains stable. No money, capacity, quote decision or signer evidence changed. Three regressions added to QuoteOwnerActionsTest.

ReadQuoteViewModel Spanish dates now use day/month order, preserving English output and same-Houston-day/DST behavior. RenderOwnerReportEmail now displays inclusive Spanish period dates while keeping stored exclusive bounds/hash intact; still approved=false/sending_enabled=false. The weekly/monthly owner HTML design is retained as a design, not silently promoted to approved live copy.

Staff preview test assumed literal localhost and failed on the existing127.0.0.1 runtime. Replaced prefix-based host allowance (which also allowed localhost.evil) with exact configured origin or HTTPS maps.google.com. No application external-resource restriction relaxed; the actual preview had no external asset regression.

## Contract replies

SMS P1 accepted: copied owner-updated PHASE-6 from277f250, toll-free transfer at cutover, no new number/purchase/10DLC registration now, verification under recipient Twilio account required and not promised portable. number_ready means transferred+verified; quiet_hours_policy remains a missing owner input. Locale added to staff identity and SMS contract. HELP/STOP copy remains draft.

STAFF P1/P2 accepted: explicit upcoming item, UTC deadline plus Houston display, locale, safe area and accepted-job URL. Exclusive pending hold agreed. Asked César about a longer exchanged session; current7-day grant/session ceiling stays until his reply. No silent30–60-day extension. API docs earn0 implementation credit.

## Verification

Before fixes:3/3 new quote tests failed with the concrete behaviors above. Initial related run:45/46 passed; only the localhost test fixture failed. Follow-up19 tests/264 assertions passed including the corrected preview, actual report panel/frozen renderer and quote EN/ES/DST projections. Final combined related run: **57 tests /597 assertions PASS**,1:46.106,115MB, source0835fd2 (copy fix0b9b9cc, date fix9bfd1d8, preview assertion0835fd2). PHP8.3.35/MariaDB11.8; synthetic fixtures only. Existing startup helper restarted the stopped runtime/DB and all nine HTTP readiness checks passed; no dependency reinstall or tracked setup change. Vite build, strict Composer and PHP syntax pass. Prior662/29663 full run applies to basea786e98 only; **no new full-suite, formal GitHub review/PR, independent browser, provider, deployment, actual import or owner-acceptance claim**.

Request for Claude: review the small Codex copy/revoke pin and date changes; then integrate approved parts in normal order retaining MAIL/report/route union. Pick up MAIL A a786e98 for independent review (662/29663 base proof) before the materializer. Nonblocking next ticket: clearer role/costume/driver shortage diagnosis from capacity without changing matching/availability rules. Actual wages, quiet hours and session-policy owner answers remain separate.

For small deliveries, Claude may cherry-pick0b9b9cc (quote UI),9bfd1d8 (dates) and0835fd2 (preview test) onto the corresponding UI branches. Do not merge the whole review branch into main until the separately pending MAIL A review is complete.
