---
name: waoo-pantallas
description: Use when changing anything César or a client sees in the Waoo CRM — Filament admin pages, the client portal (resources/views/portal), quote/contract PDFs, CSS in public/css. Covers the design tokens, component classes, mobile-first checks and the screenshot proof Claude expects.
---

# Waoo screens (admin, client portal, PDFs)

The users are César and his wife (Spanish, not technical, mostly on a phone) and their clients (Spanish or English, almost always on a phone, often from a WhatsApp link). A screen is correct only when it reads clearly for them at 390 px wide.

## Before you change a screen

1. Find the existing pattern and reuse it. Do not add a new visual style.
   - Tokens: `public/css/waoo-tokens.css` (colors, type, radius, spacing, dark mode). Never hard-code a color that a token already covers; semantic colors (`--ok`, `--warn`, `--bad`, `--info`) are only for status.
   - Client portal: `public/css/waoo-portal.css` with `p-*` classes (`p-card`, `p-btn`, `p-btn-pink`, `p-steps`, `p-notice`, `p-chip`, `p-field`, `p-help`, `p-small`). Layout in `resources/views/portal/layout.blade.php`.
   - Admin: Filament 5 components first (`x-filament::section`, actions, forms, tables). Custom admin CSS lives in `public/css/waoo-admin.css` with `waoo-*` classes (calendar: `waoo-cal*`).
   - Copy for each surface: `app/Support/PortalCopy.php`, `QuoteCopy.php`, `StaffCopy.php`, `TipCopy.php`. Client wording follows the `waoo-textos` skill.
2. Package colors come from the logo tokens (`--pkg-*`). Only one accent-font word per page title.

## Rules that César has already asked for

- Spanish first, English equal. Every client screen has both, chosen by the client's language.
- Do not show amounts in places César removed them (unpaid quick-booking message). Zelle is shown without fee; card shows the 3% fee before paying.
- The contract shows the package description, not a list of requirements.
- When the client has signed and paid the deposit, the portal reads as booked ("¡Tu fiesta está reservada!").
- An owner session opening a client link sees a notice, never a 403.
- Calendar for picking dates is always visible (month grid), not a hidden date input.
- Test and example names look real: María González, Sofía Méndez. Never "PRUEBA", "test" or a real client name.

## Checks before you push

- Phone first: 390×844, then desktop 1280. Nothing scrolls sideways; buttons are at least 44 px tall; text does not clip.
- Light and dark (`data-theme` / `prefers-color-scheme`): every color comes from a token.
- Keyboard focus is visible (`--focus`).
- Run the Blade/Feature tests that render the view, and add one if the screen had none.
- Take screenshots with Playwright (pattern: `tests/Browser/*.cjs` + `tests/Browser/render-*.php`, or the dev preview route `/dev/portal/{step}` locally). Save them outside the repo and describe them in the review-queue entry: what the screen shows at 390 px, ES and EN.

## Never

- No new CSS framework, icon set or font host. Fonts load from Google Fonts only.
- No inline styles that duplicate a class. No `!important` unless overriding Filament and commented.
- No change to `.github/workflows/*`, frozen client texts, contract terms, prices or flags.
- Log every screen change in `docs/CLAUDE-REVIEW-QUEUE.md` as kind `UI` (skill `waoo-cola-claude`).
