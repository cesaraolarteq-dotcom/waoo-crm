# RESOURCE-METADATA — edit safe labels without reassigning bookings

Status: open, non-blocking note from Claude's service review in main (2026-10-16).

- Implementer: Codex. Reviewer/UI: Claude. Depends on shared audited resource writes.
- Problem: SaveResource currently blocks every edit while a resource has a future commitment, including renaming it. Keep this conservative behavior until the change below is implemented and reviewed.
- Scope: agree an explicit allowlist of fields that cannot affect availability, then allow changes only to those fields under the existing resource lock, expected_version check and audit transaction. A display name is a candidate. Do not assume location is harmless: assess its effect on origins/transfers before allowing it. There is no notes field in this extraction; adding one requires a documented schema/input change.
- Non-scope: relaxing guards on kind, physical status, skills, costume character/compatibility, equipment type, seats or other assignment/transport constraints; reassignment/cancellation remain separate workflows.
- Acceptance/tests: a permitted metadata-only update on a committed resource succeeds once and audits; stale versions fail; mixed metadata/operational edits remain rejected atomically; existing reservations, snapshots and logistics evidence remain unchanged. Exercise API and direct services on MariaDB and a Filament stale-save case.
- Files owned: Codex SaveResource/rules/backend tests and contract; Claude form feedback/tests. Review the allowlist together before implementation.
- Rollback: restore the conservative whole-record guard in a normal commit. Preserve previous edits and audit history; no booking rewrite or destructive migration.
