# Claude review queue (Codex autonomy, 2026-10-22 → 2026-10-25)

Every change Codex makes while Claude is away gets one entry here, newest first, in the same branch as the change. Rules are in `docs/plans/CODEX-AUTONOMY-UNTIL-SUNDAY.md`. Claude reviews each entry on Sunday and changes its status.

Status values: `PENDING` (Codex wrote it) → `REVIEWED` (Claude checked it, OK) or `REWORK` (Claude changed or reverted it; reason added).

Kinds: `BUG` (César reported it), `UI` (screen, portal, PDF or CSS change), `TEXT` (client-facing wording), `DATA` (catalog, seed or config values), `BACKEND`, `DECISION` (needs host access or César; logged only).

## Template

```
### <YYYY-MM-DD> · <kind> · <short title> · PENDING
- Branch / commits: codex/fix-... · abc1234
- PR: #NNN (merged by César: yes/no)
- Reported by César: "<his words, if any>"
- What changed and why:
- Files:
- How verified: <tests run, result; screenshots if UI>
- What Claude must check: <the exact screen, text or risk>
```

## Entries

### 2026-10-27 · BACKEND · Integrate Claude-reviewed Resend and availability without dropping either boundary · PENDING
- Branch: `codex/fix-reviewed-integrations-1027`, base main5ac3593 after reviewed #134 merge; preserves #131 head9874fd1 and Claude #135 headfd1934d through real merge commits aa3bf25/4b40a82.
- Authorization: César's standing necessary-green-merge instruction and Claude's explicit docs/HANDOFF.md request in #135. Claude approved the original #134/#131 heads with no rework and explicitly asked Codex to record REVIEWED; those statuses are attributed to Claude, not Codex self-approval. This new assembly entry remains PENDING.
- What changed and why: resolve5 real merge conflicts by retaining both disabled .env sections, both private response path sets, all raw-body normalization exclusions, Resend's generic errors and availability's generic errors/CORS exclusion, plus both prior HANDOFF/queue histories. Merge Claude's review and website catalog map unchanged. No new domain behavior, sender activation, pricing, client wording or website switching.
- How verified: combined availability/auth/CORS/lead/Postmark/Svix/Resend/master-hold regression **92 tests/715 assertions PASS**, PHP8.3.36/MariaDB11.8,1:27.276. Includes exact raw signatures, no CORS on availability while lead CORS preserved, debug error privacy and no direct/framework mail while held. PHP lint/diff check PASS; no conflict markers or duplicate env keys. No new implementation-mirroring tests added for this merge; the existing meaningful boundary regressions verify the combined source. Parent PR #134 and #131 each12 green checks; #135 docs6 green checks. Integration CI still required before main.
- What Claude must check: the union in bootstrap/app.php and SensitiveApplicationResponses, both OFF/empty config blocks, attributed REVIEWED metadata and retained catalog/review disclosure. Host hold is Claude's reported metadata (d42b7b8, MAIL_MAILER=log, sending flags unset), not a new Codex host check. Claude disclosed one earlier direct test-sink request outside CRM before reading the hold; no new email/probe/provider/host action performed here. Domain status wording verifying/verified is not independent proof of full delivery readiness.
- Still pending: S2 candidate/resource/traffic/10-per-day-cap/14-day-acceptance work, website adaptation and specifically authorized deployment. Characters Only→variant28 requires confirmation; K-Pop mapping/recipe unresolved, other unmapped selections omit service safely. Quick-payment Resend provider receipt/suppression integration remains blocked. Keep all outgoing email held until operational acceptance and new explicit owner authorization; no progress/CI auto-activation.

### 2026-10-27 · BACKEND / DECISION · Resend prepared with owner master sending hold · REVIEWED
- Claude review recorded by Codex at Claude’s explicit request in PR #135 (`fd1934d`), docs/HANDOFF.md entry2026-10-27: REVIEWED, no rework. This attributes Claude’s review of the original head, not a Codex self-review.
- Branch: `codex/fix-resend-preparation`, base main90096c5 (#133 docs merged after6 green checks). Contract-first `0becdf6`, docs/MAIL-RESEND-01-API.md.
- Reported by César: “no quiero que aun slagan correeo hasta que el crm este 100% operativo”. This overrides #133's earlier provider+key-only binding proposal. No real test email, including to the owner, is authorized.
- What changed and why: default-OFF independent delivery gate; provider/key/DNS cannot activate delivery. Binding remains Disabled while held; direct Resend.send rechecks hold/config/claimed immutable outbox/recipient allowlist/suppression. Laravel MessageSending veto blocks framework mail too, including reset-password email; existing MAIL_MAILER unchanged. Fixed HTTPS API, no redirects/retries, generic unknown outcome, namespaced outbox idempotency. Separate default-OFF HTTPS Svix webhook records delivered/bounced/complained evidence while sending remains held; exact binding, raw signature/clock, rotation, idempotent events, complaint precedence and no unsuppression. Native1..9-digit fractional provider timestamp retained by hash for replay identity; no raw body/recipient/signature persisted. No new schema/dependency or activation/UI/client-copy change.
- Files: Resend adapter/verifier/receiver/controller, MailDeliveryGate and provider binding, RecordMailDelivery/ReadOutboundMail, unsupported QuickPaymentNotification Resend guard, raw-body/privacy bootstrap+middleware, webhook route, disabled/empty config placeholders; tests/fixtures; API/runbook/DECISIONS and this queue/HANDOFF.
- How verified: baseline new master-hold regression against old framework mail pipeline FAIL1/1 (in-memory array transport stored a message, expected0; no actual mail). Initial provider/receiver16/74 PASS; extra signature/outbox/quick guards5/19 PASS. Broad mail/outbox/occurrences/Postmark/quick-payments/auth **111 tests/693 assertions PASS**, PHP8.3.36/MariaDB11.8,5:05.458. New real-format fractional-time regression initially FAIL422; final hardened Resend/master/Postmark set **38/214 PASS**,31.777s, includes milli/micro/nanosecond same-ID conflict and official public Svix vector. Sets overlap; do not sum. All HTTP mocked/preventStrayRequests, framework array/fake only; PHP lint and diff PASS. GitHub CI pending after push. Resend docs site403 even on approved retry, official Svix/Resend GitHub sources read instead. No actual provider send, host/DNS/key access, webhook registration or deploy.
- What Claude must check: gate staysOFF on host and framework mail remains held; confirm only nonsecret driver/gate metadata, not a test send. Show delivery_enabled hold state in admin readiness if needed (backend field provided). Review exact payload/idempotency, generic failures/no resend, raw Svix authentication and exact committed send+recipient binding; preserve both this raw-body/privacy change and #131's CORS/availability changes when integrating. Current adapter is sandbox/owner-only; do not advertise production activation or complete mail readiness.
- Explicit readiness gap: parallel QuickPaymentNotification channel lacks provider delivery-ID/suppression storage. Resend skips that path before claim; no fake sent or delivery_unknown marker. Implement its receipt integration before enabling it. Runbook requires review of old outbox/queued mail and uncertain outcomes, operational acceptance and new explicit owner authorization before any actual email. No automatic percentage/time/CI activation.

### 2026-10-26 · BACKEND · AVAIL-WEB-01 S1 signed transport preparation · REVIEWED
- Claude review recorded by Codex at Claude’s explicit request in PR #135 (`fd1934d`), docs/HANDOFF.md entry2026-10-27: REVIEWED, no rework. This attributes Claude’s review of the original head, not a Codex self-review.
- Branch: `codex/fix-website-availability-transport`; contract-first `4394ef8`. Requested by Claude PR #129 and César: prepare the CRM/website connection.
- What changed and why: dedicated default-OFF availability capability, exact raw-target/body/method/timestamp HMAC, independent60/min/IP abuse budget,16KiB body/2048-byte target bounds and staging HTTPS. GET returns a closed demand/unknown day signal only, no slots/alternatives or count-only availability. Earliest8a.m./14-local-day review hint is conservative; no positive cache. POST explicitly503 evaluation_unavailable until full candidate validator + combined resource/transfer evaluator. Production remains denied. No website switch, booking/hold/send/payment/domain mutation or actual provider call.
- Files: availability controller/auth middleware/day reader, CORS boundary, SensitiveApplicationResponses, routes/api.php, bootstrap/app.php, config/waoo.php and disabled `.env.example` placeholders; transport/catalog tests, API S1 freeze, website handoff and ticket status; this queue/HANDOFF.
- How verified: original new day-route regression fails404 (1/1). First executable23/169 PASS exposed a separate browser-CORS regression when explicitly tested: Laravel default wildcard was present. Scoped HandleCors subclass excludes only availability routes, retaining existing lead CORS. Mixed availability/catalog/WebsiteLeads/AdminShellSecurity **45 tests/438 assertions PASS**, PHP8.3.36/MariaDB11.8,49.392s; final transport including published synthetic signing vector and±300s edges **27/188 PASS**,651ms (overlapping, not additive). Catalog query trace SELECT-only; no-variant day read zero SQL; Http preventStrayRequests, no mail/queue side effects. Tests include signatures/tampering, duplicate/encoded/unknown query keys, invalid/past dates, DST14-day equality, revoked-by-config/production, shared failed-auth throttle, debug503 privacy, CORS/preflight and unchanged lead policy. PHP lint/diff check PASS. Full GitHub CI pending after push; no browser/Hostinger/private provider or real-data claim.
- What Claude must check: exact bytes/signing vector in docs/AVAIL-WEB-01-WEBSITE-HANDOFF.md; isolated CORS replacement and exception privacy in bootstrap; the distinction between demand and capacity, no zero/invented stock or staff. Prepare reviewed public variant/character mapping. Do NOT connect availability.php to verified slots on this S1 delivery. S2 still needs full read-only matching/driver/cargo/date-traffic evidence, both neighboring transfers, Houston candidate/DST/closing semantics, cap/holds/pending-Zelle/imported commitments and concurrency/cache-generation proof. The code does not claim full AVAIL completion; defaults remain OFF.

### 2026-10-26 · BUG / UI · Reserva rápida con solo ZIP, saldo con tarjeta en el link rápido, contrato firmado visible, preguntas a lo ancho · REVIEWED
- Branch / commits: `claude/owner-fixes-1026` (Claude; Codex reviews and merges).
- Reported by César (2026-10-26): "it should be only the zip code mandatory to calculate the travel fee"; "¿En qué idioma prefieres / ¿Dónde será la actividad? … todo junto hacia la izquierda"; "¿al cliente le llega una copia del contrato ya firmada …? en donde dice tu pago también debería aparecer la opción rápida para pagar por stripe".
- What changed and why:
  - Travel fee: `TravelFeeQuote` accepts the ZIP alone (address under 10 letters or empty) and routes Google to "<ZIP>, TX, USA"; breakdown gets `zip_only`. Without ZIP the address still needs 10+ letters. Admin API and the "Calcular" button follow; summary says "Calculado con el ZIP". Acceptance hash is unchanged (same address/ZIP inputs as the draft).
  - Balance on the quick-booking link: `PortalContext::allows()` lets a `quick_booking.pay` grant use `checkout.create` only when its contract is signed and `deposit_shortfall_cents === 0`. Pending Zelle deposits stay blocked (no double pay). Grant scopes are not mutated; portal card/Zelle forms and the balance-reminder mail use the same check.
  - Signed contract: the existing approved button "Ver mi contrato firmado / View my signed contract" now also shows in the "¡Tu fiesta está reservada!" card (before it only showed when the balance was $0). No new client text.
  - Portal CSS: `.p-field legend{width:100%}` so fieldset questions are full width (a legend in a grid fieldset shrank to its narrowest word).
- Files: app/Services/Routing/TravelFeeQuote.php, app/Filament/Support/TravelFeeFields.php, app/Http/Controllers/Admin/TravelFeeController.php, app/Services/Portal/PortalContext.php, app/Http/Controllers/Portal/PortalPageController.php, app/Services/Mail/EventMailSource.php, resources/views/portal/show.blade.php, public/css/waoo-portal.css, tests TravelFeeQuotesTest/QuickPaymentsTest.
- How verified: local PHP 8.3 + SQLite, 8 suites (TravelFee×2, QuickPayments, ClientPortalPage, ZelleNoticeScreens, SandboxDocuments, QuickBookingCore, SameVisitContract): 129 tests, 1285 assertions, only the 5 MariaDB/pcntl concurrency tests error locally (CI authority). New: ZIP-only quote + draft acceptance + API; pending-Zelle signed link cannot pay balance; paid+signed link shows card, Zelle and signed-contract button. Screenshot 390px dark: both questions full width (322px), no horizontal scroll.
- Not done: a client receipt (PDF or payment list with dates) does not exist yet; needs new client text, so it waits for César's decision.

### 2026-10-25 · BACKEND · Livewire security patch unblocks the review merge · PENDING
- Branch / commits: `codex/fix-livewire-security-20261025`; package patch `bcea061`. Includes PR #123 through a preserving merge, without rewriting Claude's commits or editing workflows.
- Reported by César: Claude has returned and sent instructions; PR #123 asks Codex to merge once CI is green, consistent with César's existing merge authorization.
- What changed and why: `composer.lock` changes only Livewire v4.4.7 → v4.4.8. Public advisory [GHSA-mhf7-xp6r-6qrc](https://github.com/advisories/GHSA-mhf7-xp6r-6qrc), published October 23, affects >=4.3.0,<4.4.8; the upstream patch fixes authorization ordering and parameter validation. No ignored advisory, relaxed audit, version-constraint change or application workaround. No direct `#[Authorize]` use found in app/tests; no claim of an observed exploit.
- Files: `composer.lock`; this queue and `docs/HANDOFF.md`. Claude's reviewed statuses and notes from #123 are preserved.
- How verified: PR #123 run 40215312157 fails specifically at `composer audit --locked` while all five test shards pass. Independent advisory/version comparison confirms the old locked version is affected and 4.4.8 is patched. Fresh install and strict Composer validation pass. Existing Auth/AdminShellSecurity/AdminScreens/QuoteScreens/CostumeHoldScreen/ClientPortalPage regression: **33 tests / 331 assertions PASS**, PHP8.3.36/MariaDB11.8, 26.664s. Vite build and diff check pass. Local standalone Packagist audit is blocked by the environment proxy (403); CI must provide the definitive locked-audit and full-suite result before merge. Necessary network domains were saved to the environment draft, not represented as applied.
- What Claude must check: the package-only version diff, upstream advisory, and final GitHub audit/full-suite results. Queue review is not host verification: Stripe return, private HoneyBook capture and approved crm-test configuration remain separate followups. No deployment, live-provider action, customer data, price or approved wording changed.

### 2026-10-22 · TEXT / UI / BUG / DATA / DECISION · Mesa de aprobación, cotizaciones 48h y portal de clienta · REVIEWED
- Branch / commits: `codex/fix-owner-text-decisions`, base main `5c4b963` (#120/#121). Owner explicitly authorized Codex in this chat to resolve and merge necessary green PRs; this overrides the earlier owner-only merge workflow, without authorizing deployment.
- Reported by César: Mesa de aprobación pasted in chat; quotes must never last more than 48 hours. Reports internal wardrobe details and repeated additional-character counts in the client summary, plus a paid Stripe test return stuck before contract on crm-test.
- What changed and why: new `client-texts-2026-10-22` addendum archives exact approved words; quote-copy proposals remain pending_review. Remove CST/CDT only from mail display; separate payment amount/date in actual render and preview. New quote/revision deadlines default to min(48 elapsed hours, event start) and reject longer explicit values. Additional 90-minute $350 Glitter Mobile option comes from the existing owner-supplied 120-minute recipe during an explicit future catalog import; no repricing of two hours or inferred resources. Baby Shower15/15 confirmed, log only.
- Client portal: only character names/quantities in summary and event API, no outfit; package name/duration without the repetitive extra-character suffix. Internal financial snapshots/admin/staff evidence and issued/signed artifacts stay exact. Active Stripe checkout is polled even without return parameter, transient HTTP/network errors retry with bounded per-request timeout; only server can_sign advances to preparation/signature. A successful return URL never records or proves payment.
- Files: controllers PortalPage/Portal/PortalPreview, portal/PDF Blade views, QuoteSnapshot/QuoteForm/API, MailDates/ApprovedMail/MailTemplatesDraft, OwnerCatalogOptions/importer/config, addendum/source/tests. Full decisions and final ES/EN text in `docs/reviews/OWNER-APPROVALS-2026-10-22.md`.
- How verified: original approval/quote regression **42 tests/369 assertions PASS**, PHP8.3.36/MariaDB11.8. Five new regression cases against previous implementations: **3 assertion failures +2 errors** (missing new option / old short-event default); final implementation restored byte-for-byte. Old portal projection fails its wardrobe-privacy regression. Old browser polling fails ES/EN regression; final browser polling **4/4 PASS** (return parameter present/absent, temporary503, no advance before can_sign). Portal390px ES/EN light/dark **4/4 PASS**, mail390px ES/EN **4/4 PASS**, no horizontal overflow; captures outside Git at `/workspace/setup/waoo-phase1/browser/portal-*.png` and `mail-*.png`. Composer strict and changed PHP syntax/diff checks pass. Final portal/payment regression **34 tests/304 assertions PASS**, including real synthetic receipt→contract-preparation/signature paths and client-wardrobe privacy. Final unit recheck **10 tests/134 assertions PASS**. Existing quick-payment browser regression **2/2 PASS**, including stable retries and fee disclosure.
- CI followup: first head26b41d1 reproduced an obsolete CostumeVersions expectation requiring wardrobe in customer portal HTML/API. The old case fails locally at1 test/7 assertions. It now asserts plain character name, absent outfit field/value and absent private note, preserving all finance/contract/internal assertions. Entire CostumeVersions suite **18 tests/98 assertions PASS** (PHP8.3.36/MariaDB11.8). GitHub validation must finish on the updated head before merge.
- What Claude must check: client summary, internal wardrobe retained in source only, quote expiry boundaries and old receipts, exact approved copy/new quote proposals, Glitter90 import after verifying the private source recipe. Reproduce the reported successful Stripe test return on crm-test and inspect private webhook/receipt/reconciliation state; no host access or real reservation fix is claimed here. No deployment, flags, real data, sends, charges, live provider calls or HoneyBook writes.
- Approved host decisions (logged only per temporary plan): analytics/company/anniversary flags on crm-test; exclude nameless HoneyBook contacts in the private import; connect Google Calendar on crm-test. OAuth/provider/host execution remains Claude's workflow. `birthday_draft` approved as draft only: campaign permissions/terms and sending stay blocked. Stripe release-message note clarified as the48h quote policy; current approved release text retained.
- Already-approved existing messages are reproduced verbatim in the new source; frozen base and the previous approved source are unchanged. Requested quote text below is NOT yet approved for sending. `mail_payment_received` words are unchanged; its amount/date separator is corrected.
- Claude review 2026-10-25: matches César's approval page (48h quotes, no CST/CDT, approved texts, Glitter 90 min $350, Baby Shower 15/15). Revisions go through QuoteSnapshot too, so the 48h cap covers them. Every real `balance_due` producer (EventMailSource, QuickPaymentNotifications) already starts with " · vence" / ", vence el"; the "197.5020" César saw was the approval page sample, so the leading-space guard is a harmless no-op. Portal shows `name` only, which every snapshot carries (CharacterLabel reads the same key). Polling stops after 60 tries or on reload without the return param, no loop. main CI green at 055bffb. Still open: reproduce the Stripe test return on crm-test after deploy.

**portal_reserved · approved**

ES: ¡Tu fiesta está reservada! 🎉

EN: Your party is booked! 🎉

**portal_reserved_body · approved**

ES: Tu contrato está firmado y recibimos tu depósito. Solo falta el saldo antes de la fiesta; aquí abajo tienes cómo pagarlo.

EN: Your contract is signed and we received your deposit. Only the balance is left before the party; how to pay it is below.

**portal_zelle_memo · approved**

ES: En la nota del Zelle escribe

EN: In the Zelle memo write

**portal_zelle_memo_help · approved**

ES: así sabemos que es tu pago

EN: so we know it is your payment

**portal_stripe_confirming · approved**

ES: Estamos confirmando tu pago con Stripe. Esto tarda unos segundos; esta página se actualiza sola.

EN: We are confirming your Stripe payment. This takes a few seconds; this page refreshes by itself.

**portal_owner_notice · approved**

ES: Estás viendo este enlace con tu sesión de dueño. Para ver y probar lo que ve el cliente (pagar, firmar), ábrelo en una ventana de incógnito o en tu celular.

EN: You are viewing this link with your owner session. To see and test what the client sees (pay, sign), open it in a private window or on your phone.

**pdf_bill_to · approved**

ES: Facturar a:

EN: Bill to:

**pdf_attention · approved**

ES: Atención:

EN: Attention:

**mail_quote_sent · pending_review**

ES: Asunto: Tu cotización Waoo Party para el {subject_date}

¡Hola {first_name}! Aquí está tu cotización de {package} para el {event_date}.

[Aquí va la tabla con paquete, transporte y total]

Para apartar tu fecha pedimos un depósito del 50 % ({deposit}). Esta cotización tiene una vigencia máxima de 48 horas, hasta el {valid_until}.

[Botón: Ver y aceptar mi cotización]

¡Estamos emocionados de ser parte de tu celebración!

EN: Subject: Your Waoo Party quote for {subject_date}

Hi {first_name}! Here is your quote for {package} on {event_date}.

[Table with package, travel fee and total goes here]

To reserve your date we need a 50% deposit ({deposit}). This quote is valid for a maximum of 48 hours, until {valid_until}.

[Button: Review and accept my quote]

We're excited to be part of your celebration!

**quote_link_msg · pending_review**

ES: ¡Hola {first_name}! Aquí está tu cotización de Waoo Party para el {event_date}: {link}. Tiene una vigencia máxima de 48 horas, hasta el {valid_until}.

EN: Hi {first_name}! Here is your Waoo Party quote for {event_date}: {link}. It is valid for a maximum of 48 hours, until {valid_until}.


### 2026-10-22 · BACKEND · Contrato de disponibilidad para la web · REVIEWED
- Branch / commits: `codex/fix-availability-api` · `6d85bea`, desde main `4dccfa8` (#119).
- PR: no fusionado; comparación https://github.com/cesaraolarteq-dotcom/waoo-crm/compare/main...codex/fix-availability-api?expand=1 . César autorizó crear PR automáticamente; se intenta al subir, sin autorización para fusionar/desplegar.
- Reported by César: “sigue trabajando en lo que está pendiente”; siguiente trabajo del plan de autonomía, sección5 punto3: contrato solamente.
- What changed and why: contrato publicado para lectura firmada del servidor web, señal diaria y comprobación de horas concretas; distingue capacidad de demanda comercial y de una reserva. Incluye14días/revisión del dueño,10fiestas/día, horarios nocturnos/Houston/DST, recursos físicos/roles/conductor/vestuario,45min y logística, leases/Zelle pendiente, caché con invalidación y límites de privacidad. No inventa disponibilidad ni toma el conteo/iCal como prueba de recursos.
- Files: docs/AVAIL-WEB-01-API.md, docs/tickets/AVAIL-WEB-01.md; HANDOFF y progreso como design solamente.
- How verified: Python estándar parsea los3ejemplos JSON; comprueba los3enlaces relativos, reservation_created=false en ambas respuestas y America/Chicago en la solicitud. git diff --check. Sin pruebas de aplicación nuevas porque no cambia código; no endpoint implementado ni llamadas a proveedores. Sin captura ni datos reales.
- What Claude must check: contrato, mapeo del catálogo y adaptación PHP antes de conectar la web; no exponer credenciales al navegador ni horarios sin prueba completa. La interpretación exacta del cierre1a.m., el límite preciso de14días, generación de capacidad, extracción de la lectura de emparejamiento y evidencia privada de rutas quedan como fronteras de implementación, no decisiones nuevas aplicadas. Revisar estas fronteras antes de programar sus comportamientos; ninguna autorización implícita para activar flags o desplegar. Errores/iCal alternativo no pueden fabricar horarios libres. Porcentaje de implementación sin aumento.
- Claude review 2026-10-25: design doc only, no code. Accepted as the contract; the listed boundaries (1 a.m. cutoff, 14-day limit, capacity generation) get decided when implementation starts.

### 2026-10-22 · BACKEND · Verificador HoneyBook: referencias y vocabulario de estados · REVIEWED
- Branch / commits: `codex/fix-honeybook-capture-decoder` · contrato `6520851`, código `3e31245`; base main `5e1d500` (#118).
- PR: no abierto; César abre y une solo con CI verde. Comparación: https://github.com/cesaraolarteq-dotcom/waoo-crm/compare/main...codex/fix-honeybook-capture-decoder?expand=1 . Sustituye la entrega anterior `codex/honeybook-capture-decoder`; no unir ambas.
- Reported by César: “Cuando no tengas nada mío, empieza por la corrección del verificador”. Formas de captura: docs/reviews/HONEYBOOK-CAPTURE-SHAPES.md, ya en main.
- What changed and why: projects.json es la única lista de proyectos; contactos, workspaces y archivos se enlazan como referencias, sin crear duplicados ni enfrentar el nombre/fecha del workspace con los del proyecto. Se compara client como reservado y lead/lead_sent como prospecto, conservando valores originales y separando la clasificación temporal cuando existe fecha fuente. Soporte de centavos explícitos, pagos en dólares sin redondeo, horas12h/espacios U+202F, firmas requeridas de clienta y procedencia del consentimiento de HoneyBook. Reporte privado distingue comparaciones pendientes de campos crudos pendientes.
- Files: app/Services/MigrationVerification/{FlatHoneyBookCapture,RawHoneyBookSnapshot,VerifyHoneyBookMigration}.php; comando VerifyHoneyBookMigration; pruebas RawHoneyBookSnapshot/FlatHoneyBookCapture/HoneyBookIndependentVerification/HoneyBookFutureImport; contrato y runbook del decodificador. Sin pantallas, textos para clientas, banderas, workflows ni datos reales modificados.
- How verified: prueba reproducida contra el decodificador de main: FAIL con ambiguous_source_field (1 prueba,3 aserciones); regresión final en main5e1d500 + arreglo: PASS70 pruebas/337 aserciones,2:23.093, PHP8.3.35/MariaDB11.8. No batería completa ni SQLite/browser/Hostinger. Composer estricto y diff sin errores; datos sintéticos solamente. No captura real ni despliegue.
- What Claude must check: revisar los enlaces por proyecto/contacto/archivo; correr la misma captura privada en crm-test con un nombre de acta nuevo y comparar hashes/estado consistente. Desconocidos, acuerdos ambiguos, semántica de reembolsos/pagos parciales, precios anidados y preguntas no mapeadas siguen sin verificar. Ninguna diferencia de bytes de PDF se excusa por reimpresión. human_double_review_complete=false; no declarar100% ni activar nada con esta entrega. Confirmar Actions antes de integrar; acceso GitHub API del entorno limitado (se vuelve a intentar tras el push).
- Claude review 2026-10-25: read-only verification tooling, no screens or client data paths; tests added, main CI green. Still open: run the private capture on crm-test with a new report name.

### 2026-10-22 · DECISION · Pending owner decisions that need host access · REVIEWED
- Branch / commits: none (logged by Claude before leaving)
- What Claude must check: César's answers in the Mesa de aprobación page for `dec_flag_analytics`, `dec_flag_company`, `dec_flag_anniversary`, `dec_nameless_leads`, `dec_google_calendar`. Apply on crm-test only after his "súbelo". PR #117 (contract shows the package description; portal reads as booked) is merged and **not deployed** yet.
- Claude review 2026-10-25: César approved all five (analytics, company, anniversary flags; exclude nameless leads; Google Calendar on crm-test). Applied on crm-test at the next deploy, after his "súbelo".
