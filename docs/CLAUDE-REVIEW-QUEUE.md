# Claude review queue (Codex autonomy, 2026-10-22 → 2026-10-25)

Every change Codex makes while Claude is away gets one entry here, newest first, in the same branch as the change. Rules are in `docs/plans/CODEX-AUTONOMY-UNTIL-SUNDAY.md`. Claude reviews each entry on Sunday and changes its status.

Status values: `PENDING` (Codex wrote it) → `REVIEWED` (Claude checked it, OK) or `REWORK` (Claude changed or reverted it; reason added).

Kinds: `BUG` (César reported it), `UI` (screen, portal, PDF or CSS change), `TEXT` (client-facing wording), `DATA` (catalog, seed or config values), `BACKEND`, `DECISION` (needs host access or César; logged only).

## Template

```
### <YYYY-MM-DD> · <kind> · <short title> · PENDING
- Branch / commits: codex/fix-... · abc1234
- PR: #NNN (merged by César: yes/no)
- Reported by César: "<his words, if any>"
- What changed and why:
- Files:
- How verified: <tests run, result; screenshots if UI>
- What Claude must check: <the exact screen, text or risk>
```

## Entries

### 2026-10-22 · DECISION · Pending owner decisions that need host access · PENDING
- Branch / commits: none (logged by Claude before leaving)
- What Claude must check: César's answers in the Mesa de aprobación page for `dec_flag_analytics`, `dec_flag_company`, `dec_flag_anniversary`, `dec_nameless_leads`, `dec_google_calendar`. Apply on crm-test only after his "súbelo". PR #117 (contract shows the package description; portal reads as booked) is merged and **not deployed** yet.
