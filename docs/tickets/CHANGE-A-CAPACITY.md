# CHANGE-A-CAPACITY — Release or exchange reservations atomically on event changes

Status: open; non-blocking follow-up from Claude's PR #22 review. Parent: CHANGE-A; dependencies: RESV-A-01, PAY-02, DOC-01 and CHANGE-A's approved change lifecycle. Implementer: Codex; reviewer: Claude.

Problem: current committed events deliberately cannot be cancelled/rescheduled through draft editing. When CHANGE-A is implemented, changing an event status alone must not leave stale resource reservations blocking the schedule, or free the old date before securing the new one.

Scope: use the shared resource-lock protocol for the union of original/proposed resources, validate new dates/travel and atomically swap assignments only at the lifecycle's accepted-change point. On failure keep every original commitment. Cancellation releases capacity once, with authorization, reason and audit; it does not erase payment/contract evidence or initiate an unapproved refund. Follow the existing CHANGE-A acceptance/addendum/fee rules rather than adding a new pre-payment hold.

Acceptance/tests: failed reschedule preserves the entire original assignment; accepted reschedule replaces it without a gap or double booking; racing changes allow one valid result; repeated cancellation does not duplicate effects; resource calendar becomes available after authorized release; history, external payment evidence and signed artifacts remain. Replan notifications idempotently after commit under JOBS-01/CHANGE-A.

Files owned: future change lifecycle service, reservation transaction, audit and tests; coordinate documents/money/UI with their owners.

Rollback: disable the new change entry points and revert application changes normally while preserving ledger, documents and audit; no destructive rollback of accepted real changes.
