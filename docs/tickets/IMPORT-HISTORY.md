# IMPORT-HISTORY — HoneyBook client and past-party archive (Phase 8, needed earlier by ANNIV-01)

**Why now:** the "Un año después" email (ANNIV-01) and recognizing returning clients need past parties. HoneyBook (read-only, 2026-10-19, aggregates only): **262 clients, 262 projects: 242 past, 18 future** (dates 2025-2027). IMPORT-01 already covers the future ones.

**Source:** Claude reads the owner's HoneyBook through the owner-authorized read-only connector and writes a **local file outside git** (never logs, HANDOFF or chat rows). No HoneyBook credentials in the CRM. CSV export from HoneyBook is an equivalent fallback.

## Backend for Codex (publish the API before code)
- **Archive-only:** creates/links `customers` and `historical_events` (or `events` with status `historical`): never capacity, reservations, finance revisions, ledger, portal grants, mail plans or contracts. Read-only after import except owner corrections (audited).
- **Fields (all optional except source id + date):** HoneyBook project id (unique source key), client contact id, name, email, phone, party date (Houston), start/end time, location ZIP/city only (no full address needed for history), package/project name text, child name if present in custom fields, lead source label, booked total as **historical text/amount without ledger effect**, project status (completed/cancelled/archived reason).
- **Dedupe:** by HoneyBook contact id first, then exact normalized email, then phone; ambiguous → owner review list, never auto-merge. Existing CRM customers are linked, not overwritten (owner data wins).
- **Idempotent:** same source id + same content = no-op; changed content = new version with diff for owner review.
- **Dry run first** with aggregate report (counts, duplicates, missing emails, cancelled), then explicit owner-approved commit, like IMPORT-01.
- **Privacy:** encrypted at rest like other PII; no export endpoint; reports show counts only.
- **ANNIV-01 hook:** history rows are eligible anchors only if completed (not cancelled) and the client is not unsubscribed.
- **Tests:** idempotency, dedupe ambiguity, no capacity/money side effects, cancelled excluded from anniversaries, DST dates, encrypted fields.

Owner question pending: do the 18 future HoneyBook parties finish in HoneyBook (recommended) or move to the CRM?
