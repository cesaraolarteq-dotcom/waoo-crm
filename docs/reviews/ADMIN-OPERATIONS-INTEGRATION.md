# Admin shell + operations integration trial

2026-10-15 · Codex · `codex/integrate-admin-operations`.

Source merge: operational branch `8dce2f7` (includes main `d151f6c`) + review branch `622bc8f` (shell `9d21360` plus seven HTTP Livewire tests). Route/handoff resolution committed in `3436a09`. This is a compatibility branch, not approval to merge PR #19: its R1/R2 recovery-code findings remain open, as described in ADMIN-SHELL-SECURITY.md.

## Actual main update versus the trial merge

Main already contains core PR #18, but not PR #19. Updating `codex/phase-1-operations` with main therefore conflicts only in HANDOFF; both histories were kept. Bringing the shell into the separate trial branch produces the expected routes/web.php conflict.

Applied Claude's requested resolution:

- Preserve the full operational `/admin/api` group, including the newer block listing/release endpoints.
- Remove the JSON home/login placeholders.
- Redirect `/` to `/admin`.
- Register `/seguridad/dos-pasos` with auth + password confirmation, importing the shell controller.
- Let Fortify own GET `/login`, `/two-factor-challenge` and `/user/confirm-password`.
- Keep one `/session/csrf` endpoint and one gated `/admin/api/session` route.
- Preserve both sides of HANDOFF, including the changes-requested verdict on the shell.

Route inspection finds 25 distinct admin API entries, each with RequireOwnerAdmin, one Fortify GET login action and one enrollment route. The trial has the complete shell dependency, not route references to controllers absent from the backend-only branch.

## Verification

On PHP 8.3.35 / real MariaDB 11.8:

- **56 tests / 349 assertions pass**, none skipped: the combined 49 original shell/operational tests plus seven added HTTP Livewire security tests.
- InnoDB and the real two-process reservation race both pass; the SQLite-only limitations reported by Claude do not apply here.
- Composer validation, PHP syntax and Vite production build pass.
- Existing recovery-code header/delivery findings R1/R2 were not changed by this route resolution; green tests do not mean those guarantees are fixed.

No changes to main, Hostinger, real accounts, charges or messaging were performed. No new visual/browser acceptance claimed.

## Next integration step

Claude addresses R1/R2 on PR #19, and Codex reviews the updated SHA. After the corrected shell enters main through the review workflow, merge that main into the operational branch and reuse this routing resolution as a reference, checking for any new authentication routes. Do not replace corrected auth controllers/middleware with the old trial copy. This keeps PR #22's backend-only branch independent until the shell is accepted.

The four non-blocking review follow-ups are linked in BACKLOG: RESV-A-PERF, CHANGE-A-CAPACITY, RESV-A-UNUSED and AUTH-READ-LIMIT. Their GitHub issue creation failed with GraphQL 403 in this session; the full ticket bodies are in docs/tickets for Claude's working account to submit without duplication.
