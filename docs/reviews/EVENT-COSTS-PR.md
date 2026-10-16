# COST-A foundation PR

Title: Sandbox event cost snapshots and contribution margin

Head: `codex/event-cost-snapshots` · stacked base: `codex/zelle-payment-notices`; retarget after prior reviewed increments merge. API contract first: d2e6da9, docs/COST-A-API.md. Source: **08c284a**.

Owners can record immutable numbered estimated and actual event-cost snapshots using explicit USD amounts. Unknown costs and missing owner coverage confirmation never become zero costs or a complete margin. The owner-only summary compares current revenue excluding taxes against complete current costs and labels actual cash separately. Stale finance/event versions invalidate the margin, historical costs remain visible, and negative margins stay negative.

Fresh owner/2FA and finance sandbox checks apply to services and API routes. Event row locks, optimistic per-kind versions, canonical UUID idempotency and atomic audit prevent conflicting edits or silent historical changes. Summary takes the same event lock for a consistent read. Bounded input/history, no portal cost projection and no changes to invoices, money, contracts or resource assignments. Migration210000 is additive and reversible before any later dependent migration.

This first slice does not implement rate versions, paid-hour/preparation formulas, automatic staff transport/shared-cost allocation, package margin, payroll, net profit, or scheduled reports. Owner data still determines those rules. Claude owns the owner-only estimate/actual forms and margin/history UI; show missing/stale coverage clearly and preserve displayed versions on save.

Focused MariaDB: **12 tests / 101 assertions** pass, including two real-process races, unknown/zero/negative costs, estimate/actual/cash separation, history/idempotency/stale agreement, fresh authorization, portal privacy and rollback. Full integrated result is recorded in the latest HANDOFF. Vite/PHP lint/strict Composer/diff checks pass. No real provider call, payment/disbursement, customer message or deployment.

PR creation remains subject to the previously observed GitHub GraphQL Forbidden limitation; no PR is claimed by this draft. Claude can open the prepared review with working access.

Final full integration: **241 tests / 2119 assertions pass on real MariaDB**, using512M only for the full-suite CLI command. The prior128M run ended prematurely in repeated dompdf fixtures; no test/assertion was removed. Test helper/start instructions were updated separately from application/production configuration. Nine real HTTP readiness checks pass. No application source changed after the successful run.
