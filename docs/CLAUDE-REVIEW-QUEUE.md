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

### 2026-10-22 · TEXT / UI / BUG / DATA / DECISION · Mesa de aprobación, cotizaciones 48h y portal de clienta · PENDING
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


### 2026-10-22 · BACKEND · Contrato de disponibilidad para la web · PENDING
- Branch / commits: `codex/fix-availability-api` · `6d85bea`, desde main `4dccfa8` (#119).
- PR: no fusionado; comparación https://github.com/cesaraolarteq-dotcom/waoo-crm/compare/main...codex/fix-availability-api?expand=1 . César autorizó crear PR automáticamente; se intenta al subir, sin autorización para fusionar/desplegar.
- Reported by César: “sigue trabajando en lo que está pendiente”; siguiente trabajo del plan de autonomía, sección5 punto3: contrato solamente.
- What changed and why: contrato publicado para lectura firmada del servidor web, señal diaria y comprobación de horas concretas; distingue capacidad de demanda comercial y de una reserva. Incluye14días/revisión del dueño,10fiestas/día, horarios nocturnos/Houston/DST, recursos físicos/roles/conductor/vestuario,45min y logística, leases/Zelle pendiente, caché con invalidación y límites de privacidad. No inventa disponibilidad ni toma el conteo/iCal como prueba de recursos.
- Files: docs/AVAIL-WEB-01-API.md, docs/tickets/AVAIL-WEB-01.md; HANDOFF y progreso como design solamente.
- How verified: Python estándar parsea los3ejemplos JSON; comprueba los3enlaces relativos, reservation_created=false en ambas respuestas y America/Chicago en la solicitud. git diff --check. Sin pruebas de aplicación nuevas porque no cambia código; no endpoint implementado ni llamadas a proveedores. Sin captura ni datos reales.
- What Claude must check: contrato, mapeo del catálogo y adaptación PHP antes de conectar la web; no exponer credenciales al navegador ni horarios sin prueba completa. La interpretación exacta del cierre1a.m., el límite preciso de14días, generación de capacidad, extracción de la lectura de emparejamiento y evidencia privada de rutas quedan como fronteras de implementación, no decisiones nuevas aplicadas. Revisar estas fronteras antes de programar sus comportamientos; ninguna autorización implícita para activar flags o desplegar. Errores/iCal alternativo no pueden fabricar horarios libres. Porcentaje de implementación sin aumento.

### 2026-10-22 · BACKEND · Verificador HoneyBook: referencias y vocabulario de estados · PENDING
- Branch / commits: `codex/fix-honeybook-capture-decoder` · contrato `6520851`, código `3e31245`; base main `5e1d500` (#118).
- PR: no abierto; César abre y une solo con CI verde. Comparación: https://github.com/cesaraolarteq-dotcom/waoo-crm/compare/main...codex/fix-honeybook-capture-decoder?expand=1 . Sustituye la entrega anterior `codex/honeybook-capture-decoder`; no unir ambas.
- Reported by César: “Cuando no tengas nada mío, empieza por la corrección del verificador”. Formas de captura: docs/reviews/HONEYBOOK-CAPTURE-SHAPES.md, ya en main.
- What changed and why: projects.json es la única lista de proyectos; contactos, workspaces y archivos se enlazan como referencias, sin crear duplicados ni enfrentar el nombre/fecha del workspace con los del proyecto. Se compara client como reservado y lead/lead_sent como prospecto, conservando valores originales y separando la clasificación temporal cuando existe fecha fuente. Soporte de centavos explícitos, pagos en dólares sin redondeo, horas12h/espacios U+202F, firmas requeridas de clienta y procedencia del consentimiento de HoneyBook. Reporte privado distingue comparaciones pendientes de campos crudos pendientes.
- Files: app/Services/MigrationVerification/{FlatHoneyBookCapture,RawHoneyBookSnapshot,VerifyHoneyBookMigration}.php; comando VerifyHoneyBookMigration; pruebas RawHoneyBookSnapshot/FlatHoneyBookCapture/HoneyBookIndependentVerification/HoneyBookFutureImport; contrato y runbook del decodificador. Sin pantallas, textos para clientas, banderas, workflows ni datos reales modificados.
- How verified: prueba reproducida contra el decodificador de main: FAIL con ambiguous_source_field (1 prueba,3 aserciones); regresión final en main5e1d500 + arreglo: PASS70 pruebas/337 aserciones,2:23.093, PHP8.3.35/MariaDB11.8. No batería completa ni SQLite/browser/Hostinger. Composer estricto y diff sin errores; datos sintéticos solamente. No captura real ni despliegue.
- What Claude must check: revisar los enlaces por proyecto/contacto/archivo; correr la misma captura privada en crm-test con un nombre de acta nuevo y comparar hashes/estado consistente. Desconocidos, acuerdos ambiguos, semántica de reembolsos/pagos parciales, precios anidados y preguntas no mapeadas siguen sin verificar. Ninguna diferencia de bytes de PDF se excusa por reimpresión. human_double_review_complete=false; no declarar100% ni activar nada con esta entrega. Confirmar Actions antes de integrar; acceso GitHub API del entorno limitado (se vuelve a intentar tras el push).

### 2026-10-22 · DECISION · Pending owner decisions that need host access · PENDING
- Branch / commits: none (logged by Claude before leaving)
- What Claude must check: César's answers in the Mesa de aprobación page for `dec_flag_analytics`, `dec_flag_company`, `dec_flag_anniversary`, `dec_nameless_leads`, `dec_google_calendar`. Apply on crm-test only after his "súbelo". PR #117 (contract shows the package description; portal reads as booked) is merged and **not deployed** yet.
