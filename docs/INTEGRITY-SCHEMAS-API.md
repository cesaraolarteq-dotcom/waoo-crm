# N1 — frozen versioned integrity projections and compatible TIP migration

2026-10-20 · Codex · Contract before code, on codex/integrity-schemas over Claude afa3019. No activation/sending/provider/source operation.

## Compatibility policy

Introduce `App\Services\Integrity\EvidenceV1`, an explicit version1 projection registry. Each named profile has a fixed field list, scalar/JSON/date conversion and fixed relation membership. It reads persisted raw originals for the named fields, never hashes a complete model serialization or the model's dynamic casts/accessors/appends/hidden fields. Collections retain caller's ordered current-read order. Required loaded fields or required relation absent => safe409; unsupported profile/version is refused. Added unrelated columns/relations/appends cannot silently enter a proof. Meaningful existing values still participate; omitted/new security-relevant semantics require a future explicit schema version and deliberate migration, never editing V1 field lists.

**V1 deliberately reproduces the existing bytes/hash on today's persisted schema.** Existing C3 review/preparation/binding/allocation envelopes retain schema_version1 and their original hashes, tokens, amounts, authors, times and expiry. No reseal/update, alias-acceptance fallback, grace period or integrity bypass. Canonical dates reproduce legacy UTC ISO microsecond-Z for model arrays; raw audit and native-finance legacy representations remain raw, with explicitly named columns. JSON snapshots remain complete original JSON values (including semantically relevant keys); fixing field lists does not strip service requirements, assignment evidence or money. Existing mutations/corruption must still fail.

## Profiles and consumers

- `financial`: every currently persisted FinancialRevision V1 field (IDs/revision/version, immutable line/discount snapshots and components, currency, due, creator/timestamps), typed as old model-array serialization. EffectiveOpeningAgreement uses its hash; future materialization must use the same profile.
- `financial_raw`: the SAME named columns in their persisted raw representation, reproducing native TIP's old financial getAttributes component exactly. Native event ID/version/customer and UTC start/end components remain unchanged. ReadConfirmedAgreement and existing native TIP fingerprints remain byte-compatible.
- `service`, with only its explicitly loaded `characters` relation; `character_selection`, `reservation`, `booking`, `operational_review`: fixed current fields and JSON/date forms. Used by CutoverMember, OpeningAgreementFacts, OpeningCollectionBinding and C2 selection evidence. No arbitrary eager-loaded relation enters integrity.
- `resource`: only the existing seven selected resource policy fields; `block`: existing four selected block fields. Do not widen the old trust boundary or make display-only names part of capacity.
- `customer`: existing id/version/name/phone/email/locale binding. `event`: existing reviewed operational event shape. `portal`: existing selected collection-grant facts. Preserve original exact schema rather than hashing arbitrary ORM attributes.
- `ledger`: current LedgerEntry fields, typed; `audit`: explicit original audit columns in their raw SQL representation (before/after JSON strings remain exact stored evidence). Receipt proof hashes these fixed shapes; allocation payload/hash still immutable. Verification audit's required semantic fields are unchanged.

The implementation lists each column/type literally. New migrations/casts cannot change the V1 registry implicitly. Raw SQL-selected collection state already has explicit column lists and remains unchanged. Audit recording itself may retain its original payload; only the fixed audit-row projection participates in the receipt's persisted proof.

## TIP marker migration490000

Add `tip_grants.snapshot_schema_version` unsigned small integer default1. Existing grants receive1 without touching snapshot_sha256/token/encrypted_token/version/expiry/receipt data. New grants also use1; callers cannot select another version. TipGuard refuses unknown schema versions before source use/reveal/checkout. V1's frozen raw-finance profile preserves the old native hash exactly, so no token rotation/reissue or snapshot rewrite is required. Source TIP keeps its existing domain-separated source hash and also uses supported schema1.

The marker is not a change to the legacy encrypted-token envelope; version1 is the only accepted interpretation. Future versions need their own explicit contract, authenticated binding policy and migration. Down may remove the marker only while all rows remain version1; it refuses unsupported future-version evidence, never deletes grants. Existing v1 hashes remain readable by the old implementation if both code/schema are rolled back coherently.

No owner/client API input/response changes, ledger changes, new fees/deposit, original-source movement, grant renewal or activation writer. UI needs no new controls. N3 nullable due consumers and N4 reference handling follow after N1 verification.

## Required verification

1. Current legacy representations equal V1 canonical hashes for finance/raw TIP, services/characters, capacity/review and verified receipt/audit (not a fallback in production).
2. A prepared group/effective synthetic binding/allocation and a legacy native TIP link stay valid after real unrelated nullable/default columns are added to the disposable schema. Current security-relevant value changes still invalidate proof.
3. Cast/accessor/appended/extra relation changes do not alter V1; missing required loaded values/relations and unknown TIP versions refuse.
4. TIP migration preserves token/hash/expiry and supports coherent V1 roundtrip; stale/expired/revoked/changed agreement remains denied. Existing grant issue/reveal replay semantics unchanged.
5. Regression of C2/C3 preparation/receipts, original client portal, native/source TIP and new Claude history/cutover UI. MariaDB only one runner at a time; fictional realistic names, no real source files/accounts.
