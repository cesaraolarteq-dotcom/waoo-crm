# Preserve reviewed client details and resolve requested Houston wall times

> Integration update (2026-10-16): these source changes are approved and consolidated on `codex/integrate-42-43-fixes`. Open the single PR into `claude/quick-details-ui` using [INTEGRATE-42-43-FIXES-PR.md](INTEGRATE-42-43-FIXES-PR.md). The original description below is retained for context.

Base: `claude/zelle-notices-ui` (#43), after #42. Head: `codex/portal-review-fixes`. This contains the review-only commit 24ee242 plus fixes; do not merge this entire stack independently over main before the parent PRs are coordinated. Claude can cherry-pick the small source commits into the appropriate PRs instead, then retain the handoff and regression tests.

An owner confirming an already-open client-details review could approve a newer, unseen address because the UI fetched the latest submission at confirmation. The page now displays and submits a locked submission snapshot; stale approval is rejected by the service with no event/financial/audit write. Browser changes to that snapshot are denied. Reloading shows the new submission and permits explicit approval.

The real portal now submits requested local wall time, and the server resolves the event date in America/Chicago. March gaps are rejected; repeated November hours require a choice. Existing explicit-offset API clients remain supported. No automatic rescheduling. Consent, when configured, displays the exact approved escaped wording recorded by the service; its current empty configuration remains disabled. The isolated local/testing design preview retains a sample, never records consent and remains closed in production. The Zelle UI fixture now uses yesterday and runs at frozen 00:01 Houston. Contract PDFs also label the ten catalog equipment keys requested in the previous review.

Interface recorded first: docs/PORTAL-REVIEW-FIXES.md; BOOK-QUICK-API updated. Source commits: bf2ffac (R1/R2/C1/R3), 4acf3a4 (catalog PDF labels), 7ebcf2c (isolated preview adapter). Ordinary regression coverage includes stale page/modal, locked-state tampering, both DST transitions/occurrences, malformed/mixed inputs, stable retry and exact consent evidence. Final verification counts are in the newest docs/HANDOFF.md entry.

Claude review is required. This does not approve, merge, deploy or activate the parent PRs, legal text, SMS or real charges.
