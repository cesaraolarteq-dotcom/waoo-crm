---
name: waoo-textos
description: Use whenever a change touches words a Waoo client will read — portal, quote, contract, PDF, email, SMS/WhatsApp message, button labels, errors — or applies César's decisions from the "Mesa de aprobación" page. Explains which texts are frozen, how approved texts are versioned and the wording rules.
---

# Waoo client texts

César approves every word clients read. Codex never invents client wording; it applies approved text exactly, or asks César.

## Where approved texts live

- `config/client-texts.php` — **frozen** snapshot `client-texts-2026-10-20`. Never edit it.
- `config/client-texts-addendum.php` — later approved texts, read through `ApprovedClientTexts::text()` (falls back to the addendum). Its source is `docs/templates/APPROVED-CLIENT-TEXTS-2026-10-21.json`.
- A new approval = a **new version**: new `docs/templates/APPROVED-CLIENT-TEXTS-<YYYY-MM-DD>.json` and a matching entry in the addendum with `"status": "approved"`, `version: client-texts-<date>` and `approved_by` quoting César's words or pasted decision. Never change the text of an existing approved version.
- Emails: `App\Services\Mail\ApprovedMail` (7 keys). `mail_contract_ready` and `mail_zelle_notice_pending` are **removed**; never bring them back.
- Old signatures and consent records keep the exact text that was signed. Never rewrite stored evidence.

## Applying César's "Mesa de aprobación" paste

The paste lists items by id as APROBADO, PEDIR CAMBIO (with a note) or SIN REVISAR. Follow section 4 of `docs/plans/CODEX-AUTONOMY-UNTIL-SUNDAY.md`:
- APROBADO and already in code → only log it.
- APROBADO and new → add to a new addendum version; read it from there when that is a safe change.
- PEDIR CAMBIO → apply his note exactly, keep ES and EN saying the same thing, log it as `TEXT` so he approves the new wording later.
- SIN REVISAR → do nothing.

## Wording rules

- Every client text exists in Spanish and English, with the same meaning and the same facts.
- Spanish: "costo de transporte". English: "travel fee". Spanish uses "tú", warm and short.
- Money: `$1,234.50`. Spanish dates like "sábado 21 de noviembre"; English "Saturday, November 21".
- Card payments mention the 3% processing fee before paying; Zelle has no fee.
- No marketing in transactional messages (the SMS consent says "No enviamos publicidad").
- Never invent prices, discounts, durations, deadlines, policies or availability. If a text needs a fact you do not have, ask César in plain Spanish.
- Placeholders use `{first_name}`, `{event_date}`, `{link}`, … and must never reach a client unfilled; when a name is missing use the neutral greeting ("¡Hola!").
- Example data in tests and previews: María González, Sofía Méndez. Never real clients, never "PRUEBA".

## Before pushing

Render the text in both languages (test assertion or screenshot) and paste the final ES and EN strings into the `docs/CLAUDE-REVIEW-QUEUE.md` entry, kind `TEXT`.
