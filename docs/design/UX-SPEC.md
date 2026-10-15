# Waoo CRM — UX and visual spec (D-UX-01, Claude)

Status: proposal for the owner and Codex. Clickable prototype: `docs/design/prototype.html` (also published privately to the owner). The prototype is design only; all clients, dates and amounts in it are fictitious, and the package prices come from the live website.

## Principles
1. **Today first.** The home screen answers "what needs me now?": payments to verify, unsigned contracts, balances due in 24 h, unconfirmed staff, conflicts.
2. **State as shape and color.** Every event shows separate status pills (payment, contract, crew), never one mixed "status" (v2 §7).
3. **One event, one file.** Everything about a party lives in its event file: details, money, crew, costumes, documents, messages, history. Cancel/Reschedule live there (CHANGE-A).
4. **The client sees one path:** quote → sign → pay, in her language. The date is secured only when payment arrives (D7), and the portal says so.
5. **Money is admin-only where it should be.** Costs and margins sit in a dashed purple "Solo tú y Maybeli" frame and are never rendered in client or staff views.
6. **Plain Spanish in admin** (the owners' language); the portal and emails are EN/ES. Legal contract text stays English (D9).

## Visual system
| Token | Light | Dark | Use |
| --- | --- | --- | --- |
| `--navy` | #122F94 | #6F8BFF | Primary actions, Waoo package |
| `--sidebar` | #0E1B52 | #070B1C | Navigation |
| `--teal` | #15D1C4 | same | Focus ring, positive accents |
| `--purple` | #8D53D9 | same | Magic package, admin-only frame |
| `--pink` | #FF017C | same | Client CTA ("Firmar y pagar"), badges |
| `--yellow` | #F9C930 | same | Star package |
| Semantic ok/warn/bad/info | green/amber/red/blue tints | adjusted | Status pills only (never brand colors) |

- **Package color code** (the logo's colors): Star = yellow, Magic = purple, Music & Games = teal, Music & Games 2.0 = pink, Waoo = navy. The same chip appears in lists, the calendar, quotes and reports.
- **Type:** Bricolage Grotesque (the website's brand face) for the UI; Bodoni Moda italic only for a single accent word in page titles (website continuity). Tabular numbers for money and times.
- **Layout:** desktop navy sidebar + workspace (max 1180 px); phone ≤760 px: bottom navigation, 2-column summary cards, stacked panels. Minimum touch target 40 px.
- **Calendar:** day columns with hour grid; travel/setup buffers drawn as striped blocks; conflicts outlined in red with the reason in words (for example "animadora ya está en Waoo · Ortiz").
- **Accessibility:** visible focus (teal ring), color never the only signal (pills carry words), contrast AA in both themes, reduced-motion respected.

## Screens (Track A) and the tickets that build them
| Screen | Shows | Key actions | Ticket |
| --- | --- | --- | --- |
| Inicio | To-dos (4 counters), weekend list with status, money this week, packages sold | Open event, new quote | UI-ADMIN-01, REPORT-A |
| Calendario | Week/day, package chips, travel buffers, conflicts | Drag to reschedule (later), open event | UI-ADMIN-01, RESV-A-01 |
| Ficha de evento | Party details, payments ledger, crew by role, costumes, documents, history, cost/margin (admin) | Verify Zelle, assign crew, reschedule, cancel, message | UI-ADMIN-01, PAY-02, CHANGE-A, COST-A |
| Cotización | Package, characters (+$150), add-ons, ZIP → travel fee, deposit 50% | Send by email/SMS | UI-QUOTE-01, QUOTE-01 |
| Portal clienta | Quote accepted, contract sign (typed name + consent), deposit (Zelle instructions with reference, or card) | Sign and pay, switch EN/ES | PORTAL-01, DOC-01, PAY-01 |
| Staff y trajes | Per-costume availability per day, staff roles; pay rates (admin) | Add/edit | OPS-01 |
| Reportes | Monday and monthly summaries (also emailed to sales@) | — | REPORT-A |
| Catálogo (not prototyped yet) | Packages, variants, crew recipe, prices by version | Edit with versioning | OPS-01, BIZ-01 |
| Contrato plantilla (not prototyped yet) | Versions, diff, activate | Edit → new version | TPL-01 |

## Copy rules
- Admin ES: short verbs ("Verificar Zelle", "Asignar staff", "Reprogramar"). Errors say what happened and what to do.
- Client: warm and brief (brand tone), EN/ES equivalent; "costo de transporte" / "travel fee"; never imply a date is held before payment.
- Money format: `$1,234.50`, America/Chicago times as "Sáb 17 · 2:00 pm".

## Open design questions for the owner
1. ~~Maybeli's view~~ **Decided 2026-10-15: Maybeli sees exactly what César sees (full admin, including money and staff pay).**
2. Do you want the staff to have their own phone view (Track B STAFF-01), or do you keep coordinating crew by WhatsApp for now?
