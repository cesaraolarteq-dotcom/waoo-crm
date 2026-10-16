# COMMS-01 · Getting the client link to the client (owner request, 2026-10-16) — options for Codex

Owner: “The link is shown only once; if the tab closes it is a hassle. With HoneyBook more than 50% of our emails land in clients' spam, so we also text them. Can we avoid spam as much as possible? For SMS, can I use my Waoo Party number or do I need Twilio?” Owner's number 346-851-8830 is a regular **AT&T mobile line**. Owner priority: **simplicity of the process**.

Codex: please evaluate and answer each section with your recommendation (or a better alternative), then publish an API contract before implementation, as usual.

## A. Link shown only once → owner can copy it again (backend: Codex)

Today only a hash of the portal token is stored. Proposal **PORTAL-LINK-REVEAL**:
- Store the raw token **encrypted** (Laravel encrypter / APP_KEY) next to the hash on issue; never in logs, audit payloads or API lists.
- `GET /admin/api/events/{event}/portal-grants/{grant}/link` (fresh owner + confirmed 2FA, sandbox gates) returns the URL only while the grant is active and not expired/revoked; each reveal is audited (`portal.link_revealed`, no token in the audit).
- Revocation / expiry / rotation unchanged. Optional: “Reemitir enlace” = revoke + issue in one action.
- Question: any objection vs. keeping hash-only? Alternative you prefer (e.g. short-lived re-issue)?

Claude already shipped the UI side that needs no backend: one-tap **Abrir WhatsApp** / **Enviar por SMS** with the message prefilled (`wa.me/<number>?text=` and `sms:+<number>?&body=`, sent by the owner from her own phone/computer), and a leave-page warning until the link is copied or sent (branch `claude/link-send-buttons`).

## B. Email deliverability (Phase 5)

Findings (DNS, read-only, 2026-10-16): waooparty.com has **no SPF, no DKIM, no DMARC** records; MX = Hostinger. HoneyBook sends on the owner's behalf from its own infrastructure.

Proposal:
- Transactional provider with good inbox placement: **Postmark** (preferred: transactional-only streams) or Amazon SES; send from the owner's domain (e.g. `reservas@waooparty.com`, or a subdomain `mail.waooparty.com` to protect the main domain's reputation), Reply-To the owner's inbox.
- DNS: SPF including Hostinger mail + provider, provider DKIM, DMARC starting at `p=none` with reports → later `quarantine`. (DNS change = separate owner approval.)
- Content: short, personal, plain-text + light HTML, one link on the same domain as the sender (`crm.waooparty.com`), no URL shorteners, no attachments on first contact, consistent From name “Waoo Party”.
- Bounce/complaint webhooks recorded; email + SMS/WhatsApp together for the link (never only email).
- Question: provider choice, sending subdomain, and how you would test placement before go-live (seed inboxes Gmail/Outlook/Yahoo/iCloud).

## C. SMS / WhatsApp (Phase 6)

- An AT&T mobile number **cannot** be used by an API for SMS (Twilio “hosted SMS” is not available for wireless numbers; porting would move calls too). Proposal: a **new Twilio local number** (346/281/832) only for automated texts, **A2P 10DLC** brand + campaign registration (owner's EIN/LLC; approval 1–3 weeks), STOP/HELP handling, consent text already on the website form.
- Alternative to evaluate: **WhatsApp Business Platform** (Cloud API or via Twilio) with approved templates — clients already use WhatsApp with Waoo Party. Check whether Meta's app+API **coexistence** lets the owner keep 346-851-8830 on the WhatsApp Business app while the CRM sends template messages from the same number; costs per conversation; opt-in rules.
- Question: which channel first (SMS via Twilio vs WhatsApp templates), and the minimal compliant setup.

Until then the owner sends the link from her phone with the one-tap buttons; nothing is sent automatically.
