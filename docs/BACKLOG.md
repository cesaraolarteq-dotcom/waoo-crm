# Backlog: Track A then Track B

Spec of record: `docs/PROJECT_PLAN.md` (v2). Owner decisions D1–D6 and the Codex conditions are in `docs/HANDOFF.md` (entries of 2026-10-15). **Phase 0 approved by the Owner on 2026-10-15.** Later phases each need their own approval.

Every ticket: scope, non-scope, implementer, reviewer, files owned, acceptance criteria, tests, rollback. No parallel edits to the same files or migrations. "Owner" = César/Maybeli data or decision.

## Change to v2's dependency chain (Codex 2026-10-15)
v2 has `PAY-01 → HOLD-01 → AVAIL-01`, which would block payments behind the automatic engine (Track B). For Track A we add **RESV-A-01**: transactional protection of the resources an admin selects by hand. PAY-01 depends on RESV-A-01 instead of HOLD-01. In Track B, HOLD-01 reuses the RESV-A-01 protocol; that protection is never removed to move payments forward.

## Phase 0: audit and rules (Track A)

| ID | Task | Impl. | Review | Depends on | Done when |
| --- | --- | --- | --- | --- | --- |
| DISC-01 | Audit `waoo-website` (commit), integrations (form, travel-fee/Routes, calendar availability) and the real Hostinger capabilities | Codex | Claude | Repo access + Phase 0 OK | Inventory that marks each item verified / assumed / blocked |
| DISC-02 | Feasibility probes in an authorized test environment: signed test webhook, a job that fails then recovers on retry, a private PDF, a backup restored to a separate target | Codex | Claude | DISC-01 | Commands, results and limits recorded; no production restore, no DNS change |
| BIZ-01 | Draft catalog: packages/variants, included roles and times, characters; a sample of real resources (no sensitive data) | Claude (with Owner data) | Codex | Owner data | Draft catalog with gaps listed per service |
| BIZ-02 | Money and travel-fee policy: compare the v2 historical table with the live `travel-fee-config.php` tiers; tax and per-method fees | Codex | Claude | DISC-01 | Calculation cases documented, including the boundaries |
| DOC-00 | Contract as a versioned template (full text, client data removed), plus an anonymized quote and invoice | Claude | Codex | Owner sends the clean contract/quote/invoice | Template v1 with merge fields; Spanish version only if Owner decides D9 |
| ARCH-01 | ADR: stack, master calendar, roles/access, jobs, storage, backups, RPO/RTO; e-signature comparison (D4: DocuSign, Dropbox Sign, BoldSign, in-house); email provider; Stripe test access proven | Codex (ADR) + Claude (e-sign and email comparison) | the other | DISC-01/02 | Signed-off ADR; D4 presented to the Owner with verified prices |
| PLAN-01 | Executable backlog: estimates, interface contracts, cost scenarios (40 events/mo, growth, peak) | Claude | Codex | ARCH-01 | Backlog and costs reviewed by both |
| EXIT-0 | Present the results and gaps to the Owner | Both | — | all above | Owner accepts closing Phase 0 and authorizes Phase 1 separately |

## Phase 0 status (Codex audit 2026-10-15)

| Ticket | Implementa / revisa | Alcance y salida | Estado |
| --- | --- | --- | --- |
| DISC-01-WEB | Codex / Claude | Auditar checkout web, mapa de reutilización, rutas/formulario/calendario, evidencia local | Entregado en PHASE0_AUDIT; sin cotejo live |
| DISC-01-HOST | Codex + administrador autorizado / Claude | Inventario de capacidades reales, evidencia staging aislado | Bloqueado: no existe staging; requisitos preparados |
| BIZ-01 | Claude con dueño / Codex | Completar variantes piloto, roles, tiempos, inventario y dotaciones | Base observada documentada; recetas/inventario pendientes |
| BIZ-02 | Codex con dueño / Claude | Contrastar transporte activo; reglas de impuestos/cargos/redondeo/excepciones | Fórmula checkout probada; live y decisiones pendientes |
| DOC-SOURCE | Claude / Codex | Contrato completo y ejemplos anonimizados, versión EN/ES | Documentos no accesibles en checkout |
| ARCH-01 | Codex / Claude | ADR validado por evidencia de hosting; selección firma y agenda, costos | Propuesta escrita; faltan viabilidad, fuentes y D4 final |
| STAGE-PROBE | Codex / Claude | Webhook Stripe test firmado, job con fallo/reintento, PDF privado, backup restaurado en destino separado | No ejecutado; requiere staging autorizado/bindings |
| PLAN-SYNC | Claude / Codex | Unificar precedencia documental, actualizar pendientes resueltos por D6 y fases A/B | Solicitado; conservar D1–D6 |
| PHASE0-EXIT | Dueño / ambos | Revisar evidencia, resolver bloqueos y aceptar cierre; autorizar siguiente incremento por separado | No listo |

No-alcance de estos tickets: rehacer web, desplegar CRM, procesar pagos reales, enviar mensajes a clientes o cancelar HoneyBook. Revertir entrega documental mediante commit normal si se rechaza; no reescribir commits compartidos. Las pruebas futuras en staging tendrán plan de limpieza/rollback separado sin afectar producción.


**Codex names map to this backlog:** RESERVE-A = RESV-A-01, MAIL-A = MAIL-01, PAY-01-A = PAY-01, DOC-SOURCE = DOC-00 (done in main), PLAN-SYNC (done in main), STAGE-PROBE = DISC-02.

## Phase 1: base and operation

| ID | Task | Impl. | Review | Depends on |
| --- | --- | --- | --- | --- |
| DATA-01 | Schema, migrations and fake fixtures (integer cents, America/Chicago, audit) | Codex | Claude | ARCH-01 |
| AUTH-01 | Roles, sessions, 2FA for admins, server-side permissions (Claude: login/2FA screens) | Codex | Claude | DATA-01 |
| OPS-01 | Catalog, resources (staff, characters, costume units, equipment, vehicles) and events, entered by hand | Codex (data) + Claude (admin UI) | the other | AUTH-01 |
| RESV-A-01 | Manual resource assignment with transactional protection: the admin selects resources; conflicts (person, costume, equipment, vehicle, travel) are rejected on save and re-validated on confirm; the same protocol for every schedule write | Codex | Claude | OPS-01 |
| UI-ADMIN-01 | Dashboard, event file (summary, services, agenda, resources, documents, payments, messages, history), calendar month/week/day | Claude | Codex | OPS-01 |

## Phase 2: quotes and logistics
| ID | Task | Impl. | Review | Depends on |
| --- | --- | --- | --- | --- |
| LEAD-01 | Connect the website's smart form to the CRM (lead + event draft; recoverable error) | Codex (API) + Claude (website form) | the other | AUTH-01, DISC-01 |
| QUOTE-01 | Versioned quote engine (server-side totals, revisions, acceptance) | Codex | Claude | BIZ-02, DATA-01 |
| ROUTE-01 | Adapt the existing Routes integration; failure goes to review, never a $0 fee | Codex | Claude | DISC-01, BIZ-02 |
| UI-QUOTE-01 | Quote builder and quote PDF/email design, EN/ES | Claude | Codex | QUOTE-01 |

| COST-A | **Cost and margin per package and per event (owner request 2026-10-15)**: staff pay by role × paid hours (incl. prep/makeup time where the owner says so), staff transport pay (variable by people picked up + distance; formula from the owner), materials/consumables, card fees → profit per package (estimate) and per event (estimate vs actual after the event), monthly summary. Admin-only; staff never see others' pay | Codex (rules, calculations) + Claude (report UI) | the other | OPS-01, QUOTE-01, owner rates |

## Phase 4: portal, signature and payments (Track A)
| ID | Task | Impl. | Review | Depends on |
| --- | --- | --- | --- | --- |
| BOOK-QUICK | **Quick reservation (owner's real flow, see docs/plans/QUICK-BOOKING.md)**: the owner enters package/characters/date/travel/deposit, which computes total/balance/due date; a client link where she completes name, phone, email, event address and start time and signs; when both halves are complete the contract is generated, the event is booked and confirmations/reminders are scheduled. **First client-facing feature of Track A** | Claude (owner form, client form, contract render) + Codex (link security, data, payments ledger, jobs) | the other | OPS-01, DOC-00, RESV-A-01, PAY-02 |
| JOBS-01 | Durable jobs/outbox and alerts (moved up from Phase 5) | Codex | Claude | DATA-01 |
| MAIL-01 | Minimal transactional email: portal links, documents, receipts (provider chosen in ARCH-01) | Codex (sending) + Claude (templates EN/ES) | the other | JOBS-01 |
| BOOK-A-01 | Pending-booking lifecycle: who creates it, duration, release on expiry, incomplete signature, Zelle pending, late payment → reconcilable incident (**durations = Owner decision D7**) | Codex | Claude | RESV-A-01 |
| DOC-01 | Contract instance from template v1, immutable signed PDF, evidence (provider or in-house per D4) | Codex (evidence/storage) + Claude (document/signing UI) | the other | DOC-00, ARCH-01 |
| PAY-01 | Stripe test Checkout + ledger; exact deposit/balance (D3); verified, deduplicated webhooks | Codex | Claude | QUOTE-01, RESV-A-01, JOBS-01 |
| PAY-02 | Zelle: the client declares a payment, an admin verifies and records the real amount (adjustable, author + reason, audited); installments, overpayment → credit | Codex | Claude | PAY-01 |
| TPL-01 | Contract template editor: an admin edits the clauses and saves a new version (author, note, preview, diff), activates it for new contracts, and can never edit a version that was already used; addendum flow for booked events | Claude (editor UI) + Codex (versioning, immutability) | the other | DOC-00, DATA-01 |
| PORTAL-01 | Client portal EN/ES: event details, quote, sign + pay in one session (D2), documents, balance; mobile and accessible | Claude (UI) + Codex (auth/API) | the other | AUTH-01, PAY-02, DOC-01, MAIL-01 |

## Phase 5: pilot and communications (Track A)
| ID | Task | Impl. | Review | Depends on |
| --- | --- | --- | --- | --- |
| MSG-01 | Essential reminders (7 days, balance, 24 h) that cancel when stale | Codex + Claude (copy) | the other | JOBS-01, BOOK-A-01 |
| CAL-01 | One-way mirror to Google Calendar | Codex | Claude | OPS-01, JOBS-01 |
| IMPORT-01 | Import and reconcile future HoneyBook events and their capacity limits (before the pilot) | Codex | Claude | DATA-01, OPS-01 |
| CHANGE-A | **Cancel and reschedule booked events (owner request 2026-10-15; rare but required)**. **Reschedule** (date and/or time): pick the new slot, re-check resources and travel (RESV-A protocol) **before** releasing the old one, apply the $100 change fee as an invoice line (admin can waive it with a reason), count reschedules (contract 2.5: once, within 60 days), generate an addendum the client accepts in the portal, update the calendar mirror, and cancel and re-plan reminders. **Cancel:** reason + who; outcome from the contract version (deposit non-refundable; within 48 h up to 100% retained; weather: 50% credit for 60 days if paid in full); release resources; void pending payment sessions and reminders; notify the client; keep contract, payments and history. Admin can override with a recorded reason | Codex (rules, transactions) + Claude (screens, addendum and notice copy) | the other | RESV-A-01, PAY-02, DOC-01, JOBS-01 |
| REPORT-A | **Owner reports by email (owner request 2026-10-15)**, simple and visual, not detailed. **Every Monday:** money in (deposits + payments received that week) and pending balances vs the weekend's staff cost, plus what was sold that week as counts per package (for example "2 Waoo · 1 Magic · 1 Star"). **On the 1st of each month:** events booked, income received, staff cost, net (income − staff), packages sold in the month. Sent by the cron-driven scheduler (America/Chicago) to **sales@waooparty.com** (owner decision 2026-10-15); the same view in the admin dashboard. Data from the ledger (PAY-01/02) and COST-A; definitions (cash by payment date vs events by event date) shown on the report | Claude (email + dashboard design, copy ES) + Codex (queries, scheduling, idempotent send) | the other | PAY-02, COST-A, MAIL-01, JOBS-01 |
| PILOT-01 | Controlled pilot plus runbook: one system per event sends charges and messages | Both | Owner | everything above |

## Track B (after the Track A pilot)
AVAIL-01 (search for combinations and routes), HOLD-01 (on the RESV-A-01 protocol), then v2 Phases 6–8: STAFF-01, SMS (needs Twilio A2P), COST-01, self-service, WhatsApp, final migration. Each service turns on automatic availability only after it passes the v2 §20 tests.

## Owner data received (for BIZ-01 / OPS-01)
- 2026-10-15 staff pay by role (per hour): recreador $25, animador $35, personaje $60, personaje de traje (mascot/costume) $80; personaje de peluca (princesses, K-Pop) needs prep, rate to confirm. Prep time, minimum paid hours, the role descriptions and the staff transport pay formula are being collected in the owner's catalog worksheet (Claude Docs).
- 2026-10-15: some characters have **two costume units** (for example Mickey and Rumi); most have only one. Model a costume unit per physical copy (v2 §4): two Mickeys can serve two parties at once, a single-copy character cannot. The full list of costumes and copies is still pending from the Owner.

## Open owner decisions
- **D7 decided:** nothing is held until a payment is received (see HANDOFF 2026-10-15).
- **D8 decided:** sales@waooparty.com.
- **D9 decided:** contract in English only.
- **Phase 0: APPROVED by the Owner on 2026-10-15.**
