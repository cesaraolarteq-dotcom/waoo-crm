# Quote decision text — v2 DRAFT for César's approval

Status: **draft, not active.** v1 (2026-10-17) was too heavy for a quote; César asked for a lighter text on 2026-10-18 ("es una cotización, no un contrato"). Used by the client quote page (/portal/quote): the text sits right above its own button and **tapping the button is the answer** (no checkbox). Once a quote is sent, its text is frozen with that quote; a later wording change needs a new version and only affects new quotes. Config key `waoo.quote_acceptance_consent` (`version` + `es`/`en` → `accepted`/`rejected`), plain text, no placeholders.

Answering a quote is **not** the contract, does not reserve the date and does not charge anything. Deposit (50 %, non-refundable) and balance terms are explained in the contract the client signs afterwards.

Version: `quote-consent-v2-2026-10-18`

## "Me interesa, quiero reservar" button

**ES:** Me interesa esta cotización y quiero reservar. Sé que esto todavía no aparta mi fecha ni cobra nada: Waoo Party me enviará el contrato y el depósito para confirmarla.

**EN:** I like this quote and want to book. I know this does not reserve my date or charge anything yet: Waoo Party will send me the contract and deposit to confirm it.

## "No me interesa por ahora" button

**ES:** Por ahora no me interesa. Puedo pedir una cotización nueva cuando quiera.

**EN:** Not for now. I can ask for a new quote anytime.

## Owner checklist

- [ ] Wording OK in Spanish and English (or tell Claude what to change).
- [ ] After approval Claude puts it in the configuration with this version; nothing changes for quotes already sent.
