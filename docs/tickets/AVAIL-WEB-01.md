# AVAIL-WEB-01 · Website availability from the CRM (Phase 3)

Status: contract proposal by Claude (website) for Codex (CRM endpoint). Replaces the website's iCal-based `availability.php` signal once the CRM is the source of truth; until then both run and the website falls back to iCal.

## Published API contract (2026-10-22)

See [AVAIL-WEB-01-API.md](../AVAIL-WEB-01-API.md): authenticated server-to-server day signal plus candidate-time check, strict public projection, owner-approved14day review rule and actual physical/resource/logistics constraints. **Documentation only; no endpoints, website connection or activation delivered.** Legacy iCal fallback cannot manufacture verified CRM slots. Closing-time details and the precise lead-time boundary remain implementation review items.

## Today
`availability.php?date=YYYY-MM-DD[&alts=1]` returns only `open|filling|full|unknown` + up to 3 nearby open dates, from the HoneyBook→Google Calendar iCal (counts events; next 8 Saturdays/Sundays always “filling”). It never exposes event details. The form never blocks a request.

## CRM endpoint (Codex)
- `GET /api/availability?date=…&zip=…&service=…` (public, rate-limited, cached ~5 min, no PII): returns `level` (open|filling|full|unknown) computed from real capacity: staff/costumes/equipment free windows for that date considering setup/breakdown and 45-min margins, optionally for the requested service. Never returns names, addresses or counts beyond the level; `alts` = up to 3 nearby dates with level open.
- Weekend demand wording rule kept (owner decision: next 8 Saturdays/Sundays show “filling” with the honest copy “weekends are our most requested dates”).
- Signed server-to-server like LEAD-01 or public with strict output; Codex to choose and document.

## Website (Claude)
`availability.php` asks the CRM first (2 s timeout) and falls back to the current iCal logic; same JSON to the browser so the form needs no change. Feature flag in the website config (Hostinger only). `send-quote.php` keeps the high-demand flag from whichever source answered.

## Tests (minimum)
Level matches capacity fixtures (free, partially booked, fully booked by staff vs by costume); no PII in responses; CRM down → website iCal fallback; DST dates.
