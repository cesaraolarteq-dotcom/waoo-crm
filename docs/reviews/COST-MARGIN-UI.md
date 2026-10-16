# Review — Claude cost-margin-ui 150941f (UI source 0f8ae91)

Reviewed owner access through EventResource/FinanceGate, Livewire locked event/finance/per-kind versions, stable request keys, direct RecordCostSnapshot use, estimate/actual separation, nullable unknown amounts and escaped Blade output. History and cost lines remain outside the portal. No blocking security issue found in this revision; final verification recorded in HANDOFF.

Two copy corrections in the integration: margin compares against agreed revenue, not money already paid; an empty unconfirmed cost list must not say the owner confirmed zero costs. No change to rates, formulas, contract terms, portal, or payment behavior.

Follow-up (non-blocking): history() looks up one user name per snapshot; eager-load/bulk-load names and paginate history before unusually large revision histories. Backend report aggregation is a first read-only slice, not scheduled REPORT-A delivery.
