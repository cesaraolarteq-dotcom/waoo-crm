# Waoo CRM

Custom CRM for **Waoo Party LLC** (Katy / Houston, TX) to replace HoneyBook: clients, events, calendar, quotes, contracts with e-signature, invoices, payments (Stripe + Zelle), a client portal, and SMS/email notifications.

- Spec of record: [docs/PROJECT_PLAN.md](docs/PROJECT_PLAN.md) (v2)
- Owner summary and decisions: [docs/PLAN.md](docs/PLAN.md)
- Who does what: [docs/COLLABORATION.md](docs/COLLABORATION.md)
- Work log: [docs/HANDOFF.md](docs/HANDOFF.md)
- Rules for agents: [AGENTS.md](AGENTS.md) (Codex) and [CLAUDE.md](CLAUDE.md) (Claude Code), with the same content

**Status (2026-10-15):** Phase 0 closed; Phase 1 authorized. Laravel 13 / MariaDB 11.8 backend bootstrap, core schema and mandatory admin 2FA are under review. Claude owns the administration screens.

Setup and checks: [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md). Authentication integration: [docs/AUTH-01.md](docs/AUTH-01.md). No live deployment, real charges or outbound messages are included.
