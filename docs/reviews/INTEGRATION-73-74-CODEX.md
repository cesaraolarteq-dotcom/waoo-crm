# PR #73 / #74 — Codex integration review, 2026-10-17

Targets: #73 claude/integrate-main b7f675e (integration source d0605bb, test correction c8b3fdf); #74 claude/mail-ui c7d1374 (UI d5b5aae, MAIL source b30e1cb). Reviewer branch codex/review-integration-mail; source under verification 0d519a1 adds QA d9d69d2 and mail date correction.

## Scope and findings

Reviewed integration conflict resolutions (ff97141, 4fd9404,1155803,041a81a,d0605bb,2c32780), routes, nine explicit Filament pages, event actions, imported capacity/money boundaries, portal quote bindings and mail owner-only reads/mutations. No blocking source issue found in #73 or #74. Keep #46 outside this integration: exact c81d0fd has two reproduced blockers; fixes99bbc78 await Claude cross-review on codex/review-catalog-46.

c8b3fdf is justified: capture the persisted FinancialRevision row before the cost action, compare against the persisted row afterwards. New quote migration defaults exist on reloaded rows but not initially instantiated attributes. Financial values/assertions remain intact.

Mail UI: fixed shown version for pause; reminder planning skips imported agreements; warnings and event timeline reveal no token/secret; statuses do not claim delivered on preparation. Source settings remain sandbox-only and DisabledMailTransport. No send endpoint, DNS/provider configuration or real delivery is approved by this code review. Nine-page union: Dashboard, Calendar, QuickBooking, Leads, Quotes, QuoteDetail, ContractTemplates, EmailTemplates, ImportHoneyBook. Preserve350000 MAIL migration, imported source340000, driver360000, day-only source evidence and140000 rollback order.

0d519a1 fixes Spanish full dates to day/month/year and supplies subject_date (date only, no time) without approving draft copy. Date tests cover maximum59-character Spanish subject and fall-back offsets. Retains prior quote same-Houston-day shortening and imported deposit below50% owner-note proof; never manufactures a payment.

Nonblocking follow-ups: timeline currently shows latest50 without UI pagination even though the API supports pagination; dashboard warning preview similarly caps at50 and should label/count that limit. A pause request with a reused UUID and changed desired state correctly conflicts; UI may retain the full original payload on retries for smoother recovery.

## Verification

MariaDB full run on frozen source0d519a1: 600 tests / 29,243 assertions PASS, 14:13.846, PHP8.3.35 / MariaDB11.8. It includes actual independent-process race tests; no second MariaDB runner touched this database. Earlier targeted new dates+MAIL/UI35 tests/192 assertions passed. Owner supplied GitHub green558 for #73 and green #74; GitHub API access is Forbidden in this environment, so no independent remote-check or formal GitHub approval is claimed. Full suite is local, not browser/phone/provider testing. Host Vite build and strict Composer validation passed after the isolated run completed.

Verdict: APPROVE source integration #73/#74, with Codex follow-up commits requiring Claude cross-review. This is a review on codex/*, not a merge into main and not an approval to activate transport or real imports. Claude should review the small follow-up source commits before combining them.
