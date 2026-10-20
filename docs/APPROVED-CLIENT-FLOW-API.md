# Approved client flow — delivery contract, 2026-10-20

Owner source: docs/templates/APPROVED-CLIENT-TEXTS-2026-10-20.json, PR103 evening handoff. Codex temporarily owns backend and screens. No deployment, outbound send or charge authorized.

## A: approved copy and retirement
Runtime snapshot in config/client-texts.php must exactly equal the approved JSON; no runtime docs dependency. waoo quote consent uses version quote-consent-v3-2026-10-20, with outcome-specific ES/EN wording. SMS uses distinct client-texts-2026-10-20-sms-es/en versions and the exact selected language text. No provider registration/transport activation.

Signature consent v2 has separate -es/-en version IDs. Existing input fields and booleans stay; consent_version pins both approved checkbox and intent wording, stored together separated by one newline with their SHA256. Portal shows both exact clauses in the selected language. New signatures reject obsolete v1; exact existing idempotent requests may still return their original immutable evidence. Existing evidence/PDFs are never rewritten.

Mail definitions/preview use the seven approved mail_* entries. contract_ready and zelle_notice_pending are retired: no new occurrence, no new rendering/planning, and dispatch of any old queued message fails closed. Historical records remain readable. Inquiry uses required zip and the exact HTTPS Waoo travel-fee link; only that approved inline link is rendered as HTML, every other value is escaped. Approved thank-you retains both buttons; missing valid tip link fails closed rather than silently omitting approved content. This does not implement automatic tip-link issuance.

Approved texts are separate from transport permission. Mail/SMS remain off; settings show approved wording with example data, not a false sent/active claim. Tests cover exact runtime/text source parity, two languages, XSS/URL/subject handling, removed mail suppression and actual new signature evidence plus old record preservation.

## B: same-visit details then signature
Implementation contract to be frozen after examining existing submission/contract/approval paths. No change to agreed date/address/price, no new owner approval gate between data and signature, no fake owner review, immutable submitted identity and contract snapshot, exact presented PDF must still be opened. Native CRM only; HoneyBook source restrictions remain. Existing owner submission review stays available for follow-up, not represented as already performed.
