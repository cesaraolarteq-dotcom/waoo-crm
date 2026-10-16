# LEAD-01 — website intake and owner lead workflow

Contract published before backend code. Source ticket docs/tickets/LEAD-01.md. Claude owns website/spool and screens; Codex intake/data/conversion. Sandbox local/testing/staging only; new WAOO_SANDBOX_LEAD_INTAKE=false by default. WAOO_LEAD_SHARED_SECRET must be32–512 bytes, configured securely, never in chat/git. Production/live activation remains separately approved. No email/SMS, event/customer or fee creation on intake.

## Website server → CRM

POST `/api/leads` (HTTPS required in staging), stateless API middleware (no session/cookies/CSRF). Content-Type application/json, exact body ≤65536 bytes,120 requests/min per source IP (429 → retry). Headers:

- Idempotency-Key: canonical lowercase UUIDv4, unique per genuine submission, unchanged on retry.
- X-Waoo-Timestamp: Unix seconds decimal, within±300s of CRM clock.
- X-Waoo-Signature: `sha256=` + lowercase hex HMAC-SHA256 of **timestamp + "\n" + idempotencyKey + "\n" + exactRawJsonBody**, using the shared secret as its raw string value.

**Security correction to ticket:** signing body alone does not authenticate timestamp/key; a captured body signature could be replayed with a fresh date/key. All three must be bound together. PHP: `hash_hmac('sha256', $timestamp."\n".$key."\n".$body, $secret)`. Retry/spool keeps original key + exact raw body, but generates fresh timestamp/signature; submitted_at stays original. Email fallback still happens first on the website; CRM never triggers that email. No credentials/headers/body in error logs.

Allowed payload keys only (unknown root/consent keys422): name required string≤255, phone required≤40, email required valid≤255; event_date real YYYY-MM-DD (2000–2100, past allowed for delayed spool), start_time nullable HH:mm Houston (no UTC inference on DST ambiguity), zip five digits or ZIP+4, event_type/package required opaque website codes≤100; message nullable≤4000, travel_estimate_text nullable≤1000; locale en|es; demand_flag open|filling|full|unknown; submitted_at required ISO8601 with explicit offset, no more than5min future; page exactly /contact; consent present nullable, or `{text: nonempty string≤4000, version: nonempty string≤120}`. Consent text is stored verbatim as website evidence; **does not enroll/opt-in a customer or authorize messaging**. No browser honeypot submission should be posted; website drops it before this call. Travel estimate is informational text only.

202 `{accepted:true}` for first receipt and identical retry; no lead/customer IDs or PII returned. Same key/different exact body409. Invalid/missing/stale auth401, payload422, wrong media415, too large413, disabled/missing secret/production/persistence failure503; insecure staging403. All responses private/no-store. No public read/search endpoint. One immutable lead_submissions (request UUID/body hash/validated original payload/submitted+received timestamps) and one leads row per distinct key; one system audit without payload. Concurrent retries create only one of each. Different keys are separate prospects, never automatically merged.

## Owner API and services

Owner+2FA, feature gate and private/no-store on every route/service; both admins equal. Services are in App\Services\Leads: ReceiveWebsiteLead::execute(string body,string timestamp,string key,string signature): Lead; ReadLeads::index(User,array) and show(User,int); UpdateLead and ConvertLead below. All website text is untrusted: escape it in owner screens, including message, consent and duplicate-hint names.

- GET `/admin/api/leads?status=new&page=1&per_page=25` (per_page1–50). Laravel paginator; each row has lead metadata, immutable submission and `duplicate_hints` [{customer_id,name,matched_by:[email|phone]}], capped20. Comparison: trim/lower email; phone digits with US leading1 removed for11-digit numbers (minimum7 digits to hint); hint only, never merge. Batch-load candidate customer data once per page.
- GET `/admin/api/leads/{lead}` → same owner row. No public payload leakage.
- PATCH `/admin/api/leads/{lead}` → `UpdateLead::execute(User,int,array): Lead`. expected_version required integer≥1; status optional new|contacted|quoted|lost; owner_note optional nullable≤2000; at least one change field. Row-lock/version guard409; audited change increments once. booked cannot be set manually. Once booked, status changes reject but note-only edits remain allowed. Claude captures Locked displayedVersion; missing/stale versions never overwrite.
- POST `/admin/api/leads/{lead}/quick-book` with Idempotency-Key UUID and `{expected_version,booking:{...existing BOOK-QUICK-API payload...}}` → `ConvertLead::execute(User,int,array,string): QuickBooking`. Owner explicitly selects existing/new customer and validates price/time/fee/deposit; do not auto-trust website package/time/transport text or duplicate hints. new/contacted/quoted only; lost must be reopened first. booked is set only with a successful real QuickBook core transaction, linking quick_booking_id/event_id and incrementing lead version. Same owner/key/payload retry returns existing booking and never reissues a one-time portal URL; different request against booked lead409. Two owners cannot convert twice.

QuickBook will accept optional paired lead_id/lead_expected_version, check/lock lead inside its existing core transaction after the owner retry lock and mutation-key lookup, then save lead linkage/audit alongside customer/event/finance/receipt/quick-book record. **Do not wrap its post-core portal-link recovery in a new UI transaction**. Existing non-lead bookings and later link_issue_pending handling remain unchanged. Intake never calls QuickBook. These fields cannot bypass the lead feature gate.

## Review prerequisite completed

Reviewed #47→#46→#48→#49→#44 exact heads in docs/reviews/CLAUDE-47-46-48-49-44.md; published codex/review-47-46-48-49-44. #46/#49 need changes; others approved as documented. LEAD starts independently from main7038f30, not from blocked UI integration. Then ROUTE-01, then OUTFIT-01. Screens/spool, real website outage fallback, shared-secret provisioning and live activation remain Claude/owner integration work, not claimed by backend tests.
