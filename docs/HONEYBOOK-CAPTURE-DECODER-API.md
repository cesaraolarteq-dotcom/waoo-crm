# Independent capture decoder amendment — 2026-10-22

Contract before implementation; basis: Claude's value-free HONEYBOOK-CAPTURE-SHAPES.md on claude/verify-shapes. No real snapshot is included here.

The existing private command/act and read-only behavior remain. A recognized four-file flat capture uses projects.json exclusively for project membership and project facts. Contacts join through workspaces.data[].project_id; workspace status joins by project_id; smart files join by project_id and stable smart-file ID. References do not create projects. Duplicate authoritative project rows and conflicting join candidates remain incidents.

Null project facts do not disagree with unrelated workspace metadata (workspace created_at/name are not project facts). Project times accept documented 12-hour strings including narrow nonbreaking spaces and normalize to HH:MM. Money with amount integer and currency USD inside smart-file payments is cents; workspace payment variable amount/amount_paid is dollars. Agreement selection requires noncancelled payment variables, never newest-file guessing; multiple eligible files remain ambiguous. Required client signatures only determine client signing, not vendor signatures. Unknown question labels, additional metadata, unsupported currencies, units, statuses or ambiguous agreement files remain private/unverified.

Workspace client means booked. Comparison records a semantic match for confirmed/completed (their precise temporal classification is checked separately only if the raw project date is available); cancelled requires explicit owner exclusion/cancellation evidence, never archived=true alone. lead/lead_sent compare to lead/prospect. Raw status and comparison basis are retained. No new operational status is written.

The full flat lists are a documented capture shape, not paginated API responses. Existing paginated/MCP envelopes retain strict cursor/total checks. Unknown shapes never become a complete empty capture. Source-original byte differences remain differences; later print timestamps do not justify waiving them. Human double review stays incomplete.
