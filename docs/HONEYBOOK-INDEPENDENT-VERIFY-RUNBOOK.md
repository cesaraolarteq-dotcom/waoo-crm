# Independent verification — operator runbook

Claude runs this on crm-test after reviewing the branch and CI. Codex does not access the real snapshot,
change HoneyBook, import rows, send messages or deploy this delivery.

1. Keep the private raw API capture directory outside the application checkout, public directory and logs.
   Directory mode0700; each raw JSON/PDF file is bounded to32MiB; originals are read one database row at a time. original raw responses in projects.json, contacts-pipeline-files.json,
   workspaces.json and file-responses.json. Do not turn them into the import CSV or use import field mappings.
   Recognized envelopes include structuredContent, named collections, pages/data/result/response wrappers and MCP JSON text
   blocks. Preserve actual pagination/terminal cursor evidence. Incomplete or unknown shapes remain unverified.
2. Optional exclusions.json: `[{"project_id":"synthetic-project","reason":"Owner decision","owner_approved":true}]`.
   The act distinguishes declared exclusions, and flags missing owner attestation and exclusions still present
   in CRM. Do not invent exclusions simply to make counts agree.
3. For independent PDF comparison provide originals.json, an array with project_id, source_reference, kind
   (contract/invoice), private snapshot-relative path and sha256. Keep the actual original PDF bytes there.
   A database hash check alone does not prove source equality. Original references/project association are
   crosschecked against raw file metadata. Do not copy a CRM download back into the source directory and
   call that an independent HoneyBook original.
4. Review and temporarily enable sandbox_history plus WAOO_SANDBOX_HISTORY_VERIFICATION only in the authorized
   sandbox. Choose an existing owner admin with confirmed2FA. Create a separate0700 output directory.
   Run (paths below illustrative, no secrets):

   php artisan waoo:honeybook:verify --owner=OWNER_ID --snapshot=/private/hb-capture --report=/private/hb-acts/codex-act-UNIQUE.json

5. Exit0 = supported fields/coverage verified; exit2 = act saved but review required; exit1 = unable to finish
   safely (format/access/path/existing output). Console contains aggregates only. JSON0600, exclusive-create,
   never overwritten. Inspect field-level source/CRM differences, unresolved paths/metadata and source hashes
   privately. Ambiguous aliases, unknown money units, malformed phones and absent source details must not be
   converted to zeros, guessed values or silently marked matched. If an API envelope needs support, share
   only synthetic/anonymized structural keys with Codex; no real raw payload in HANDOFF/git/logs.
6. Compare independently with Claude's act, using the same source snapshot hashes and consistent CRM state.
   Latest owner-supplied baseline is262 projects=241 history+18 future+3 declared exclusions,515 originals,
   zero unresolved differences in Claude's act. Those are a comparison baseline, **not constants in the code**.
   Do not claim independent agreement until both actual acts have been compared. human_double_review_complete
   deliberately remainsfalse in the technical act.
7. Share only aggregate outcomes in HANDOFF: source/destination counts, duplicate/missing/extra counts,
   different/unverified field counts, source-byte vs internal-hash PDF counts and remaining issue categories.
   Disable the verification/correction flag after the authorized review window if appropriate.

## Corrections

There is no automatic repair mode. Phone-only matches with a different/missing name go to owner review.
The three disputed real cases were later reported correct; **do not move them merely because an older
message called them errors**. César decides each case.

If an owner correction is needed, open the current customer's history and choose “Corregir clienta del
historial”. Pick another existing person or explicitly create one, with a meaningful reason. The form pins
history/revision/hash and target customer version; stale edits are rejected. It changes only that archive
head through a new revision, leaving original source, previous customer evidence and source contact link
intact. Other projects sharing a disputed global contact stop being automatically assigned. No operating
party, original PDF, payment, customer record or ledger is rewritten.
