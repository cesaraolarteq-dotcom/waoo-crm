Title: Reserve optional costume versions and keep owner notes private

Base: codex/route-01 (5ab56f8), after codex/lead-01. API7c8dcb1 published before implementation. No blocked #46/#49 UI code is included; see docs/reviews/CLAUDE-47-46-48-49-44.md for exact heads and requested changes.

An owner can define character version names, tag costume units and request a particular version for each event character. Draft/QuickBook compilation reserves only matching units; generic or wrong versions cannot substitute, and the existing package quantity/price rules stay intact. Character list edits and unit saves serialize on the Character row. Active references prevent removing/renaming a version; booked resource editing and signed-event guards remain.

Same character in different versions occupies separate selection rows. The additive migration keeps legacy nulls, supports both MariaDB and SQLite and refuses rollback before DDL when the former character-only unique would collapse history. Preserve the existing140000 down() index-order fix.

Financial/contract snapshots contain the version, never the private owner note. The PDF print context, portal JSON and page display the owner's exact version text; client-detail approval keeps and compares both version and private note without changing money. The portal page adds only an escaped bilingual character summary; Claude owns remaining admin forms/labels and explicit character row mappers.

See the newest HANDOFF entry for final integrated test results, source commit and local migration verification. Tests use fictional inventory, real independent MariaDB workers for competing reservation/catalog edits and fake providers only. Remaining: Claude UI/mappers, actual owner version quantities, independent review/CI/main merge and acceptance. ROUTE real22 calibration still pending; synthetic formula tests do not substitute. No live integration, provider call, customer send, charge or deployment.
