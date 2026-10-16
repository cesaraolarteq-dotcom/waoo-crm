# DOC/PAY PR draft

Title: Sandbox: versioned contracts, scoped signatures and verified Stripe ledger

Head: `codex/sandbox-doc-pay` · suggested stacked base: `claude/admin-screens` (after #22 / #30 integration, retarget according to the screens merge). Latest admin a9f2996 and money fix are integrated into this branch for compatibility verification. This is a review request, not permission for deployment.

Actual `gh pr create` failed in this session: `POST https://api.github.com/graphql: Forbidden` (403). No PR was created by Codex; Git push works. Claude can use the title/body below through the existing working GitHub access. No token requested.

Adds the sandbox backend for versioned private contracts/PDFs, scoped client portal sessions and hash-bound in-house signatures. The presented PDF remains immutable; acceptance survives final-PDF failure and owner retry does not resign. Payment, signature and resource capacity remain independent.

Also adds test-only Stripe Checkout and verified webhook reconciliation on the existing exact-amount ledger: card allowlist, no Affirm/surcharge, authoritative account/mode/amount checks, transaction deduplication and late-payment/overpayment handling. Both sandbox flags default off; production remains denied. No provider request, real charge, message or deployment was performed.

Validation: full integrated PHP 8.3.35 / MariaDB 11.8 suite against Claude admin-screens a9f2996, **179 tests / 1482 assertions**, plus Vite build (standalone backend delivery was 146/1123); real two-process signature/payment races, dompdf, scoped portal and staging CSRF/HTTPS tests. PHP lint, Composer strict validation and route discovery pass. Provider adapter is fake in tests; actual Stripe-account probe, legal approval, portal UI/owner acceptance remain pending. Packagist advisory audit is blocked by HTTP 403.

Source delivery: 0cd7f5a. docs/DOC-PAY-API.md contains implemented route/service contracts and exact UI flow. docs/HANDOFF.md records scope and pending work. Claude owns portal HTML and reviews/merges this increment; no self-merge. Preserve the SQLite index-before-column rollback and both HANDOFF histories when integrating. QuickBook/SubmitClientDetails are proposed separately and not implemented in this PR.
