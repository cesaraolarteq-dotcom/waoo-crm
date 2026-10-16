# Integrate #42/#43 using the approved Codex corrections

Head: `codex/integrate-42-43-fixes`. Target: `claude/quick-details-ui` (PR #42), as requested by the owner/Claude. This replaces the separate #43 path; do not retarget this integration to #43 or use the superseded v2 alternative.

Started at exact `claude/zelle-notices-ui` **2d19000** and merged exact approved `codex/record-versions` **bdc6c9e** with a normal two-parent merge. All code conflicts use Codex's implementation. The application, resources, tests, migrations and CI match bdc6c9e; both HANDOFF histories are retained.

- Requested Houston wall time is resolved on the server, with explicit CDT/CST choice for the repeated hour and rejection of the nonexistent hour.
- Approval displays and submits the immutable submission snapshot captured when the page loads; stale submissions are rejected without writes.
- The real portal displays the exact configured SMS wording/version; it remains disabled with the empty map. The separate design preview stays isolated.
- Zelle verification uses yesterday's fixture and frozen 00:01 Houston coverage, with real received-at validation intact.
- Migration220000, versioned services, ApproveClientDetails's locked customer version and both forms' Locked displayedVersion remain together. PDF equipment labels and SQLite CI dependency are retained.

Removed the competing houston() helper, smsConsent() selector, mountUsing/reviewSubmission* adapter and Claude's three duplicate tests; Codex's normal regression coverage remains. No claude/* branch rewritten. Existing old branches and decisions remain in history; this explicit choice supersedes the previous advice to use record-versions-v2.

Final fresh full-suite counts, build/Composer results and review evidence are in the newest HANDOFF entry. The owner relayed approval of ee706a6/bdc6c9e with green CI; this branch still needs its own PR CI/merge. No deployment, real charge or client message. The owner/Claude opens the PR from this branch as requested.
