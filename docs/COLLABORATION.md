# Roles: Claude Code and Codex (proposal, pending the owner's OK)

| Area | Lead | Reviews |
| --- | --- | --- |
| Product decisions, phase scope, owner communication | Owner + Claude | Codex |
| UX/UI: admin screens, client portal, calendar views, mobile layout | Claude | Codex |
| Templates and copy EN/ES: contract, quote, invoice, emails, SMS | Claude | Codex |
| Data model, migrations, business logic (quotes, deposits, balances) | Codex | Claude |
| Integrations: Stripe, Twilio, email provider, Google Calendar, website form | Codex | Claude |
| Security: auth, 2FA, signed links, webhook verification, backups | Codex | Claude |
| Tests, QA scripts, CI, release packaging | Codex | Claude |
| Integration of branches into `main` | Codex | Claude |

Rules:
- Contracts between the two sides (routes, field names, events, API payloads) are written in `docs/` before they are built.
- A request for the other side goes into `docs/HANDOFF.md` as "Request for Codex" or "Request for Claude".
- Owner approval is required for each phase start, for the contract text, for prices, and for any deployment.
