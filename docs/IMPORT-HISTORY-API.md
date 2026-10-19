# IMPORT-HISTORY — archive API proposal v1

2026-10-19 · Codex · design published before implementation; review requested from Claude. **Private intake A implemented in35a4b8b; no archive/customer domain commit or real import.** Owner prioritized read-only migration preparation after C2/C3 A; remaining archive implementation is delivery B. Source: docs/tickets/IMPORT-HISTORY.md and ANNIVERSARY-REBOOK.md. No credential/connector needed in the CRM.

## Archive boundary

### Delivery A — private intake and review (2026-10-19)

Owner now requests advancing all available HoneyBook information without modifying HoneyBook. Implement first **stage/read/row/discard only**, on `codex/history-intake-a`; customer matching/creation, historical domain commits, exclusions, close, corrections and anniversary reads remain unavailable until delivery B. No historical record is counted imported by this slice. Reuse the existing importer singleton; no source-project identity is claimed during staging. The later operating/archive duplicate barrier must be implemented on both domain commit paths before enabling archive commit.

The fixed19-column profile below is named `honeybook-history-v1`; it is not the24-column future profile. Maximum5MiB/2000rows/32768bytes per cell, strict UTF-8/comma CSV. Store the original bytes encrypted **in the batch database row** rather than a filesystem reference, plus encrypted original/normalized row envelopes. Do not persist original filename. No download/export route. Duplicate source IDs remain explicit review issues on every duplicate row. No status/type/money inference: source status needs confirmation later, event type stays unknown unless a declared enum, missing currency stays unknown. Date-only today is not past; partial/invalid/ambiguous/nonexistent times remain issues, never guessed instants.

Existing service signatures/routes for stage, read, row and discard remain as proposed. Stage multipart `{file}` + Idempotency-Key returns `{batch_id,version,state,profile}`. Exact same active file reuses the batch across owner requests; receipt replay authorizes the owner again and returns current metadata, not customer content. A discarded batch returns410 on old stage replay; a new explicit stage key may create a new batch. Changed body under the same key409. Read accepts only `{page?,per_page?}` (1..50), clamps page to last and returns the proposed batch/page/rows/counts fields plus `coverage_sha256`, `commit_available:false`; `customer_match.state=not_evaluated` and `candidate_count=null`, never a fabricated match. `counts.new/unchanged/changed/ambiguous/operating_linked/imported/excluded` remain0 until domain classification exists; `invalid/future/missing_email/cancelled` are overlapping source observations, not an exhaustive partition.

Selected row read returns `{id,version,state,issue_codes,preview_sha256,source,normalized,customer_match,commit_available:false}`. Source contains only this profile's19 original fields; no arbitrary metadata. Owner-only text must be escaped by UI. `normalized` preserves date precision, optional exact USD cents, currency, proposed status/type and source-dated times with UTC only if unambiguous and valid. Read responses verify encrypted envelope/checksum binding and fail with a generic409 on corruption; ciphertext never serializes. Batch counts/coverage derive from a protected manifest, not an editable aggregate blob.

Discard input `{expected_batch_version,expected_coverage_sha256,owner_reason}` + UUID. Reason5..1000chars stays encrypted in the discarded batch's protected metadata. Under actor → existing importer singleton → batch locks, require displayed version/hash and no domain commits. Atomically clear encrypted raw artifact and all staged row envelopes, clear active fingerprint, increment version, mark discarded and record a metadata-only audit+receipt. Return `{batch_id,version,state:discarded}` only after null artifact/zero rows are verified; rollback all on failure. Exact-key retry returns that result, changed key/body409 as appropriate. Deletion means active database contents removed, **not erasure from backups/storage remnants**. No external files mean no asynchronous cleanup_pending step in this slice. Discarded reads410. Populated down migration refuses deletion; teardown must explicitly remove synthetic staging records. Production denied even if flag set; staging HTTPS+secure session required, owner/2FA/CSRF/no-store as below.

Use dedicated `historical_events`, not operating `events`. History creates/links customers only through an explicit reviewed row commit. No reservations, capacity, financial revisions, booking ledger, grants, signature evidence, mail occurrences or outbound messages. Totals are historical source evidence, never verified receipts, opening agreements, a new sale or a trigger for collection. IMPORT-01 future projects and IMPORT-HISTORY must share a source-project identity registry/lock: the same project cannot become both an operating party and a duplicate archive. Known operating projects produce `operating_project_exists`, with a reference for owner review; they do not create history.

Aggregate source counts are observations, not acceptance fixtures: the ticket states262 projects,242 past and18 future (these add to260). Unknown/status/date exclusions must be counted, not guessed into two invented records. All tests and committed fixtures are synthetic. Do not read or import the owner's file to implement this proposal.

## Proposed source profile

One UTF-8 CSV, exact versioned header `honeybook-history-v1`:

```text
project_id,contact_id,client_name,client_email,client_phone,event_date,start_time,end_time,end_date,postal_code,city,project_name,child_name,lead_source,booked_total,currency,project_status,archive_reason,event_type
```

This is a **proposed profile for Claude's local export**, distinct from IMPORT-01's fixed24 columns. No silent autodetection or reinterpretation of that format. Required nonblank: project_id and event_date. Other columns may be empty, including money. Missing source name never becomes a fake customer name. Preserve original source fields encrypted alongside normalized fields and a canonical digest; blank means unknown, never zero. Dates ISO YYYY-MM-DD, times HH:mm optional, Houston by definition. end_date is optional; if absent and both times supplied, end earlier than start requires review (no silently assumed overnight party). Date-only parties remain date-only; no invented noon/midnight or UTC instant. For actual times, reject nonexistent spring times and require explicit owner CDT/CST selection for ambiguous fall times. Store chosen UTC instant, timezone and original precision separately. Future/today-unsafely-past rows stay review/excluded; date-only today is not proof the party ended.

`booked_total` is a decimal USD source amount, not cents guessed from text. Preserve unparseable/original text with an issue rather than invent a number. Non-USD source amounts stay historical-only with original currency; no conversion or summed USD total. Unknown status/event_type never defaults to completed/birthday. Map statuses only from a published mapping plus owner confirmation: completed, cancelled, archived_unknown. Source `archived` alone does not prove completed. event_type is birthday/baby_shower/gender_reveal/corporate/other/unknown; the last is never anniversary eligible.

CSV values are untrusted data. Reject duplicate project IDs within the same file for review, malformed/duplicate headers, oversized files/rows, invalid UTF-8 and extra columns. No formula evaluation, code or instructions from cells. Owner reads use escaped text. Return field/reason codes and row numbers in validation errors, not raw contact contents, filesystem paths or exception SQL bindings.

## Private storage and records

Proposed tables (names frozen only after review):

- `history_import_batches`: integer id, profile/version, digest, encrypted private artifact reference, byte/row counts, actor, version, state staged/reviewing/cleanup_pending/closed/discarded, staged_at, cutoff, aggregate coverage. Private encrypted staging, deletion verified at close/discard; retry cleanup without duplicating commits. No raw file hash/path/token in public error responses.
- `history_import_rows`: batch/id, source identity digest, encrypted raw/normalized payload, preview digest/version, issue codes, decision/import revision id. Date/status/type classification allowed as indexed non-contact fields. No raw names, email, phone or notes in issue JSON/audit.
- `historical_events`: id, source_system=honeybook, unique project identity digest, nullable customer_id, immutable current-revision FK, version. New rows require a known or explicitly created customer; unlinked records remain staged, not phantom customers.
- `historical_event_revisions`: event id/version, encrypted source snapshot and normalized archive facts, source/content hashes, correction reason/actor, chronology metadata and previous revision id. Append-only; current head moves atomically. Source content changes require a diff and an explicit owner decision. Source date/category/money corrections do not mutate operating parties or old report snapshots.
- `external_customer_links`: unique(source_system,contact identity digest), customer FK, version and provenance. Source identity/envelope encrypted; audit internal IDs/hash/version only. No automatic historical reassignment when a contact is edited.

Use authenticated encryption/envelope identity checks like staff private payloads; raw artifacts must also be encrypted **before** private storage (private local visibility alone is not encryption). Model serialization hides ciphertext/source blobs and never exports history. Keys stay in secure environment configuration, never fixtures/logs. Customer currently stores ordinary CRM fields without model-level encryption; **do not claim this API encrypts the existing whole customer table**. Before real imports, document actual database/backup encryption and agree the shared customer PII adapter if column encryption is required. New archive payloads are encrypted regardless; existing customer reads/search must not be silently broken by a history migration.

## Service/API contract for Claude

Namespace `App\Services\History`; stage/read/row/discard classes exist in delivery A. The other signatures remain proposed and unavailable. Owner/2FA, local/testing/staging sandbox flag `waoo.sandbox_history`, staging HTTPS, CSRF/origin, private no-store, bounded pagination. Idempotency-Key UUID required for every write; expected versions/hash are from the displayed read, never substituted with fresh values during submit.

```php
StageHistoricalImport::execute(User $actor, UploadedFile $file, string $key): array
ReadHistoricalImport::execute(User $actor, int $batchId, array $filters = []): array
ReadHistoricalRow::execute(User $actor, int $batchId, int $rowId): array
CommitHistoricalRow::execute(User $actor, int $batchId, int $rowId, array $input, string $key): array
ExcludeHistoricalRow::execute(User $actor, int $batchId, int $rowId, array $input, string $key): array
CloseHistoricalImport::execute(User $actor, int $batchId, array $input, string $key): array
DiscardHistoricalImport::execute(User $actor, int $batchId, array $input, string $key): array
ReadCustomerHistory::execute(User $actor, int $customerId, array $filters = []): array
CorrectHistoricalEvent::execute(User $actor, int $historyId, array $input, string $key): array
```

Routes under `/admin/api/history/imports`: POST root(stage), GET `/{batch}`, GET `/{batch}/rows/{row}`, POST `/{batch}/rows/{row}/commit`, POST `/{batch}/rows/{row}/exclude`, POST `/{batch}/close`, POST `/{batch}/discard`. Customer read GET `/admin/api/customers/{customer}/history`; correction POST `/admin/api/history/events/{history}/corrections`. No download/export endpoint. No client/staff portal routes.

Batch read returns `{batch:{id,version,state,profile,cutoff},counts:{rows,new,unchanged,changed,ambiguous,invalid,future,operating_linked,excluded,imported,missing_email,cancelled},page:{number,last,total},rows:[{id,version,state,issue_codes,preview_sha256,customer_match:{state,candidate_count},source_date,status,event_type}]}`. Aggregate counts contain no contact samples. Selected owner row/detail read separately returns only whitelisted decrypted contact/date/project/diff fields and customer candidates `{id,version,name,email,phone}`; owner access does not permit arbitrary raw payload/path download. Read-only paging is clamped; changing filters resets page1.

Commit input closed schema: `{expected_batch_version,expected_row_version,expected_preview_sha256,expected_history_version?,expected_revision_id?,customer:{mode:link,id,expected_version}|{mode:create,name,email?,phone?,locale},source_status_confirmation?,event_type_confirmation?,time_resolution?,owner_note?}`. Explicit customer identity, including an unambiguous suggestion; no client-controlled source project id. `mode:create` requires a real owner/source name and locale; recheck no new collision under the lock. Confirmations are declared enums and CDT/CST choices for the displayed date, never arbitrary source amount rewrites. Changed-source commit requires diff hash/old history version and owner note; unchanged source is no-op even under a fresh key. Corrections use a separate append-only action, not edited CSV facts misrepresented as original evidence.

Result `{state:imported|unchanged,history_id,history_version,revision_id,customer_id,batch_version,row_version}`. Exclusion pins batch/row/hash plus mandatory owner reason and is visible in coverage; never label excluded as imported. Close pins displayed batch_version plus reviewed coverage digest; every row must be imported/unchanged or explicitly excluded. Return actual closed/cleanup_pending state, with safe same-key retry. `cleanup_pending` is not "file deleted". Same scope/key plus changed body =>409, never a new customer or revision. Audit failure rolls back row/head/customer link/receipt together.

## Matching and concurrency

Suggest by HoneyBook contact link first, then exact normalized email, then validated phone. Normalize email for comparison only (trim/case), preserve original display; no fuzzy names, Gmail dot/plus removal or inferred country for ambiguous phones. Phones use the existing conservative NANP/+international policy. All matches at a tier are considered: more than one or contradictory linked identities => explicit owner review. A missing/invalid phone/email is not a dedupe key. Selecting a CRM customer never overwrites their name/contact/locale. Matching does not grant promotional consent or reverse suppression.

Serialize with the existing importer membership/source identity protocol, not a new unrelated mutex. Use one published lock order (import singleton → batch/rows → sorted customer/source identity rows → history/head → exact request receipt) and **current locking reads**, including idempotency lookup under MariaDB REPEATABLE READ. Revalidate candidate versions, current source project registry and canonical content hash at commit. Different batches with the same source project cannot create duplicate history, operating history or customer links. Known customer conflicts return409; they do not silently attach another contact. Delete cleanup happens after committed state, never deletes historical evidence or unrelated private files.

## Anniversary seam — no mail side effect

Expose a separate internal candidate read only after import review: completed + explicitly birthday + reliable Houston source date + active linked customer, not cancelled/unknown, not owner do-not-contact and not suppressed/unsubscribed. This is a potential anchor, not permission to send. ANNIV-01 rechecks the **latest** qualifying anchor, any later booking/pending-deposit party (including current operating imports), no email/unsubscribe, dedupe and actual send time. Import never creates a promotion/code, backfills missed sends or resets opt-in. Existing account history cannot be used to infer the child's birthday, exact birth date or permission for SMS.

## Verification before implementation approval

Synthetic tests must cover duplicate contacts at each tier, source-contact contradiction, customer version conflict/no overwrite, same and changed source idempotency, known operating project exclusion, concurrent batches/atomic rollback, encrypted artifact+payload/tamper refusal, no PII in errors/audit/logs, verified cleanup retry, no capacity/finance/ledger/grant/mail effects, date-only/overnight/CDT-CST/nonexistent dates, null vs0 money, cancellation/unknown category and suppression exclusions. Real aggregate source counts are not fixtures. No real import, provider connection or sends authorized by this document.
