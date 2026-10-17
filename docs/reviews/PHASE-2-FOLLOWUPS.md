# Re-review: #49 → #56 → #57 → #59

2026-10-17 · Codex · `codex/review-phase-2-followups`. Main6d46f8a includes the progress update #63; that merge is documentation/progress, not a source merge of these screen PRs. No deployment reported or performed.

| PR | Exact fetched head | Verdict |
| --- | --- | --- |
| #49 | 619720f | Approve; original10 backend tests preserved exactly,3 screen tests in their own class |
| #56 | b2f5ba2 | Approve; zero-named outfit preserved, filled rendering, precise generic-unit wording |
| #57 | f096c04 | Approve;22-case reference calibration and failed-recalculation guard retained exactly |
| #59 | d04561a | Approve; original pagination blocker fixed; one nonblocking boundary note below |

These are technical repository verdicts, not formal GitHub API reviews (write API previously Forbidden). Previous #54 approval81771b2 and #46/#47/#44 approvals stand; no new review of changed heads for those PRs is claimed here.

## Findings resolved

Both contact test files and both transport test files are byte-for-byte identical to ed8d426, checked directly. The original backend class still tests rollback/replay and real concurrent owners; renaming the screen class avoids losing that evidence. #56 filters only empty strings, so the value `"0"` survives; event display uses filled, and the resource selector no longer claims a generic unit can satisfy an exact-version request.

#59 forwards the current page to the bounded ReadLeads service, exposes paginator metadata and previous/next buttons, and resets the page on status change. The51-record regression reaches the oldest lead through the page action and direct `?pagina=2`; an excessive page requested through the controls clamps to the last page. This resolves the original unreachable-record blocker.

New-customer email maps through `new_customer.email` inside the same QuickBook call. Existing-customer conversion fills the selected record's contact/locale, not the lead's email; hidden new-customer fields cannot overwrite it. No new pre-save, duplicate booking path or automatic customer merge was introduced.

## Evidence

**34 tests /477 assertions PASS** (36.552s), PHP8.3/MariaDB11.8, tested source **ba3d9d2**. Contact backend+screen classes, outfit screens, travel screens/reference calibration, lead screens and quick-booking page tests. Actual separate-process customer races still pass;22/22 reference pricing cases match with provider fakes, not live Google measurements. Vite build and diff check pass. No dependency/application source change by Codex after these checks.

QA-only commit **ba3d9d2** adds an assertion that a new lead's email survives the real paid booking, plus a complete existing-customer-by-phone conversion proving its email/locale/version remain unchanged, one customer remains and the lead links to the real event. Claude can cherry-pick it onto #59; it does not change production code or require a new API.

No fresh full-suite claim: the older369/3672 full result belongs to ed8d426, and the345/3269 quote-foundation full result belongs to88d53f3 on its separate backend branch. These34 checks address the changed screen/data paths without repeating those complete runs. GitHub CI green is the owner's/Claude's report; no actual browser/mobile/remote CI/provider checks were performed here.

## Nonblocking initial URL boundary

Code inspection: `goToPage` caps using lastPage, but initial `rows()` only lower-bounds leadPage before reading. A manually entered/bookmarked `?pagina=999` can therefore produce an empty page while the status has records; the rendered Anteriores control can recover it by clamping. Likewise the current last page can disappear after status updates. Normalize an initial requested page to the actual last page and requery when necessary. Add an initial excessive-URL test alongside the existing action clamp test. This is a smaller follow-up than the original lost-access issue: normal navigation now reaches every record. No runtime reproduction of this specific extreme-URL case is claimed by the34 passing checks.

## Request for Claude

1. The four exact heads above are technically approved; integrate using normal PR bases/CI/owner merge, preserving the two contact classes and calibration data/tests. This review branch is a reproduction/integration branch, not a request to merge its entire ancestry wholesale.
2. Pick ba3d9d2 for stronger email-conversion regression coverage. Initial-URL normalization is a nonblocking follow-up; no new backend/API request for the four screens.
3. Keep main merge, independent website/spool review, actual secure shared-secret/Google configuration, staging/device verification and owner acceptance separate in progress. Website lead intake remains off until explicitly authorized. No deployment or real messages are authorized by these approvals.
4. QUOTE A remains separately delivered on `codex/quote-01`, API2d90794 then source88d53f3, full345/3269. Review that backend and wire owner forms against its API; planned quote portal/PDF/acceptance/finance conversion is not enabled by these UI approvals. Follow docs/QUOTE-01-API.md and the top HANDOFF of that branch for the next work.
