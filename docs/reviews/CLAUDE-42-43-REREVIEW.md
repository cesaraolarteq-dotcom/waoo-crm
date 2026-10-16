# PR #42 / #43 re-review — approved corrected UI

2026-10-16 · Codex · codex/review-claude-42-43-v2

Exact reviewed heads: **#42 0fae4e5**, source **071749c**; **#43 2d19000**, including #42. Main remains ea41755. Review is against these corrected heads, not the superseded 4ff9d13 / a16166d.

**Verdict: approve the corrected #42 and #43 application/UI changes. No remaining blocking finding in this review.** Repository verdict only: no GitHub API approval or merge was submitted; previously denied write API is unchanged.

- **R1 closed:** mountUsing pins the exact submission ID/revision/event version in three Locked properties; the modal explicitly displays its version/name/venue/ZIP. Confirmation rejects a newer submission, and the service still checks expected versions under lock. The submitted target is not replaced at confirmation. A new review after refresh explicitly displays and approves the newer submission. Capturing on modal mount is acceptable here because the captured version/address is shown inside the modal itself.
- **R2 closed:** the actual inline houston(date,time) helper resolves the requested wall time in America/Chicago, independently of device/original-start offset. Its executed Node regression demonstrates November 03:30 => -06:00, repeated 01:30 => explicit refusal, March skipped 02:30 => refusal and subsequent 03:30 => -05:00. Ambiguous requests ask for another time/owner discussion rather than guessing; no automatic reschedule or API change. This is a valid current solution; Codex's optional server-local-time API extension is not required to merge it.
- **C1 closed, activation still disabled:** the real portal renders the exact configured approved text/version and prefers a language-suffixed version. Stored service evidence uses that same version's configured text/hash. Empty configuration stays hidden; local/testing preview sample remains separate. No legal wording is activated or translated automatically.
- **R3 closed:** actual bank receipt fixture is yesterday at 00:05; real received-at validation and assertions remain. ConfirmCapacityPageTest's subDay fix is retained.

## Evidence

Final complete suite on exact corrected #43: **238 tests / 2183 assertions pass**, PHP 8.3 / MariaDB 11.8; no incomplete/skipped test reported. Focused actual portal/notice/preview suite: 11/231. Vite build and strict Composer validation pass. The PHP container originally lacked Node; the platform-provided Node 24.19.0 is now installed there with source/copy SHA-256 verification, and the real extracted-helper test ran (not marked incomplete). Nine real HTTP startup checks pass. Reusable install/start instructions were saved as an environment draft; snapshot publication and restoration in a fresh task are not claimed.

## Integration order / superseded alternatives

Keep Claude's corrected UI as the base of #42/#43. **Do not blindly merge codex/portal-review-fixes bf2ffac over these fixes:** it is a separately tested alternative for the same R1/R2/C1/R3 cases, now redundant. Its independent PDF equipment-label commit 4acf3a4 remains useful and can be applied alone. No need to carry the alternate consent preview adapter or additive local-time endpoint just to merge these PRs.

Codex will publish customer/character version guards rebased onto this corrected UI on codex/record-versions-v2. Review that coordinated increment (migration/services/ApproveClientDetails caller/forms/CI dependency) after the UI stack. Use v2 rather than merging the old record-versions branch and its alternate parent wholesale. Existing old branches are preserved, not rewritten. All review/merge/owner acceptance states remain separate; no deployment, real charge or client message.
