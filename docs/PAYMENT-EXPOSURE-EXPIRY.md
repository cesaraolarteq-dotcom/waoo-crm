# Unresolved checkout exposure at capacity expiry

2026-10-31 · Codex · supplement to S2.

An intent is unresolved until a terminal unpaid state is verified. Prepared/creating attempts without a session ID are exposure, as are open/processing/paid/superseded/reconciliation attempts. A deadline cannot erase that exposure. The scheduler closes known sessions outside locks, verifies an expired unpaid result with no processing/succeeded intent, then rechecks all local attempts under the event lock before freeing stock. Unknown sessions keep stock until reconciliation.

Owner release/expiry must apply the same guard; no manual shortcut around an outstanding collection. The legacy request-preparation service performs no provider dispatch and creates no PaymentIntent; its existing cancellation behavior stays intact. No provider I/O inside admission locks. Original deadline is never extended, no new charge may begin after it, and verified late money remains recorded by the existing protocol. This does not implement S1 full-team capacity retention or enable payments.

If a provider accepted a checkout but its session ID was lost, keep its stock pending reconciliation even beyond the original deadline. This change deliberately does not invent a recovery/search UI or extend the collection deadline. That operational recovery remains a pre-go-live item.
