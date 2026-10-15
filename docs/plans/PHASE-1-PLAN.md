# Phase 1 action plan (proposal; starts only after the owner approves EXIT-0)

> Revisión Codex 2026-10-15: [dictamen y condiciones de implementación](../PHASE0_REVIEW.md). ARCH-01 firmado técnicamente en `docs/arch/ARCH-01-ADR.md`; esta revisión no inicia Fase 1. Se conserva la propuesta de Claude para incorporar los ajustes identificados.

Goal of Phase 1: **an event can be fully managed by hand in the CRM with real resources**: clients, events, catalog, staff, costume units, equipment, manual assignment with conflict protection, calendar and audit. No client-facing features yet (no portal, no payments, no messages).

## Preconditions (EXIT-0)
- [x] Hostinger feasibility proven (DISC-02, all probes pass)
- [x] D1–D9 decided (D4 = in-house signature)
- [x] ARCH-01 ADR signed off by Codex with the host evidence (docs/arch/ARCH-01-ADR.md, 2026-10-15; implementation conditions in PHASE0_REVIEW)
- [x] Owner approves closing Phase 0 and starting Phase 1 — chat approval recorded in HANDOFF.md (2026-10-15)

## Work order (one implementer, one reviewer per ticket)
| Step | Ticket | Implementer / reviewer | Output | Owner input needed |
| --- | --- | --- | --- | --- |
| 1 | Repo bootstrap: Laravel 13 app in `waoo-crm` (from the probe learnings), CI with tests + lint, `.env.example`, no secrets | Codex / Claude | App skeleton that installs on Hostinger like the probe | — |
| 2 | DATA-01 schema + fake fixtures (cents, America/Chicago, audit log) | Codex / Claude | Migrations, seeders with fictitious data | — |
| 3 | AUTH-01 admin login + 2FA for César and Maybeli; roles | Codex / Claude (login/2FA screens) | Two admin accounts on the test site | The emails for the two accounts |
| 4 | Admin shell UI (sidebar, Inicio skeleton, design tokens from UX-SPEC) | Claude / Codex | Navigable admin matching the prototype | — |
| 5 | OPS-01 catalog: packages, variants, crew recipe per package, prices with versions | Codex (data) + Claude (screens) | Catalog editable in the CRM | Answers in the catalog worksheet |
| 6 | OPS-01 resources: staff (roles, rates, admin-only), costume units (Mickey ×2, Rumi ×2, …), equipment, vehicles | Codex + Claude | Resource screens | Inventory and staff list |
| 7 | Events + clients CRUD, the event file screen | Codex + Claude | Create/edit events by hand | — |
| 8 | RESV-A-01 manual assignment with transactional conflict protection (person, costume, equipment, vehicle, travel buffers) | Codex / Claude | Saving a conflicting assignment is refused with a clear reason | Setup/teardown times and the buffer rule (worksheet) |
| 9 | Calendar (month/week/day, package colors, buffers, conflicts) | Claude / Codex | Calendar screen | — |
| 10 | Phase 1 acceptance with the owner on the test site | Both + Owner | Signed-off checklist | 30–60 min of the owner's time |

## Acceptance (Phase 1 done when)
- The owner creates a Waoo event for a fictitious client, assigns the crew and Rumi #1, and sees it in the calendar.
- Assigning Rumi #1 to an overlapping event is refused; Rumi #2 is offered instead.
- Two admins saving the same assignment at once: one wins, the other gets a clear message (tested on MariaDB like P4).
- Every change to price, date, crew or costume appears in the event history with who and when.
- Works on desktop and phone width; admin text in Spanish.

## Deploy path (test site only)
Code is pulled with Git on the server into the private app folder (as in the probe); the hPanel cron keeps the scheduler; `.env` stays on the server only. Production (`crm.waooparty.com`) is a separate later step with its own approval.
