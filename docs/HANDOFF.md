## 2026-10-29 · Codex · Signed-contract integration with daily/history main
Merge main edf0832 (#144) into153 has only2documentation conflicts, both histories preserved; no application conflict. Original153head93967c3 all12checksSUCCESS, local48/583PASS. Final assembly CI required. No deployment/old-final rewrite/sends.
## 2026-10-30 · Claude · Visual review of CRM screens + website (branch claude/availability-audit-fixes)
César: "evalúa todas las pantallas del CRM y la web y mejora". Walked locally (fictitious bookings, desktop 1440 and phone 390) and the live website on a phone width.
- Eventos list: a draft with money no longer says "Borrador": `EventResource::listStatus()` → "Depósito recibido" (receipt), "HoneyBook" (imported), "Esperando pago" (open quick payment), else "Borrador".
- Event file: chip "Recursos: Sin apartar" for drafts; balance due date on its own line (it overflowed on phones); empty staff card says resources are set aside with "Apartar recursos y confirmar".
- Reportes: `ReadPanorama` counts a CRM draft with a received deposit as a party/sale (quick booking); before, 9 paid quick bookings showed "Fiestas 0 · Ventas $0" next to "Dinero recibido $2,325". Hottest week ratio rounded ("35 veces tu promedio", was 35.33×).
- Clientes: phone shown as (346) 555-0118 (`Labels::phone`), nameless client "Cliente sin nombre".
- Personajes: column "Quién lo hace" (Cabeza · cualquier Personaje / Peluca · solo con peluca / Sin marcar).
- Website (live, phone): no horizontal scroll, no broken images, form step 1 and floating WhatsApp bar fine; no change needed.
- Tests: EventFile/Customer/Character/Panorama/ReportsPage/Dashboard groups green except the known MariaDB-only ones.

## 2026-10-29 · Claude → Codex · Your availability audit (9b54603) addressed (branch claude/availability-audit-fixes, stacked on claude/anniversary)
Applied your regression patch unchanged (AvailabilityAuditRegressionTest + the QuickPaymentsTest late-payment walk) — all pass now. Before: 3 + 1 failing as you reported.
- **R1** `PortalPageController`: `reserved` also requires no capacity review (`QuickPaymentBooking` `paid_needs_review` or a `QuickCostumeHold` `review_required`). Money and signature are kept; signing in that state is still allowed (approved flow) but never shows "reservada".
- **R2** `WebsiteCapacity::check`: 5+ characters (sum of costume quantities, split or not) → `owner_approval_required`, server side for the website and for `AdmitEventCapacity::state`.
- **R3** vehicles: requirement `kind=vehicle` builds slots (eligible: active vehicles with recorded seats ≥ required); none → candidate `unavailable`; only vehicles with unknown seats → `review_required`. `PoolCapacitySolver` job option `people=false` so the physical pass checks vehicles without the driver/seat bound.
- **R4** wearer: solver slot pairing (`pair` + `pairs` [[costume, staff]...]) — the candidate's costume slot and one performer slot hold an allowed combination from `costume_compatibilities` for the whole job (same rule as ConfirmBooking). None recorded → people pass infeasible → owner approval. Existing parties are facts (not re-proven). **crm-test has 0 compatibilities** (76 costumes, 25 staff): every character start is owner approval until César fills "Quién puede usarlo" (ConfirmBooking needs it too).
- **R5** iCal: names/BEGIN/END case-insensitive (RFC 5545), lower-case RRULE refused, timed dates must round-trip (Nov 31 → unknown). Your proof script now prints null / correct event / null.
- **S2** `TickQuickPayments`: due bookings first close their open card checkouts outside locks; release happens only when no checkout is open/unknown and the booking is not `processing`; Stripe timeout keeps the stock (`provider_close_pending`) and the next tick releases after a confirmed unpaid expiry. Test `test_deadline_keeps_the_stock_while_the_card_checkout_cannot_be_closed_and_releases_it_once_closed`. **P2 cron**: only rows with something due, soonest first.
- **S4** `GoogleCalendar::busyIntervals` → null when not connected or `busy` missing/malformed; `waoo.availability_crm_only` (env `WAOO_AVAILABILITY_CRM_ONLY`, off) is the explicit post-switch mode. **S5**: a committed party with an empty recipe or a pin to an inactive unit is uncertain; a role nobody can fill makes a feasible answer owner approval (never demand 0). **P2 feed**: download streamed and cut at 2 MB.
- **Head / wig (César 2026-10-30):** `characters.costume_style` (`head` | `wig` | null) + `App\Support\CostumeWearers::allows()`: a unit with people in "Quién puede usarlo" → only those; else head → Personaje or Personaje con peluca, wig → only Personaje con peluca; unmarked → nobody (owner). Used by WebsiteCapacity pairing (wearer slot now also matches `performer_wig`) **and ConfirmBooking** (was: recorded rows only). César marked all 70 in his checklist (artifact VE75Uh6wd8aRaYjsKiTCZU: 21 wig, 49 head); load with `php artisan waoo:character-styles <json>`.
- **Not done yet (next):** S1 full joint hold before a website charge (only the 4/10 day-lock gate exists; website payment is off); S3 route proof beyond the window without ZIP (still the ±60 fallback) and ConfirmBooking's max45; calendar dedupe by stable identity (still start±5/end±15 one-to-one); greedy pins (P2); MariaDB concurrency test of the last unit. Preview and payments stay off.
- Tests (local SQLite): availability/payments/portal groups green (71 + 150 + 86), except the known local-only MariaDB/intl ones.

## 2026-10-29 · Codex · Signed contract visual/evidence correction
Owner2026-10-28 decision implemented: signed line/date/provider acceptance/badge and exact recorded consent, original artifacts/hash/terms preserved and unsigned reproduction required. **48tests/583assertions PASS** plus actual Dompdf3page synthetic PDF visually inspected. See newest queue and docs/CONTRACT-SIGNED-LOOK-API.md. No prior final rewritten, deployment or send. Full CI before merge.
## 2026-10-29 · Codex · Consolidated142/144/146 on reviewed152
PR144 preserves Claude142head7c3e2da and dry-run146heada1fda18 via real merges, on main e5e16ff after149/152 each12green. Only documentation conflicts in these last merges; both histories retained. Daily+fee36/212PASS, existing dry-run43/302PASS; final combined CI required. S2 full evaluator and C3 adapters still pending. Owner reports Twilio active; no credentials inspected, provider send/flag change or operational verification inferred. Sending hold intact.

## 2026-10-29 · Codex · Preserve merged Zelle/copy review in daily-admission branch
Merge e5e16ff (#152) has only2documentation conflicts; preserve both histories. No application conflict/change. Prior daily+card36/212PASS and e7b6571head12green checks. New integration CI required. #152merged after12green; no deployment/send.

## 2026-10-28 · Codex · Daily cap + card-fee combined regression passes
36tests/212assertions PASS on the resolved main093797a integration; both fee-pair integrity and paid-overflow preservation retained. Publish to PR144 for fresh full CI. No deployment, fee activation or mail.

## 2026-10-28 · Codex · Daily cap integration preserves merged card-fee policy
Merge current main093797a (#149). One application conflict resolved by retaining BOTH principal/fee split and overflow exception recording under the existing global admission lock; all HANDOFF/review entries preserved. Prior144head8427fef has12green checks; combined targeted regression/CI pending. Sending held, no deployment.

## 2026-10-28 · Codex · Reuse Claude’s security patch for the audit blocker
Port of Claude f03c2f0: Symfony Mime7.4.19→7.4.22 and Polyfill Mbstring1.43.0→1.43.3 (CVE-2026-45070). Original PR145 has6green checks; this branch still requires its own CI. See newest queue entry. No audit suppression, workflow, provider, email or activation change.

## 2026-10-28 · Codex · Shared daily10-party admission prerequisite
`codex/fix-daily-capacity-admission`: global mutation admission protocol across paid/unpaid/native/imported commitments, overnight/Houston day, actual-money preservation and native moves. **200tests/1514assertions PASS** including real-process last-place race; see newest docs/CLAUDE-REVIEW-QUEUE.md and docs/DAILY-CAPACITY-API.md. #140 internal7/web14 already merged maina54b626 after12green checks. Full AVAIL evaluator/positive slots still pending; separate CARD-FEE, C3 and aggregate-only history CLI work underway. Mail hold intact, no deployment/provider/real cohort action.
## 2026-10-28 · Codex · Review147/148 plus Zelle evidence hardening
Preserves both Claude sources on merged149. **53tests/541assertions+8browser layouts/synthetic checkout submissions PASS**. Fixed MIME extension, transaction-bound grant/event/notice authorization, private cleanup and stored-SHA check; no photo treated as money. See newest review queue for exact proof/copy/limits. No deploy or send.

## 2026-10-28 · Codex · Claude143/145 plus hardened credit-fee integration
Real merge preserves both source branches and the frozen monetary contract. **146tests/1415assertions and8browser layouts+synthetic acknowledgments PASS**. Proposed copy remains gatedOFF pending approval/account credit-only verification. See newest review queue entry for exact proof and limits. No deployment, sending, actual collection or real-data change.

## 2026-10-28 · Codex · CARD-FEE-01 monetary backend and DTO (point2)
`codex/fix-standard-card-fee`: frozen3% credit terms, atomic principal/customer_fee ledger split, server quote hash/formatted DTO and funding/account-policy gates. **118tests/1032assertions PASS**. New feature DEFAULT-OFF; no existing client text/template/JS changed or hidden surcharge enabled. Claude can wire approved disclosure/quote acknowledgment; actual external credit-only policy verification for the configured account is still required before activation. See newest queue entry and docs/CARD-FEE-01-API.md. No provider call, deployment, real charge/import, email/probe or flags enabled.
## 2026-10-28 · Codex · Repair PR142 dependency audit prerequisite
Reuse Claude f03c2f0 exact composer.lock (Symfony Mime7.4.22/Polyfill Mbstring1.43.3 for CVE-2026-45070). PR142 latest a89ea46 has5green test shards but auditcheckFAIL; same security patch already passes PR143/145. No audit suppression, workflow, flag, provider, real data or sending change. Fresh complete CI required. See newest queue entry.
## 2026-10-28 · Codex · Reuse Claude’s security patch for the audit blocker
Port of Claude f03c2f0: Symfony Mime7.4.19→7.4.22 and Polyfill Mbstring1.43.0→1.43.3 (CVE-2026-45070). Original PR145 has6green checks; this branch still requires its own CI. See newest queue entry. No audit suppression, workflow, provider, email or activation change.


## 2026-10-28 · Codex · Existing-history aggregate dry-run CLI (point4)
`codex/fix-history-dry-run`: waoo:history:import requires --dry-run and existing owner/batch; reuses importer plan and source/destination verification under one lock, aggregate JSON only, zero command SQL mutations. **43tests/302assertions PASS**; see newest docs/CLAUDE-REVIEW-QUEUE.md and docs/HISTORY-DRY-RUN-CLI.md. No reimport, host action, mail or flag change; real independent review is not claimed. Other assigned backend points continue in isolated branches.
