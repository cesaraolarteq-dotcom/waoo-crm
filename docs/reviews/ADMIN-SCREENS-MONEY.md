# Admin screens review — d3fab7b and correction 3279591

2026-10-15 America/Chicago · Codex · `codex/review-admin-money`

**Verdict: accept the Phase 1 and money screens with correction `3279591`.** Original `d3fab7b` needs that small correction before merge. This branch is based on that exact SHA, so Claude can cherry-pick the fix or merge this branch, open the screens PR, and retain the migration rollback fix. No deployment or owner acceptance is implied.

## Findings and correction

- **M1 [P2] Fixed:** EventMoney's Fix amounts action supplied `fresh()->version`, so an administrator reading the old amounts could create an immutable agreement from a concurrently edited event without a stale-version refusal. The page now keeps a Locked displayedVersion from mount and passes it to CreateFinancialRevision. The backend's existing 409 guard remains authoritative. Added a real Livewire/service regression: update the fixture event version and transportation amount after page load; old page refuses without creating a revision.
- **M2 [P2] Fixed:** after draft edits, the existing financial revision was stale and the backend correctly refused receipts/confirmation, but the only action for creating a revision was hidden whenever any revision existed. The action is now available for stale draft revisions; a warning explains the need to review the draft and fix new amounts. A regression creates a new revision after a changed draft and proves the old revision and already received ledger payment stay intact. No revision creation for booked events is enabled.
- The two regression expectations failed on the original application code, then passed with the patch. They are default-suite regressions, not intentionally failing probes.

## Accepted behavior

Reviewed EventResource/Create/Edit, character selections and catalog recipes, unlink controls and blocks, Calendar, ConfirmCapacity, FinanceGate/EventMoney and the corresponding Blade views/tests. The previous historical-price finding is closed: edit options retain the draft's saved prices; its default regression passes. New selections remain constrained by the services. Character quantities compile into snapshots; omitted costumes are not invented; unlink uses its audited reason and does not hard-delete resources.

Money uses explicit owner services, integer cents, verified actual Zelle amount, bank confirmation, reference deduplication and Locked mutation UUIDs. An action halt preserves the key on rejection; successful writes rotate it. Paid confirmation calls ConfirmPaidCapacity, keeps ledger/capacity/signature status separate and does not count booking evidence as another receipt. The service revalidates event/financial version and resources under locks; failed assignment preserves the payment. Sandbox gates and owner/2FA middleware remain enforced; backend checks remain authoritative even if a button is hidden.

Calendar is a read-only view with the documented 45-minute warning. It does not claim automated traffic routing or public smart availability; transfer approval remains in the confirmation service. Unknown transportation cost stays pending. No claim of new browser/device or Hostinger verification is made in this review.

## Verification and merge

- Exact original d3fab7b: full PHP 8.3.35 / MariaDB 11.8 suite **141 tests, 1077 assertions passed**.
- Corrected candidate: EventMoneyPageTest, ConfirmCapacityPageTest, CharacterAndTypeFormsTest, CalendarPageTest, EventAndBlockFormsTest: **27 tests, 289 assertions passed**. Includes the two new regressions and actual paid-mode service path.
- Vite production build passed; diff checks passed. Original full suite was not described as a full run of the corrected candidate; required remote CI should run on the integrated commit.
- #22 is updated through main fe905e3 on commit 1a6b3f7. Merge #22 → retarget/merge #30 → update/open screens PR with 3279591. Preserve both HANDOFF histories and migration 140000's index-before-column rollback.
- Manifest includes tested/review evidence, not merge/acceptance claims. Run the renderer after combining it with main's prototype. Pending owner catalog/inventory, authorized staging deployment and owner acceptance remain separate.
