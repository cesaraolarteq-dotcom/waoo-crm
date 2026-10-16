# RESV-A-UNUSED — Explain selected resources beyond required roles

Status: open; non-blocking follow-up from Claude's PR #22 review. Parent: RESV-A-01 / OPS-01. Implementer: Codex (API) + Claude (warning UI); review: the other.

Problem: all selected resources are currently reserved, including extras that the service-role matching does not consume. This is safe against double booking but can reduce availability unnecessarily and conceal a selection mistake.

Scope: define and document a response explaining selected resources not needed by service roles or the reviewed transport/operational plan. Agree the API shape in PHASE1-API before implementation. Account for costume performers, drivers, seats/cargo and intentional backup staff/equipment; a person needed only as a driver is not automatically unused. UI warns and lets the owner correct or explain the selection. Do not silently drop a selected paid commitment or change deposit/availability policy.

Acceptance/tests: a genuinely extra speaker is identified; all resources needed by multiskill roles/costume compatibility are retained; transport-only driver is retained; intentional backup is explicit/audited according to the agreed workflow; retry semantics and transactional conflicts remain unchanged. Decide with Claude whether diagnostics precede confirmation or accompany its response.

Files owned: matching diagnostics/service response, PHASE1-API and tests; Claude owns the selection warning. No new prices or automatic reassignment.

Rollback: remove the diagnostic/UI warning normally; all explicitly selected resources remain conservatively reserved under current behavior.
