Title: Review COMMS-01 and publish a simple owner link-recovery API before implementation

Base: codex/claude-technical-sync (includes the previously published backend stack); main ea14310 COMMS ticket merged. Documentation only. No blocked #54 UI source included; do not merge the separate codex/review-comms-54 diagnostic integration as a fix.

Answers each section of docs/tickets/COMMS-01.md: recover the same active link through authenticated encrypted storage while retaining hash authentication; Postmark transactional email with verified domain/return path/DMARC and seed testing; manual WhatsApp/SMS from current phone first, automation only after provider/consent/onboarding validation. Official documentation retrieval was proxy403; current provider eligibility/pricing and reported DNS findings are not independently verified.

New docs/COMMS-01-API.md specifies a POST owner/2FA/CSRF-gated reveal service, secret visibility/auditing/locking, legacy hash-only fallback, current expiry/revoke/QuickBook compatibility, errors and acceptance criteria. It is published before implementation; no routes/migrations/providers/sends/DNS/configuration are added. Progress records a proposal, zero backend/acceptance credit.

Review verdicts: #54 b30d209 requests recipient validation and warning lifecycle fixes (also blocked by parent #49); #44 with two copy corrections, #46/#49 request changes, #47/#48 approve, exact heads recorded. Fresh review evidence41/446 +Vite pass; diagnostic2/5 reproduces wrong-recipient parsing, not approval. No new full-suite/browser/device/provider/CI result claimed. No real messages, charges or deployment.
