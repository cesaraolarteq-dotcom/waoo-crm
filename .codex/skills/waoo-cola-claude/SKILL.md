---
name: waoo-cola-claude
description: Use at the end of every Codex change while Claude is away (or whenever Codex touches an area Claude leads) — how to log the change in docs/CLAUDE-REVIEW-QUEUE.md, prove it, and report it to César in Spanish so he can merge.
---

# Logging work for Claude's review

Claude reviews exactly what is in `docs/CLAUDE-REVIEW-QUEUE.md`. A change that is not logged there is treated as unreviewed and may be reverted.

## For every change

1. Branch `codex/fix-<short-name>` from the newest `main`. One fix or tight group per branch.
2. Prove it:
   - A test that fails before the fix and passes after. Say which.
   - For screens, screenshots at 390 px ES and EN (skill `waoo-pantallas`). For client words, the final ES and EN strings (skill `waoo-textos`).
   - Name what you could **not** verify (real data, Hostinger, real Stripe, browser).
3. Add one entry at the top of "Entries" in `docs/CLAUDE-REVIEW-QUEUE.md`, in the same branch, using the template in that file. Kind: `BUG`, `UI`, `TEXT`, `DATA`, `BACKEND` or `DECISION`. Status `PENDING`.
4. One short line at the top of `docs/HANDOFF.md` pointing to the queue entry.
5. Push, wait for CI green on the branch, then tell César in Spanish:
   - what was wrong, in his words if he reported it;
   - what changed, in one or two plain sentences;
   - the compare link, and "únelo solo si las pruebas salen en verde".

## What a good "What Claude must check" says

Concrete and short: the exact screen or route, the text key, the risky part ("the deposit math when the travel fee is 0", "dark mode of the new card"). Not "review the code".

## Never

- Deploy, send messages, charge, change flags, touch real data or HoneyBook.
- Merge to `main` yourself or edit `.github/workflows/*`.
- Mark an entry `REVIEWED`; only Claude does that.
- Put client names, phone numbers, emails or money of real clients in the queue, HANDOFF or chat.
