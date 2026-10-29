# Signed final contract presentation

Source: César's 2026-10-28 decisions recorded by Claude in PR142/HANDOFF:
client signature on the client line, provider acceptance at the client's signature,
SIGNED / FIRMADO badge, existing certificate retained. No legal clauses or frozen
client-text registry changes. Existing final artifacts are never regenerated.

FinalizeSignedContract verifies the presented HTML/PDF and immutable template
hashes. It must reproduce the unsigned HTML from that exact template version and
frozen commercial snapshot and match the originally presented HTML hash before
rendering the signed view. A changed template/snapshot fails closed; there is no
fallback to another template, current event data or altered terms.

New finalization renders the same snapshot/template with ContractPrintContext's
signed context, showing the typed client signature/date, provider date, bilingual
badge and signature evidence block. It appends the certificate with the owner's
approved provider-acceptance statement. This records owner-authorized acceptance;
it is not a claim of a second human/cryptographic signature or email delivery.
Unsigned source, presented artifacts, signature evidence and all their hashes stay
unchanged. The final PDF has its own existing content hash and private artifact.

No re-finalization command or bulk data changes in this delivery. Previously
finalized PDFs remain byte-identical; a future original that cannot be reproduced
requires review rather than silently replacing signed evidence. Sending stays held.
