# Waoo CRM: working rules (Claude Code and Codex)

The same rules are in `AGENTS.md`. Keep both files identical.

## Product
- Owner: César (Waoo Party LLC). The only users are César and his wife. Spanish-speaking, not technical: explain things in plain Spanish.
- Goal: replace HoneyBook (~$80/mo) for about 40 events a month. Run in parallel with HoneyBook until proven, then migrate about 250 archived clients.
- The plan is `docs/PLAN.md`. Work phase by phase. No phase starts without the owner's OK.

## Business rules (never invent; owner data wins)
- Deposit is 50% of (package + travel fee) and non-refundable. The remaining 50% balance is due 24 h before the event. Record the exact amount received and recalculate the balance.
- Zelle is the main payment method (recorded manually). Cards go through Stripe with a 3% processing fee: credit cards only, disclosed before payment; confirm the card-network rules.
- Contract = the owner's HoneyBook contract (SOW + terms 1–10). Merge fields only; the terms text changes only with the owner's approval and a version bump.
- A character is required only when the package includes one.
- Prices, packages, fees and policies come from the owner (see the Waoo master context in the website repo). Never invent a price, discount, availability or policy.
- In Spanish write "costo de transporte"; in English write "travel fee". Every client-facing text is EN + ES.

## Security and privacy
- Never commit `.env`, API keys, Stripe/Twilio secrets, database dumps, HoneyBook exports or any real client data. Use fake fixtures in tests.
- Never store card data (Stripe Checkout/Elements only). Verify Stripe and Twilio webhooks by signature.
- Client portal: expiring signed links, no cross-client access. Admin: two-factor authentication.
- E-signature records: signer name, email, timestamp, IP, user agent and a SHA-256 hash of the exact signed document (UETA/E-SIGN).

## Collaboration
- Roles are in `docs/COLLABORATION.md`. Work on your own branch (`claude/*` or `codex/*`) and open PRs into `main`. No force-push, no rewriting others' commits.
- Log every delivery at the top of `docs/HANDOFF.md`: date, author, branch, what changed, files, how it was verified, and open items.
- Tests and checks pass before a PR. Say plainly what was not verified.
- Deployment to Hostinger (crm.waooparty.com) and any live Stripe/Twilio change need the owner's explicit approval.

## Skills (Waoo-specific, in `.codex/skills/`)
- `waoo-pantallas`: any screen, portal, PDF or CSS change.
- `waoo-textos`: any word a client reads, and César's approval-page decisions.
- `waoo-cola-claude`: logging every change in `docs/CLAUDE-REVIEW-QUEUE.md` for Claude's review.
Claude Code follows the same files when it works on those areas.
