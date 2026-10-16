# PAY-02 Zelle notice PR

Title: Sandbox Zelle notices with atomic owner verification

Head: `codex/zelle-payment-notices` · stacked base: `codex/doc-pay-followups`; retarget after preceding DOC/PAY/QuickBook review and integration. Source delivery: **3fe2dd1**. Contract committed first: **c882ef3**, docs/ZELLE-NOTICES-API.md.

A client can report a Zelle transfer without changing the ledger/balance. The owner then explicitly verifies the actual bank amount/reference/time or rejects the notice. Existing matching manual receipts are linked without duplicate credit. The immutable claim and unique immutable decision preserve both amounts and the review reason; event/notice row locks and transaction-level audit/idempotency protect concurrent reviews and retries. No reservation or signature transition occurs.

Portal POST uses existing checkout.create and refuses admin impersonation; GET uses payments.read and returns a scoped, bounded projection without bank/owner evidence. Existing session/CSRF/2FA/throttle and both sandbox flags remain. No new grant defaults or provider dependencies. Owner queue uses SQL review existence/state filters; no full-history scan. Additive migration 200000 drops reviews before notices on rollback; receipt/history corrections are separate work.

Initial focused verification: 12 MariaDB tests / 117 assertions. Final integrated results, including the additional invalid-input/verification/pagination test, are at the top of HANDOFF. Three real two-process tests cover same-key client retries, contradictory owner decisions and concurrent manual receipt vs notice verification. Actual staging CSRF and rollback/private-scope/current-revision cases included. No real bank/Stripe/provider call, deployment or client message.

Claude: review this backend after the preceding three small DOC/PAY follow-ups and QuickBook. Then wire the client declaration and owner queue/verify/reject forms from ZELLE-NOTICES-API; these screens are not delivered here. Client claims must always say pending verification, and balances must come from FinanceSummary. Keep true owner bank evidence separate from the client's proposed amount/reference.

Final full integration: **229 tests / 2017 assertions pass on PHP 8.3 / MariaDB 11.8**. All 13 notice scenarios pass; Vite build, PHP lint, strict Composer, route discovery and diff checks pass. No source changes after this run. PR API remains unavailable under the previously observed GraphQL Forbidden response; no new PR is claimed. Branch push is separate and its outcome is in HANDOFF.
