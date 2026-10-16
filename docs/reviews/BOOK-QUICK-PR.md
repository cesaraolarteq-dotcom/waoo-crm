# BOOK-QUICK PR draft

Title: Sandbox: durable quick bookings and versioned client details approval

Head: `codex/quick-booking-core` · stacked base: `codex/sandbox-doc-pay`; retarget after DOC/PAY/screens land in main. The branch includes main's merged #22 and #30 and Claude's customer-file UI 3605c76. No main merge or deployment by Codex.

The owner's deposit-first booking flow now saves customer, event, agreed amount, actual verified Zelle receipt and idempotency result in one transaction, then issues a recoverable one-time portal grant. Repeated/concurrent requests cannot duplicate the party/payment; a failed link never loses the saved receipt. Expiry anchors remain stable across lost responses and Houston DST. Both sandbox flags required, production denied.

Adds immutable, scoped client-detail submissions with event/submission version checks and explicit owner approval. Draft review preserves money and original time, updates approved contact/venue through audited services, records a new financial revision without copying receipts and supersedes unsigned documents. Reserved parties permit identity-only review at the exact committed venue, retaining resource assignments/evidence and original agreement. Current source/financial state is rechecked before signing; generic edits after a signed contract require CHANGE-A. Personal-link HTML, API and real Livewire responses are no-store/private.

Contract was published before code: docs/BOOK-QUICK-API.md. Source delivery: 1a5fbe3. Initial module run: 205/1743; fresh integration with main 46ff921 (#22/#30) and corrected receipt fixtures: **205 tests / 1745 assertions pass on real MariaDB**. Includes actual two-process creation and client/owner contention, real owner UI/service and HTTP login/TOTP/Livewire, scoped/CSRF/immutable/rollback/duplicate/stale/signed-state and lost-response/expired-grant cases. Vite build, PHP lint and strict Composer validation pass. No provider calls, bank/Stripe proof, customer messages or deployed portal acceptance are claimed.

Claude: review the increment, wire portal details with both expected_event_version and expected_revision, add owner review/transport/time/operational-minute fields and connect attention states. Use the approved review's financial revision for the existing CreateContract/IssueContract services. Existing four-scope grants require explicit details.submit reissue; QuickBook requests all five. SMS opt-in needs an explicitly approved text/version (empty by default), no SMS enrollment/send occurs. Preserve migration 140000's SQLite rollback and both HANDOFF histories. Owner catalog/inventory, final source/UI review and authorized staging/owner acceptance remain pending.

PR title/body prepared in Git because the Codex GitHub GraphQL access previously returned 403; final attempt/outcome is recorded in HANDOFF. No PR creation is claimed by this file.
