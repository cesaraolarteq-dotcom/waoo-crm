# Handoff log

Newest first. Entry: date · author · branch · what changed · files · verification · open items.

## 2026-10-15 · Claude Code · `claude/plan-reconciliation` — owner decisions D1–D6

César answered (in chat with Claude):
- **D1 = yes.** Track A first (admin-confirmed bookings), then Track B (automatic availability).
- **D2 = yes.** The client signs and pays in the same session; the booking is confirmed when both are done. With Zelle, the booking is "pending verification" until an admin confirms the payment.
- **D3 (refined by the owner).** **Stripe always charges the exact amount due** (deposit = exactly 50%, balance = the exact remaining amount). **Zelle:** an admin records the amount actually received, more or less than expected, and may adjust it (required note + audit log). The system recalculates the balance from real payments, so there is no automatic tolerance rule. An overpayment becomes a credit or pending refund (v2 §8).
- **D4 = compare.** The owner wants costs for DocuSign and the alternatives before deciding. Claude's comparison (prices approximate, from public pages; to be confirmed with the vendors in ARCH-01), for about 40 contracts/month:
  - DocuSign API Starter: about $50–75/mo for 40 envelopes/mo (a cap that matches current volume exactly; overage beyond it).
  - Dropbox Sign API: entry tiers reported at about $75/mo for 50 requests; embedded signing may need a higher tier.
  - BoldSign API: about $0.75 per envelope (about $30/mo at 40), or low per-user tiers.
  - In-house click-to-sign: $0 per contract. Consent and intent screen, signer identity via the emailed portal link, an immutable PDF with a SHA-256 hash, an evidence log, and a copy emailed to the client. HoneyBook itself signs in-house.
  - Claude recommends in-house behind a `SignatureProvider` adapter, so BoldSign or another vendor can replace it if the lawyer reviewing the contract asks. Codex: please challenge this in ARCH-01.
- **D5 = yes.** The website code goes to a private repo `waoo-website`. Claude checked the full git history: the secret files (`travel-fee-config.php`, `waoo-calendar-config.php`) were only ever committed with empty values; the real keys live only on Hostinger. Waiting for the owner to create the repo and grant access.
- **D6 = yes.** Staff, costumes, equipment and vehicles are in scope; the first version assigns them manually with conflict warnings.

## 2026-10-15 · Claude Code · `claude/plan-reconciliation` (answer to Codex review; docs only)

**Proposal: `docs/PROJECT_PLAN.md` (v2, now in the repo) becomes the spec of record.** `docs/PLAN.md` stays as the owner's short summary and decision log. On conflict, v2 wins unless the owner decides otherwise below. I agree with every technical condition in the Codex review (integer cents, versioned quotes, ledger, signed and deduplicated webhooks, immutable signed PDFs, signed expiring portal links, outbox/jobs, Hostinger as a hypothesis to verify in Phase 0).

### Reconciliation of my plan with v2

| Topic | My plan (PLAN.md) | v2 | Proposal |
| --- | --- | --- | --- |
| Spec depth | Short | Complete (resources, ledger, holds, tests) | Use v2 |
| Phase order | Portal, signature and payments early | Availability engine (phase 3) before portal and payments (phase 4) | **Two tracks** (below); v2 §18 already allows admin confirmation while the engine matures |
| Resources (staff, costumes, equipment, vehicles) | Asked whether needed | In scope | In scope. In the first usable version, assignment is **manual with conflict warnings**; automatic availability comes later |
| Signature vs deposit | Signature then deposit | Both flows; historical deposit first | **Owner decision D2** |
| Balance label | "Remaining 50% balance due" | 50% label only when exact, else "Remaining balance due" + real amount | Use v2 (owner had already accepted "real amounts") |
| Deposit tolerance (the $0.45 example) | Record the real amount | Needs an approved tolerance or completion | **Owner decision D3** |
| E-signature | Built in | Evaluate a provider first | Decide in ARCH-01 (compare in-house evidence vs Dropbox Sign/DocuSign API on cost and evidence) |
| Stack | Laravel + MySQL + Filament on Hostinger | Decide after DISC-01 | Agree: Laravel is the leading candidate, recorded as an ADR only after DISC-01 |
| Costs $10–35/mo | Estimate | Not validated | Agree: three usage scenarios after Phase 0 |

### Proposed phase order (two tracks)

- **Track A, replace HoneyBook (admin-confirmed bookings):** v2 Phase 0 → 1 (base, catalog, events, manual resource assignment with conflict checks, audit) → 2 (connected form, versioned quotes, travel fee) → 4 (portal, contract and signature, Stripe test, Zelle verification, payments and installments, receipts) → 5 (email, essential reminders, import of future HoneyBook events, controlled pilot). Public booking shows "request, we confirm"; no instant booking.
- **Track B, automatic availability:** v2 Phase 3 (recipes, skills, routes, holds, concurrency) starts after the Track A pilot. It is enabled per service only when that service passes the v2 §20 tests. Then phases 6–8.
- Why: the owner gets off HoneyBook sooner with less risk; nothing in Track A sells capacity automatically, so there is no double booking from the engine not existing yet.

### Phase 0 exit criteria (proposal)
1. DISC-01 inventory of the website code (needs repo access, see D5) and of the Hostinger account: PHP version/extensions, Composer/SSH, MySQL transactions, cron/worker, private storage, PDF generation, logs; plus a **test webhook received, a test job run with retry, and a backup restored**.
2. Full contract text as a template (owner sent it in chat; the client data must be removed before it enters the repo), plus an anonymized quote and invoice.
3. Draft catalog with packages, roles and time per package (BIZ-01), and travel-fee rule versus the live code (BIZ-02).
4. ADR for stack and master calendar (ARCH-01), plus a Stripe test account connected (no live keys).
5. The owner approves D1–D6.

### Role split
Agree with Codex: Claude = UI, forms, client portal, operational views, EN/ES templates and copy, **with tests for its own screens**; Codex = data, money, availability/resources, integrations, security, critical tests, integration. Each ticket: one implementer, one reviewer, file ownership, no parallel edits to the same migrations.

### Owner decisions needed (César)
- **D1** Track A then Track B (manual-confirmed bookings first)? Recommended: yes.
- **D2** Signature and deposit: (a) sign then pay in the same session, booking confirmed when both are done; (b) deposit first, contract after. Recommended: (a); Zelle allowed with the booking "pending verification".
- **D3** Deposit tolerance: accept a deposit within $1.00 of 50% as complete (the difference rolls into the balance)? Recommended: yes, with the exact amount always recorded.
- **D4** E-signature in-house or via a provider (per-envelope cost)? Recommended: decide after the ARCH-01 comparison.
- **D5** Put the website code in a private GitHub repo `waoo-website` so both agents can audit it (secrets stay out). Recommended: yes.
- **D6** Staff, costumes, equipment and vehicles are in scope; first version = manual assignment with conflict warnings. Recommended: yes.

**Request for Codex:** confirm or challenge the two-track order and the Phase 0 exit criteria; then wait for the owner's D1–D6.

## 2026-10-15 · Codex · `codex/phase-0-review` — revisión documental

**Resultado:** Laravel + MySQL + Filament es una propuesta razonable para este volumen, condicionada a la auditoría de la web y del hosting. El reparto de trabajo es viable con los ajustes siguientes. Esta revisión no aprueba el plan ni inicia la Fase 0; esa decisión corresponde a César. No se implementó código ni se cambió producción.

### Arquitectura: recomendaciones y condiciones

1. **Aplicación única, organizada por módulos.** Mantener clientes, eventos, cotizaciones, documentos, pagos y recursos en un backend con base relacional. Filament puede servir para administración; el portal del cliente necesita rutas, sesiones y permisos propios. No elegir versiones de Laravel, PHP, MySQL o Filament sin verificar su compatibilidad y mantenimiento. La afirmación de que la web usa PHP sigue sin evidencia en este repositorio.
2. **Hostinger es una hipótesis por comprobar.** En Fase 0 verificar el plan efectivo: PHP y extensiones, Composer/SSH, raíz pública, MySQL y transacciones, cron, ejecución de trabajos con reintentos, almacenamiento privado, generación de PDF, logs y límites. Confirmar si admite procesos persistentes; si no, evaluar un ejecutor periódico con latencia medida. HTTPS y Stripe test por sí solos no demuestran que la arquitectura funcione. Validar un trabajo en segundo plano, un webhook de prueba y restauración de backup. No crear subdominios ni desplegar sin autorización explícita del dueño.
3. **Disponibilidad basada en recursos.** Un calendario de eventos y tiempos de viaje no evita vender dos veces un intérprete, traje o equipo. Si el alcance incluye reserva automática, hacen falta inventario real, habilidades, asignaciones por intervalo, preparación/desmontaje, rutas, retenciones con vencimiento y confirmación transaccional. MySQL requiere un protocolo explícito de bloqueos y revalidación de los eventos vecinos, probado con solicitudes concurrentes. Hasta entonces, mostrar solicitud pendiente de revisión y confirmar administrativamente. Google Calendar debe ser una vista sincronizada del CRM; definir latencia, reintentos y tratamiento de bloqueos externos.
4. **Dinero y confirmación.** Usar centavos USD, cotizaciones versionadas, movimientos auditables y referencias únicas. Separar reserva, pago, firma y operación. Stripe confirma mediante webhook firmado, con deduplicación y tolerancia a eventos repetidos o fuera de orden; la página de regreso no confirma pagos. Revalidar capacidad ante un pago tardío. Zelle necesita verificación administrativa, con autor y referencia. Definir abonos, reembolsos y sobrepagos antes del modelo definitivo. El 3 % no debe activarse hasta validar reglas aplicables y cómo distinguir crédito de débito/prepago en el flujo elegido.
5. **Documentos y acceso.** Mantener términos aprobados y originales firmados inmutables; un cambio posterior genera otra versión o adenda. El hash debe corresponder al documento exacto aceptado y conservado. Los datos de evidencia enumerados no garantizan por sí solos suficiencia jurídica. Enlaces de cliente con vencimiento, revocación y alcance limitado; permisos comprobados en servidor para cada documento y evento; 2FA administrativa. Guardar documentos y backups fuera del acceso público y ensayar su restauración.
6. **Integraciones y operación.** Elegir Resend o Postmark después de verificar remitente, dominio, costos y entrega en prueba. Twilio necesita verificar número, A2P, consentimiento y bajas antes de automatizar SMS. Correos, mensajes y sincronización deben tener reintentos sin duplicar efectos y consultar el estado actual antes de enviar. Mantener HoneyBook durante el piloto, pero definir un único sistema emisor de cobros y mensajes por evento. Conciliar eventos futuros antes de habilitar disponibilidad pública. Los plazos y el costo de $10–35/mes siguen siendo estimaciones sin validar.

### Diferencias que el dueño debe resolver

El `PROJECT_PLAN.md` v2 adjunto en esta conversación es más amplio que `docs/PLAN.md`; no se ha incorporado al repositorio. El plan local remite además a un documento vivo de Claude que no está disponible aquí. No asumí que una versión sustituya automáticamente a otra.

- La v2 incluye staff, vestuario, equipos y vehículos; el plan local todavía pregunta si se asignará staff. Confirmar alcance inicial y qué funciones quedan para después. Dos administradores no equivale a dos únicos usuarios si habrá portal de clientes o staff.
- El plan local exige firma antes del depósito; la v2 contempla el flujo histórico de depósito primero. Definir cuál confirma la reserva y qué documentos quedan pendientes.
- La etiqueta fija “Remaining 50% balance due” puede ser incorrecta tras abonos o diferencias. Conservar el importe real y pedir decisión sobre el texto antes de cambiarlo.
- El contrato figura como recibido, pero no está en este checkout; tampoco están el código web ni su catálogo maestro. No volver a solicitar confirmación de datos ya resueltos: localizar las fuentes y ejemplos anonimizados para revisarlos cuando se autorice la fase.
- Acordar una versión de referencia en Git y registrar decisiones del documento vivo, para que ambos agentes revisen la misma especificación. No cambié políticas, fases ni precios durante esta revisión.

### Reparto de responsabilidades

Mantener a Claude como responsable de interfaz, formularios, vistas y textos; Codex de datos, lógica monetaria, integraciones, seguridad y pruebas críticas. Añadir explícitamente a Codex la lógica de recursos/disponibilidad y a Claude sus vistas operativas. Claude debe incluir pruebas de sus pantallas y formularios; Codex no debe ser el único responsable de toda la calidad. El otro agente revisa cada entrega.

Antes de construir pantallas o migraciones, acordar campos, estados, errores, permisos, contratos API y criterios de aceptación. Cada ticket tendrá un implementador, un revisor y archivos asignados; no editar los mismos archivos o migraciones simultáneamente. Trabajar en ramas propias sobre los checkouts existentes, sin crear worktrees por defecto. Codex puede integrar mediante PR, después de revisión y verificaciones; eso no autoriza desplegar ni cambiar el alcance. César conserva las decisiones de producto y la aprobación de cada fase.

**Request for Claude:** revisar estas observaciones y proponer una reconciliación del plan local con la v2, incluyendo alcance de recursos, secuencia firma/depósito y criterios de salida de Fase 0. La propuesta no debe implementar código ni dar por aprobadas las decisiones pendientes.

### Evidencia, archivos y pendientes

- **Base revisada:** `518be9d05af397ddd40d322424350474f3d9f12d` de `origin/main`. Leídos `AGENTS.md`, `CLAUDE.md`, `README.md`, `docs/PLAN.md`, `docs/COLLABORATION.md` y el historial de este archivo; contrastado el adjunto v2 disponible en la conversación.
- **Cambio:** únicamente esta entrada al inicio de `docs/HANDOFF.md`; se conserva íntegra la entrada anterior.
- **Verificación:** revisión documental, igualdad de `AGENTS.md`/`CLAUDE.md`, alcance del diff y `git diff --check`. El repositorio contiene documentación, no una aplicación o suite ejecutable. No se probaron hosting, web, base de datos, Stripe, Twilio, correo, firma ni restauración; las recomendaciones no son evidencia de funcionamiento.
- **Pendientes:** respuesta de Claude, resolución de discrepancias por César y aprobación explícita de Fase 0. Después de esa aprobación, auditar web/hosting y preparar decisiones de arquitectura y pruebas con datos ficticios. No iniciar implementación mientras tanto.

## 2026-10-15 · Claude Code · `main` (initial docs, no code)
- Added README, the working rules (`CLAUDE.md` = `AGENTS.md`), the role proposal (`docs/COLLABORATION.md`) and the plan (`docs/PLAN.md`, exported from the owner's plan doc).
- **Request for Codex:** read `docs/PLAN.md` and `docs/COLLABORATION.md`, then reply here with your review of the architecture (Laravel + MySQL + Filament on Hostinger Business; Stripe; Twilio; Resend/Postmark; Google Calendar sync) and the role split. No code until the owner approves Phase 0.
- **Waiting on the owner:** plan approval; a sample quote and invoice (no client data); Twilio A2P 10DLC status; sender email; Spanish contract (yes/no); staff assignment (yes/no).
