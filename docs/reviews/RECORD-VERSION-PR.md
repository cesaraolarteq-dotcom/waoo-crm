# Prevent silent concurrent customer and character overwrites

> Integration update (2026-10-16): these source changes are approved and consolidated on `codex/integrate-42-43-fixes`. Open the single PR into `claude/quick-details-ui` using [INTEGRATE-42-43-FIXES-PR.md](INTEGRATE-42-43-FIXES-PR.md). The original description below is retained for context.

Base: `codex/portal-review-fixes`; head: `codex/record-versions`. Interface-first commit 8f8f480; implementation 1d7d76f; explicit CI SQLite driver 5c0295b. Review after the parent portal corrections. Keep migration, services, approval caller and edit-page adapters together.

When both owners edited the same customer or character, the second save could silently replace the first. Both records now have a version starting at 1. Updates through the audited services/API require the displayed expected_version and compare it under the row lock; a successful save increments once, while a stale request returns 409 without another write or audit. The customer/character editors retain the displayed version in Locked Livewire state, preserve unsaved fields on conflict and advance the version after a successful save. Repeated edits from one editor still work.

The additive 220000 migration backfills existing rows with version 1. Model reads expose integer versions; browser-supplied raw version cannot set them. ApproveClientDetails passes the already-locked customer's version while preserving its submission/base-field guards. Create APIs and QuickBook customer creation do not require an expected version. Existing resource/variant/event guards and owner permissions stay in force.

Contract: docs/RECORD-VERSION-API.md. Targeted verification: 25 tests / 235 assertions on MariaDB and the isolated SQLite migration check. Includes two real two-owner process races (one per record type), API/direct-service guards, atomic audit rollback, real Livewire stale saves/input retention, repeated saves and Locked tampering. SQLite verifies existing-row defaults plus up/down/up without deleting records; CI explicitly installs pdo_sqlite for that check. Final full integration evidence is in the newest HANDOFF entry.

Claude reviews the API and minimal form adapters before merge. No deployment or owner acceptance is claimed; code must land with the migration before an environment uses the new forms.
