# #68 · client quote projection and required wiring

2026-10-17 · Codex · reviewed source `068a87b`, fetched from claude/quote-portal-ui. Backend adapter contract docs/QUOTE-CLIENT-VIEW-MODEL-API.md. Source `3460e59` on isolated `codex/quote-client-view-model`, based on codex/quote-portal9d9e080; same adapter/test code as integrated `5dbb7d2`.

The Blade view escapes client-visible values and reads a whitelist rather than the owner aggregate. Backend now supplies the exact view-model at GET /portal/api/quote `view_model`, with server-formatted USD cents/Houston dates, bilingual display formatting, frozen consent, status and disabled actions. It also preserves the existing raw safe payload and pinned hashes/consents for decisions. No migration or consent-text approval introduced by this adapter. Revoked prior revision links remain403; an otherwise valid link with a changed draft projects superseded. Language switch cannot replace frozen approved consent.

**Needs wiring before approval of actual client actions:**

1. Map signer_name to `name`; send all fields from QUOTE-PORTAL-API: expected_revision, snapshot_sha256, pdf_sha256, consent_version, outcome-specific consent_sha256, name, email, consent=true. Keep the initial values; never replace them with a fresh revision on submit.
2. Reject also needs explicit frozen rejection text/consent and identity. The current reason-only request is not accepted; do not silently consent for the client. PDF must be opened through this grant before either decision.
3. Namespace UUID by quote number + revision + outcome. Revision1 alone collides across quotes in one browser. Preserve the exact attempted payload with its UUID through timeout/retry; editing it must not reuse the previous UUID.
4. Wire the actual /portal/quote route to the existing fragment/session bootstrap and scoped read. `/dev/portal/quote` remains preview. null actions disable controls (including administrative sessions); keep the disabled state after validation/network failures when no consent is available. No consent text gets approved by merging a preview.

This is a source/integration review, not a claim of live-browser or remote CI validation. The adapter has 4 new feature tests; combined backend evidence is recorded in HANDOFF. This document does not claim formal GitHub approval or approve the still-incompatible decision JavaScript.
