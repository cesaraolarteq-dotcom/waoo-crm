# Pending merge audit · 2026-10-27

César explicitly requested merging pending work. Snapshot base `27a395580625a0393ced34fb937571ddccb14381`. No deployment or mail activation is authorized by these merges.

## Incorporated and closed

PRs #83, #82, #81, #80, #79, #78, #76, #68, #65, #59, #57, #56, #54, #49. Each received its own evidence comment. All but #81 have exact heads already ancestral to main; #81 has no branch-only file delta and a read-only git merge-tree produces exactly the current main tree. Closing these is cleanup, not new feature completion. Branches were not deleted or rewritten.

## Current deliveries consolidated

- #126 independent owner API read/write quotas with access guard priority; preserve all Resend/availability bootstrap additions. Source review status remainsPENDING; owner expressly requests integration.
- #128 extra HTTP payment/idempotency/revocation tests, preserving the later Resend guard test and approved new portal UI expectations.
- #132 includes #130: signed PDF open/download card, fixed-width questions and owner-approved balance disclosure behind a closed payment section; hide the confusing Zelle notice form, keep its API and state/receipt protections. Old SameVisit assertions expected the previous wording. The revised fixture explicitly runs the PDF finalizer before expecting a signed-download link.
- #113 official Dependabot action-version changes merged separately after6 green checks and checking upstream tags: checkoutv7/setup-nodev7/cachev6; no workflow logic or test/audit policy edits. The consolidation has zero workflow diff against main.
- #137 docs: preserve CARD-FEE-01 after AVAIL and clarify that the historical core already exists. A missing aggregate-only dry-run CLI wrapper must reuse that core; check existing captures/host state before asking for files/reimporting. No source import or new card fee behavior implemented by this merge.

## Frozen migration stacks: genuine blockers

These target other old branches, not main. Their old two-check results do not validate an integration with today's money/portal/importer code. Do not merge them between obsolete bases merely to close their PRs. Read-only merge simulations against the snapshot above:

| PR | Conflicting paths | Main-only / branch-only commits |
|---|---:|---|
| #92 | 13 | 218 / 17 |
| #94 | 24 | 212 / 78 |
| #95 | 13 | 212 / 20 |
| #96 | 13 | 212 / 24 |
| #97 | 13 | 212 / 27 |
| #99 | 22 | 212 / 51 |
| #102 | 24 | 212 / 76 |

Core conflict examples in #102: `app/Filament/Pages/Dashboard.php`, `app/Filament/Pages/ImportHistory.php`, `app/Http/Controllers/Admin/HistoricalImportController.php`, `app/Providers/Filament/AdminPanelProvider.php`, `app/Services/Documents/CreateContract.php`, `app/Services/Finance/RecordZellePayment.php`, `app/Services/Finance/StripeReceiptRouter.php`, `app/Services/Finance/StripeTestGateway.php`, `app/Services/History/DiscardHistoricalImport.php`, `app/Services/History/HistoricalSource.php`, `app/Services/History/ReadHistoricalImport.php`, `app/Services/History/ReadHistoricalRow.php`, `app/Services/History/StageHistoricalImport.php`, `app/Services/Mail/EventMailSource.php`.

Required recovery: port relevant financial bridge/original-document/refund/Stripe-proof parts onto current main, preserve current HoneyBook decoder, immutable money/signature evidence and owner-approved client privacy/48h terms, add compatible adapters and integration tests, then review one current-main recovery branch. Keep feature gatesOFF and preserve financial_bridge_not_implemented/transfer_not_implemented blockers until their guarantees are actually implemented. No real cohort switch, send or bank action. The seven original PRs remain open as frozen recovery references, not falsely labelled complete.
