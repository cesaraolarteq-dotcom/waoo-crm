# ARCH-01 (Claude part): e-signature and email provider comparison

Prices checked on the official pricing pages on **2026-10-15** (sources at the end). They can change; recheck before you buy. Volume scenarios come from the owner: about **40 events/month** today, so about 40 contracts. The two other scenarios are assumptions: growth = 60/month, peak month = 80/month.

## E-signature (owner decision D4)

| Option | Plan that fits | Monthly cost at 40 / 60 / 80 contracts | Embedded signing in our portal | Evidence / audit trail | Notes |
| --- | --- | --- | --- | --- | --- |
| **BoldSign API** | Enterprise API: from $30/mo, 40 envelopes, then $0.75 each | **$30 / $45 / $60** | Yes | Yes (audit trail on every plan) | Free sandbox for development (watermarked, deleted after 14 days) |
| **DocuSign API** | Starter: $50/mo ($600/yr), 40 envelopes | $50 at 40. Above 40, the next listed plan is Intermediate at $300/mo (100 envelopes); overage terms are not on the page | Yes | Yes | The 40-envelope cap matches today's volume exactly; growth pushes it to $300/mo |
| **Dropbox Sign API** | Essentials: $75/mo ($900/yr), from 50 requests | $75 at 40; Standard $250/mo at 100 | Not confirmed on the page for Essentials | Yes | Most expensive at today's volume |
| **In-house** (our own click-to-sign) | — | **$0 per contract** | Yes | We must build and keep it: consent screen, identity via the portal link + email, SHA-256 of the exact PDF, timestamps, IP/user agent, an immutable stored PDF, a copy emailed to the client | No vendor fee, but more build and test time. Evidence quality is our responsibility (Codex review D4) |

**Claude's recommendation (for the owner to decide):** after verifying prices, **BoldSign API ($30/mo)** is the best balance. It's cheap at our volume, grows slowly ($0.75 per extra contract), has embedded signing, and holds the evidence and audit trail, which lowers the legal and technical risk Codex flagged. The in-house option stays viable if the owner prefers $0 per contract and accepts more build time. Either way, it sits behind a `SignatureProvider` adapter.

## Transactional email (sender: sales@waooparty.com, D8)

Estimated volume: about 10 emails per event (quote, contract, receipts, reminders, portal links) × 40 = **~400/month** (peak ~800).

| Provider | Plan that fits | Cost | Limits | Notes |
| --- | --- | --- | --- | --- |
| **Resend** | Free | **$0** | 3,000/mo, 100/day, 3 domains | Pro is $20/mo (50,000/mo, no daily limit) if a day ever exceeds 100 |
| **Postmark** | Basic | $15/mo | 10,000/mo (extra $1.80 per 1,000) | The free plan is only 100/mo, which is too small |

**Claude's recommendation:** start with **Resend Free** ($0, about 400/month fits easily), with DKIM/SPF on waooparty.com. Move to Resend Pro ($20) if the 100/day limit is ever reached. ARCH-01 must confirm who controls sales@waooparty.com and that replies reach the owner's inbox (the Hostinger Starter Business Email).

## Monthly running cost, updated (Track A, verified items only)

| Item | Cost |
| --- | --- |
| Hostinger Business (already paid) | $0 extra |
| E-signature | $0 (in-house) or $30 (BoldSign) |
| Email | $0 (Resend Free) |
| Stripe | 2.9% + $0.30 per card payment (standard US rate; to confirm on the account) |
| SMS (Track B / later) | Twilio, not needed for Track A |
| **Total fixed** | **$0–30/month**, compared with HoneyBook at ~$80/month |

## Sources (opened 2026-10-15)
- BoldSign pricing: https://boldsign.com/pricing/
- DocuSign developer plans: https://ecom.docusign.com/plans-and-pricing/developer
- Dropbox Sign API pricing: https://sign.dropbox.com/products/dropbox-sign-api/pricing
- Resend pricing: https://resend.com/pricing
- Postmark pricing: https://postmarkapp.com/pricing
