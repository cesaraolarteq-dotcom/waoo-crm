# UI components (Phase 1 step 4 build list, Claude)

Built as Blade components once the skeleton exists. Names map to the prototype (`docs/design/prototype.html`).

| Component | Props | Notes |
| --- | --- | --- |
| `x-shell` | title, active menu | Sidebar (grouped: Día a día / Vender / Operación), the pink "Reserva rápida" button, top search, mobile bottom nav ≤760 px |
| `x-page-header` | title, accent word, subtitle, actions slot | One primary action per screen |
| `x-hint` | text | One-line explanation under section titles |
| `x-todo-card` | count, tone (bad/warn/info/ok), title, text, link | Home "Necesita tu atención" |
| `x-pill` | tone, text | Status always in words + color |
| `x-pkg-dot` | package code | Logo color per package |
| `x-money-lines` | rows, total, tone | Tabular numbers; "Entró − pago al staff" label (not profit) |
| `x-progress` | steps, current | Event tracker: Depósito → Datos → Contrato → Saldo → Fiesta |
| `x-tabs` | tabs | Event file sections |
| `x-confirm-dialog` | title, body, callout, primary, danger? | Explains consequences before Verify Zelle / Reschedule / Cancel |
| `x-field` | label, help, input slot | Help text under every non-obvious field |
| `x-choice` | label, sublabel, pressed | Package pickers |
| `x-admin-only` | — | Purple dashed frame; renders only if the user is an admin (server-side check, not just CSS) |
| `x-copy-message` | text, langs | WhatsApp/SMS message with ES/EN toggle and copy button |
| `x-calendar-day` | events, buffers, conflicts | Hour grid, package colors, striped travel + 45 min, conflict callouts |

Accessibility: visible focus ring (teal), labels on all inputs, dialogs trap focus and close on Escape, AA contrast in light and dark.
