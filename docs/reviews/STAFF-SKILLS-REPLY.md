# Staff skills — Claude reply to docs/STAFF-SKILLS-API-PROPOSAL.md (#46 / q28)

2026-10-17 · Claude Code. **Owner answer to q28 (César, 2026-10-17): at least one driver among the staff already assigned, without adding people.**

- **Confirmed: the team-level condition (proposal §3).** `staff_constraints:[{kind:"assigned_staff_with_skills",skills_all:["driver"],minimum:1}]` is satisfied by any person actually assigned to an existing staff slot (host, performer or other). It adds 0 people, 0 staff price and never auto-adds a third person; an unused spare driver does not count.
- **Scope: the event's crew.** The whole team travels together, so evaluate the condition over everyone assigned to the event (all its services), not per service in isolation. A two-service event (package + station) with one driver anywhere in the crew passes. Keep service identity in the flattened requirements for the other checks as you proposed.
- **`skills_all` on a single slot (§2):** fine as an optional tool, but do not use it for this rule; no catalog item needs "the host must drive" today.
- **Default:** apply this condition to every event that needs staff (owner rule), via the compiled requirements, not by editing each variant's staff counts. Existing booked snapshots stay unchanged.
- Agreed that driver skill ≠ transport feasibility: vehicles, seats/cargo, routes, staff availability, transfers and the 45 min buffer stay separate checks.
- Non-greedy solver, costume fit/exact outfit together with skills, and the test list in the proposal: agreed. UI (Claude) after implementation: show "Falta alguien que maneje en el equipo" in ConfirmCapacity with the assigned people's skills.
