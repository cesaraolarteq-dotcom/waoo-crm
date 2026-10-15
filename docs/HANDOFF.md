# Handoff log

Newest first. Entry: date · author · branch · what changed · files · verification · open items.

## 2026-10-15 · Codex · `codex/phase-0-audit` — flujo de excepciones y D7 incorporada

- Integrado main `81287c2`, conservando entregas de Claude y las reglas recientes del dueño sobre staff, cuatro speakers, tráfico y 45 minutos. Conflictos documentales resueltos sin eliminar entradas históricas.
- **D7–D9 ya no están pendientes:** no se aparta hasta depósito verificado; remitente sales@waooparty.com; contrato inglés. Corregido ADR y marcada explícitamente la sustitución de propuestas previas de hold en RESERVATION_PROTOCOL. Flujo vigente en APPROVAL_FLOW.
- Documentada opción propuesta por el dueño de solicitar hasta 15 minutos antes del recomendado, siempre con aprobación. Una sola revisión puede resolver también menos de siete días; aprobación no reserva ni salta conflictos. Regla estándar de 45 minutos sin excepción.
- Nuevo `docs/APPROVAL_FLOW.md`: estados, decisiones versionadas/idempotentes, revisión administrativa, textos ES/EN de referencia, revalidación al pago, concurrencia y criterios de aceptación. No es código ni UI implementada.
- Incorporada a DECISIONS la evidencia Hostinger de Claude (PHP 8.3, sin Node, sin exec/symlink, cron hPanel) y revisión de propuesta BoldSign/Resend. No repetí sus comprobaciones ni afirmo tener su acceso SSH. Staging ya existe; escrituras DISC-02 siguen pendientes según su entrega.
- **Request for Claude:** revisar APPROVAL_FLOW para bandeja/portal; alinear pago sin holds con aprobación de última hora/horario adelantado. Completar evidencia de exportación/retención de firma y picos diarios de correo. No reabrir D7–D9.
- **Archivos propios modificados:** BUSINESS_RULES, RESERVATION_PROTOCOL, DECISIONS, HANDOFF y nuevo APPROVAL_FLOW; otros documentos llegaron mediante merge de main. **Verificación:** diff documental, instrucciones de agentes idénticas e historial de ambas ramas conservado. Pruebas del futuro flujo no ejecutadas; ningún cambio de hosting, cobro o producción.

## 2026-10-15 · Codex · `codex/phase-0-audit` — margen estándar actualizado a 45 minutos

- César propone sustituir 30 por 45 minutos para dar más holgura a desmontaje, montaje e imprevistos. Se actualiza el diseño vigente a **45 minutos adicionales mínimos sobre viaje**; las entradas históricas de 30 se conservan como historial y quedan sustituidas por esta actualización.
- Ejemplo recalculado: fin 16:00 + viaje 28 min + margen 45 min = 17:13. 17:15 es una opción con grilla de 15 minutos, aún no aprobada. Siempre redondear hacia adelante si se usa grilla. Servicios con mayor preparación necesitan más tiempo; no garantizar suficiencia universal.
- **Request for Claude:** usar el mínimo actualizado en reglas y vistas de logística; no sumar doble montaje/desmontaje ni alterar duración de paquetes (Star sigue en 30 minutos).
- **Archivos:** BUSINESS_RULES, RESERVATION_PROTOCOL, HANDOFF. **Verificación:** diff documental y aritmética del ejemplo; sin código ni pruebas de aplicación, sin cambios de producción.

## 2026-10-15 · Codex · `codex/phase-0-audit` — última hora, tráfico y margen mínimo

- **Confirmado por César:** solicitudes a menos de siete días requieren aprobación de César/Maybeli; rutas entre fiestas deben considerar tráfico previsto para fecha/hora de viaje; mínimo de 30 minutos adicionales para desmontaje, montaje e imprevistos. Ejemplo: fin 16:00, viaje 28 minutos, oferta 17:00 si cabe la operación.
- BUSINESS_RULES distingue esas decisiones de recomendaciones: desglose de tiempos, corte temporal exacto, grilla y revalidación. No se impuso un margen de 30 minutos exclusivamente vial además del setup: el ejemplo del dueño agrupa esos usos. Ampliar el total cuando las tareas y contingencia requieran más.
- RESERVATION_PROTOCOL detalla flujo de aprobación, estados independientes, revalidación, rutas para hora de salida y criterios futuros. Tráfico de fin de semana no se supone siempre más favorable. Capacidad exacta de Google Routes pendiente de verificación; código actual sin tráfico no satisface el requisito.
- **Request for Claude:** incorporar bandeja/aprobación simple de última hora y estados de revisión en UI; usar estas reglas en BIZ-01/ROUTE-01/BOOK-A-01/AVAIL-01. Aprobación no salta recursos, firma ni pago.
- **Archivos:** docs/BUSINESS_RULES.md, docs/RESERVATION_PROTOCOL.md, docs/HANDOFF.md. **Verificación:** diff documental; casos son especificación, no pruebas ejecutadas. No se implementó código ni se alteró producción. Fase 0 continúa; Fase 1 no autorizada.

## 2026-10-15 · Codex · `codex/phase-0-audit` — transporte compartido y cuatro speakers

- **Datos del dueño:** habilidades de staff (interpretar, pintacaritas, animar); César y Maybeli pueden transportar grupos distintos, y otros miembros pueden conducir y participar en la fiesta; existen cuatro speakers y pueden reutilizarse en eventos anteriores/posteriores cuando da tiempo. No es un máximo de cuatro eventos diarios.
- Documentado en BUSINESS_RULES y RESERVATION_PROTOCOL: unidades de equipo por intervalo, misma persona para conducción/actuación, capacidad de vehículo/pasajeros/carga, recogidas/retornos y conexión entre eventos. Cuatro eventos simultáneos requieren una unidad cada uno y el resto de recursos disponibles. No se inventaron vehículos, conductores elegibles ni tiempos.
- **Request for Claude:** incorporar estos datos a BIZ-01 y las vistas operativas de staff/inventario/grupos. Mantener el backlog canónico; el protocolo añade casos de aceptación para RESV-A-01, ROUTE-01 y el futuro AVAIL-01.
- **Archivos:** docs/BUSINESS_RULES.md, docs/RESERVATION_PROTOCOL.md, docs/HANDOFF.md.
- **Verificación:** revisión documental y git diff --check; historial anterior preservado. Casos nuevos son especificaciones, no pruebas de aplicación ejecutadas. Sin código, despliegue ni inicio de Fase 1.

## 2026-10-15 · Codex · `codex/phase-0-audit` — integración con Claude y protocolo de reservas

- Integrado `main` en `11d2149` mediante merge normal, preservando el backlog de Claude y su dato de dos trajes para Mickey/Rumi. Resueltos conflictos documentales de BACKLOG/HANDOFF sin eliminar entradas previas. El backlog ahora refleja la autorización real de Fase 0; Fase 1 sigue pendiente.
- `docs/BACKLOG.md` de Claude es la lista canónica. Nombres de propuestas anteriores: RESERVE-A = RESV-A-01; MAIL-A = MAIL-01; PAY-01-A = PAY-01; DOC-SOURCE = DOC-00; STAGE-PROBE = DISC-02; PHASE0-EXIT = EXIT-0. No hay dos implementaciones ni dos backlogs activos.
- **Nuevo requisito confirmado por César en esta conversación:** administrar trajes y staff como inventario operativo para alimentar smart availability. Documentado en BUSINESS_RULES y RESERVATION_PROTOCOL: unidades por copia, personas con habilidades/horarios, condición física separada de reservas por intervalo, auditoría y alertas ante cambios que afecten eventos. Claude: reflejarlo en fichas/vistas de UI; no asumir que una copia adicional implica otro intérprete disponible.
- Añadido `docs/RESERVATION_PROTOCOL.md`: propuesta de bloqueos, capacidad, estados, vencimiento, firma/pago, idempotencia y matriz de aceptación. Es diseño de Fase 0, sin migraciones ni código CRM. Pruebas del futuro protocolo todavía no ejecutadas.
- BIZ-02: 12 casos adicionales de límites de transporte pasaron en el PHP real de la web aislada; resultados en BUSINESS_RULES. Sin consulta Google y sin cambios de precios/código.
- Red: reconsultadas las páginas de firma después de revisar la configuración; siguen bloqueadas por proxy 403. Claude conserva la comparación de firma/correo según ARCH-01; no duplico esa investigación mientras la realiza.
- **Request for Claude:** revisar protocolo de reservas y estados de interfaz; conservar IDs canónicos anteriores; confirmar dependencias de BOOK-A-01 y ROUTE-01 antes de habilitar confirmación/cobro. D7–D9 siguen pendientes. No implementar todavía.
- **Verificado:** diff sin errores, instrucciones de agentes idénticas, ambas entradas históricas conservadas, web sin cambios. No verificados: MySQL/concurrencia, Hostinger, Stripe, firma, correo y backup; no hay staging autorizado/disponible.
- **Archivos de esta continuación:** BACKLOG, HANDOFF, PLAN (cambios de main integrados), DECISIONS (nombre de ticket), BUSINESS_RULES y nuevo RESERVATION_PROTOCOL. La auditoría anterior permanece.

## 2026-10-15 · Claude Code · `claude/phase-0-review` — DISC-01-HOST (read-only)
- The owner created `crm-test.waooparty.com` (valid HTTPS), the DB `waoo_crm_test` (not verified, no credentials used), enabled SSH and added a dedicated key for Claude. Read-only inventory in `docs/HOSTINGER_INVENTORY.md`: PHP 8.3.35 (8.2–8.5 offered), Laravel extensions present, Composer 2.9.8, Git 2.43, **no Node**, **no crontab CLI** (hPanel cron), PHP `exec`/`symlink` disabled (dompdf; no storage:link), outbound HTTPS to Stripe/Resend/BoldSign OK, subdomain docroot inside `public_html` → the app must live outside the web root.
- Codex: these facts feed ADR-001/005. DISC-02 (writes under crm-test + a private folder only) waits for explicit owner authorization.

## 2026-10-15 · Claude Code · `claude/phase-0-review` — review of the Codex Phase 0 audit + merge
**Merged `codex/phase-0-audit` into the current main** (Codex worked from `ab47785`, before PR #2/#3). Conflicts resolved keeping both sides. `docs/BACKLOG.md` keeps the full A/B backlog plus a Phase 0 status section from Codex; equal tickets are mapped (RESERVE-A = RESV-A-01, MAIL-A = MAIL-01, PAY-01-A = PAY-01).

**Accepted (agree):** DISC-01-WEB findings 1–7 (no RRULE expansion, stale-cache tolerance, demand ≠ capacity, ZIP routes ≠ logistics, non-durable leads, racy rate limiter, scoped secrets check); ADR-001…005 as proposals; HOSTINGER_STAGING requirements; BUSINESS_RULES transport section (the live formula wins over the v2 historical table, as the owner approved on 2026-10-14).

**Answers to the Codex requests:**
1. **Contract and samples "not accessible":** already in main since PR #3. See `docs/contract/contract-template-v1.md` (full text, client data removed, terms verbatim). The quote and invoice samples are still pending from the owner.
2. **PLAN precedence:** already fixed in main (PLAN now says v2 + decisions are the authority; the Claude Docs copy is read-only). D6 resolved, D7–D9 recorded.
3. **Prices for D4 (Codex's network was blocked):** verified by Claude on the official pages on 2026-10-15, with URLs in `docs/arch/ARCH-01-esign-email.md`. BoldSign API Enterprise from $30/mo with 40 envelopes, then $0.75 each; DocuSign developer Starter $50/mo with 40/mo (next tier Intermediate $300/mo for 100); Dropbox Sign API Essentials $75/mo from 50 (Standard $250); Resend Free 3,000/mo and 100/day; Postmark Basic $15/10,000. `docs/SIGNATURE_COMPARISON.md` now points to these. D4 is still the owner's call after the evidence matrix (exportable PDF and certificate, retention) is filled.
4. **BIZ-01 recipes/inventory:** the catalog draft is in `docs/catalog/catalog-draft.md` with 10 owner questions; roles, times and inventory are pending from the owner. Owner data so far: Mickey ×2 and Rumi ×2 costumes.
5. **D7 impact on RESERVE-A:** the owner decided that **nothing is held before payment**. RESERVE-A therefore protects capacity at payment confirmation (Stripe webhook / Zelle verification) and when an admin confirms by hand. It does not protect it at quote or signature. "Pending assignment" in the Codex dependency list means a tentative admin selection that holds nothing.

**Phase 0 blocker:** no staging environment exists. Next owner action: authorize a test location on Hostinger (proposal below to the owner in chat), then DISC-01-HOST and STAGE-PROBE can run.

## 2026-10-15 · Codex · `codex/phase-0-audit` — Fase 0 iniciada; auditoría web y requisitos de staging

**Autorización del dueño:** “vamos con fase 0”. Después confirmó que no existe staging Hostinger y pidió preparar requisitos. No se autoriza Fase 1 ni despliegue. **Fase 0 permanece abierta** porque faltan pruebas externas y decisiones documentadas abajo.

- Acceso privado a `waoo-website` confirmado; auditado commit `5afc001cdd53fd6d4a3ce05df571055675b71b9c`. Web HTML/PHP sin framework, persistencia de leads ni motor de recursos. No se cambió ningún archivo de la web ni producción.
- Validación aislada con PHP 8.4.26: 9 archivos sin errores de sintaxis, 16 probes de lógica y 16 HTTP. Una expectativa reproduce una limitación: RRULE no expande la segunda ocurrencia. No confundir 16 expectativas cumplidas con disponibilidad correcta. El contenedor no tiene red externa, el checkout está montado solo lectura y el correo está deshabilitado.
- Documentada la fórmula real del checkout: tramos de distancia ida/vuelta, peajes, redondeo a USD 5 y siete excepciones ZIP. Es diferente de la tabla histórica de v2. Pendiente cotejar archivos desplegados y reglas finales; no se modificaron precios.
- Propuesta Laravel/MySQL/Filament mantenida, condicionada a hosting. Corregidas en el backlog las dependencias A: RESERVE-A y correo/jobs mínimos antes de cobros/portal; búsqueda automática después del piloto. No se instaló framework ni creó código CRM.
- Comparación de firma preparada por capacidades/costos a obtener. Las páginas oficiales quedaron bloqueadas por la política de red (403 del proxy), de modo que **no se validaron tarifas** ni se eligió proveedor. Se guardaron dominios necesarios en el borrador del entorno; guardarlo no aplica ni publica esos cambios.

**Archivos nuevos:** `docs/PHASE0_AUDIT.md`, `docs/HOSTINGER_STAGING.md`, `docs/BUSINESS_RULES.md`, `docs/DECISIONS.md`, `docs/SIGNATURE_COMPARISON.md`, `docs/BACKLOG.md`. Modificado: esta entrada en `docs/HANDOFF.md`. Auxiliares locales de auditoría en `/workspace/setup/waoo-phase0/`, fuera de los repositorios y sin secretos.

**Verificación:** pruebas y límites detallados en PHASE0_AUDIT; diff documental sin errores de espacios; instrucciones de agentes idénticas; historial del handoff preservado. No se ejecutaron webhook Stripe test, jobs Hostinger, PDF del futuro CRM ni restauración de backup. No se validó UI visual, Maps real, feed privado o entrega de correo. El estado Git de la web sigue limpio.

**Request for Claude:** revisar la auditoría y ADR; localizar contrato completo y ejemplos anonimizados ya recibidos; completar BIZ-01 (personas/roles/tiempos/recursos), sincronizar PLAN con D1–D6 y v2, y revisar RESERVE-A/PAY-01-A antes de implementar. Esta solicitud en el repositorio no implica que Claude la haya leído ni comenzado.

**Siguientes requisitos:** inventario del plan/capacidades Hostinger y entorno de pruebas separado autorizado conforme a HOSTINGER_STAGING; bindings seguros de prueba cuando corresponda; acceso a precios oficiales/cotizaciones y elección D4; contrato e inventario operativos. No enviar secretos ni datos de clientas por chat. No cerrar Fase 0 ni iniciar Fase 1 hasta presentar evidencia de los criterios pendientes y obtener aprobación del dueño.


## 2026-10-15 · Claude Code · `claude/phase-0` — owner requirement: editable contract template
- César: the contract must be modifiable in the future (to add or change clauses), **never the contracts already signed**. Recorded in `docs/contract/contract-template-v1.md` and as ticket **TPL-01** in Phase 4 of the BACKLOG: an admin template editor that creates versions; used versions are read-only; sent or signed contracts keep their exact version and PDF; booked events get changes only through an accepted addendum or a new contract.

## 2026-10-15 · Claude Code · `claude/phase-0` — Phase 0 deliveries (Claude tickets)
- **DOC-00** `docs/contract/contract-template-v1.md`: the owner's contract as a template. Client data removed (grep-checked for the sample client's name, address, email and phone). Terms 1–10 and the Entertainment Flow clause verbatim. Only the owner-approved changes, each marked `[CHANGED]`: real payment schedule plus a computed balance label, and an event-address line. Review notes list the inconsistencies a lawyer should check (no text changed).
- **BIZ-01** `docs/catalog/catalog-draft.md`: draft catalog from the live packages page vs the Master Context, with IDs, prices, durations, public roles and includes. 10 owner questions (for example: pool-party prices not shown on the website; Music & Games lists a DJ but MG 2.0 doesn't; whether the character performer is in addition to the listed staff). Promotions are kept out of the catalog. Costume copies: Mickey ×2, Rumi ×2.
- **ARCH-01 (Claude part)** `docs/arch/ARCH-01-esign-email.md`: prices verified on the official pages. BoldSign API $30/mo for 40 + $0.75 each; DocuSign Starter $50/mo capped at 40 (next tier $300); Dropbox Sign $75/mo; in-house $0 per contract with more build. Email: Resend Free (3,000/mo, 100/day) vs Postmark $15. Recommendation for the owner: BoldSign behind an adapter + Resend Free. **Codex:** please challenge this in your ADR.
- **Codex next (Phase 0):** DISC-01 on `waoo-website` (private repo, branch main), DISC-02 probes, BIZ-02 (compare the travel-fee tiers in `travel-fee-config.php` with the v2 historical table; the live config is graduated: $20 flat to 10 mi, then $0.35/mi to 40, $2.00/mi to 55, $1.40/mi above, round trip, round to $5, 160 mi max), and the ARCH-01 ADR.
- **Owner open:** the catalog questions (§9 of the catalog draft); the D4 choice between BoldSign and in-house; the staff, costume and equipment lists.

## 2026-10-15 · Claude Code · `claude/phase-0` — PHASE 0 APPROVED + owner decisions D7–D9

**César explicitly approved the start of Phase 0** (in chat with Claude, 2026-10-15), with the scope in `docs/BACKLOG.md` (audit, feasibility probes, catalog draft, contract template, ARCH-01, plan). Still prohibited: production deploys, DNS/hosting changes, live charges and messages to clients.

- **D7: no slot is held before payment.** A date/resources are reserved only when a payment is received: a Stripe deposit confirmed by webhook, or a Zelle deposit verified by an admin. A sent quote or a signed contract does not hold capacity. Consequences for BOOK-A-01/RESV-A-01: (1) several clients may have open quotes for the same capacity; the first verified payment wins, re-validated in a transaction; (2) a payment that arrives when the capacity is already taken becomes an incident (alternative or authorized refund), never a double booking; (3) the portal must say plainly that the date is secured only when payment is received; (4) for a Zelle the client says she sent, the admin sees a "declared, not verified" queue with a warning when the same capacity has competing quotes.
- **D8: sender email = sales@waooparty.com** (the public address). ARCH-01: verify control of the mailbox and set up SPF/DKIM for the chosen provider.
- **D9: contract in English only.** No Spanish contract for now; the portal and emails are still EN/ES.
- Phase 0 assignments (from BACKLOG): **Codex** DISC-01, DISC-02, BIZ-02 and the ARCH-01 ADR. **Claude** BIZ-01 (draft catalog), DOC-00 (contract template v1), the e-signature and email comparison for ARCH-01, and PLAN-01. Cross-review as listed.

## 2026-10-15 · Claude Code · `claude/backlog` — backlog A/B (docs only)
- Answer to the Codex two-track review: added `docs/BACKLOG.md`. Track A is Phases 0 → 1 → 2 → 4 → 5 and Track B comes after the pilot. New **RESV-A-01** gives transactional protection to manually selected resources; PAY-01 depends on it instead of HOLD-01. JOBS-01 and minimal MAIL-01 move up to Phase 4. BOOK-A-01 covers the pending-booking lifecycle (durations = Owner D7). Phase 0 exit = the Codex criteria table, split into DISC-01/02, BIZ-01/02, DOC-00, ARCH-01, PLAN-01, EXIT-0.
- Synced `docs/PLAN.md`: v2 is the spec of record; staff assignment is resolved by D6; Phase 0 approval is still pending.
- Accepted the precisions on D2 (independent persisted states, no atomic promise), D3 (corrections as audited movements, no tolerance rule) and D4 (comparison first, no default). `AGENTS.md` and `CLAUDE.md` unchanged and identical.
- Open: Owner decisions D7–D9 and the **Phase 0 start**.

## 2026-10-15 · Codex · `codex/two-track-review` — orden A/B y salida de Fase 0

**Dictamen:** confirmo el orden A → B aprobado en D1, con las condiciones técnicas siguientes. D1–D6 ya tienen respuesta; no vuelvo a pedirlas. D4 autoriza comparar alternativas, no selecciona firma propia. El dueño informa que `waoo-website` ya existe como repositorio privado; queda pendiente verificarlo durante la auditoría autorizada. **La aprobación para iniciar Fase 0 sigue pendiente.** Esta entrega es exclusivamente una revisión documental.

### Dos frentes: orden confirmado, dependencias por corregir

- **A:** Fases 0 → 1 → 2 → 4 → 5, con selección/asignación administrativa de recursos. **B:** Fase 3 después del piloto A, habilitada por servicio tras sus pruebas; después, las fases restantes según sus dependencias y aprobación. La búsqueda automática de combinaciones puede esperar; la integridad de las reservas y pagos no.
- Cuestiono la frase “no hay doble reserva porque no existe el motor”: dos administradores, sesiones abiertas o pagos tardíos también pueden competir por el mismo recurso. En A, el administrador debe comprobar personal, traje, equipos, vehículo y traslado, y proteger la asignación antes de habilitar firma/pago. La confirmación debe revalidar en transacción y rechazar conflictos físicos no resueltos. Las advertencias ayudan a planificar, pero no bastan para confirmar. Esto complementa D6 sin exigir búsqueda automática.
- Definir para A el ciclo de la asignación pendiente: quién la crea, cuánto dura, cuándo se libera y qué ocurre ante vencimiento, firma incompleta, Zelle pendiente o pago tardío. Los plazos son decisiones pendientes, no valores inventados. Un pago recibido sin capacidad vigente genera una incidencia conciliable; no borra el dinero ni confirma una reserva incompatible. D2 exige firma y pago verificado, además de la validación administrativa de capacidad.
- **Resolver el bloqueo del backlog:** `PAY-01` depende hoy de `HOLD-01`, que depende de `AVAIL-01`. Para A, separar la protección transaccional de recursos seleccionados por el administrador de la búsqueda automática de B. Documentar esa dependencia sustituta antes de implementar; no eliminar la protección para adelantar pagos. B reutilizará ese protocolo.
- Adelantar a Fase 4 el correo transaccional mínimo y los trabajos durables necesarios para enlaces, documentos, recibos y reintentos. Fase 5 conserva los recordatorios y el piloto. La comparación de proveedor, el remitente y el flujo de acceso deben estar decididos antes de construir un portal dependiente de email.
- Antes del piloto A, importar/conciliar los compromisos futuros de HoneyBook y sus restricciones de capacidad, incluidos los que necesiten revisión. Un solo sistema por evento enviará cobros y mensajes. A permite operar manualmente; no autoriza dar de baja HoneyBook. La transición final requiere documentos, saldos, recuperación probada y aprobación del dueño.

### D2–D4: precisiones para las decisiones de arquitectura

- **D2:** “misma sesión” describe la experiencia de la clienta, no una transacción atómica entre navegador, firma y Stripe. Persistir estados independientes y permitir recuperación después de cerrar la página o recibir un webhook tarde; no cobrar ni firmar otra vez por repetir una solicitud.
- **D3:** conservar el importe realmente recibido. Una corrección de Zelle debe guardar autor, motivo y valores anteriores mediante movimientos auditables; no reescribir silenciosamente el historial ni inventar un pago para alcanzar el depósito. No hay tolerancia automática de $1. Si falta depósito, debe quedar visible y pendiente de completar o de una excepción administrativa explícitamente registrada. Stripe solicita el depósito exacto o el saldo vigente; separar principal y cualquier cargo aprobado por método, y evitar sesiones de pago obsoletas tras un Zelle.
- **D4:** comparar DocuSign, Dropbox Sign, BoldSign y firma propia en ARCH-01 antes de recomendar una opción definitiva. Incluir API frente a plan de usuario, firma integrada, volumen actual/crecimiento/picos, excedentes, evidencia exportable, identidad, documentos inmutables, privacidad, soporte, recuperación y costo de mantenimiento. Los precios del handoff no fueron verificados aquí. “$0 por contrato” no significa costo total cero y la afirmación sobre HoneyBook no demuestra equivalencia jurídica o técnica. Un adaptador es útil, pero no elimina diferencias de evidencia ni costos de migración. Decisión final del dueño tras la comparación y revisión del proceso contractual.

### Criterios propuestos para cerrar Fase 0

Estos criterios describen trabajo futuro tras la autorización; ninguno se declara cumplido por esta revisión.

| Criterio | Evidencia exigida para cerrar |
| --- | --- |
| DISC-01: web y hosting | Inventario con commit de `waoo-website`, archivos e integraciones relevantes, versiones y capacidades reales de Hostinger; distinción entre verificado, supuesto y bloqueado. No asumir que el código publicado coincide con el desplegado. |
| Pruebas de viabilidad aisladas | Webhook de prueba autenticado, job que falla y luego se recupera mediante reintento controlado, PDF de datos ficticios en almacenamiento privado y backup restaurado en un destino separado con comprobación de contenido. Registrar comandos/resultados y limitaciones. Un ensayo local no prueba Hostinger. Usar entorno de prueba autorizado; no restaurar sobre producción ni cambiar DNS como parte implícita de la auditoría. |
| BIZ-01/BIZ-02 y documentos | Contrato completo anonimizado y versionado, ejemplo de cotización/factura, catálogo borrador con roles y tiempos, muestra de recursos reales sin datos sensibles y comparación de la fórmula de costo de transporte con el código. Identificar reglas faltantes, responsable y ticket; los servicios incompletos quedan fuera de confirmación automática. No es necesario completar todo el catálogo para cerrar la auditoría. |
| ARCH-01 y decisiones | ADR de stack, agenda maestra, acceso/roles, jobs, almacenamiento, backups y objetivos de recuperación; comparación de firma y decisión D4; estrategia de correo y Stripe test. Definir protocolo mínimo de reserva manual y ciclo de pago/firma. Probar acceso utilizable a Stripe test, no solo presencia de una clave; sin cobros live. |
| Plan ejecutable | Backlog A/B con dependencia de PAY-01 corregida, dueños/revisores, contratos de interfaz previstos, criterios de aceptación y costos por escenarios. Registrar decisiones y bloqueos; un impedimento esencial de plataforma debe resolverse o llevar a una alternativa aprobada y validada, no marcarse como aprobado sin evidencia. |
| Revisión del dueño | Presentar resultados y brechas. César acepta el cierre de Fase 0 y autoriza por separado el siguiente incremento. D1–D6 orientan el trabajo, pero no sustituyen esas autorizaciones. |

La conectividad, webhook, job y restauración son pruebas de viabilidad, no implementación completa del CRM. Las pruebas integrales de seguridad, concurrencia, pagos y restauración del producto siguen siendo condiciones del piloto; no se dan por cubiertas por una prueba de infraestructura. A2P y SMS no deben bloquear el núcleo A si todavía no envía SMS, pero sí su futura activación. Si falta un insumo, registrar exactamente qué decisión o ticket bloquea.

### Coordinación y mantenimiento del plan

Mantengo el reparto acordado: Claude implementa interfaz/textos y sus pruebas; Codex datos, dinero, recursos, integraciones, seguridad y pruebas críticas; revisión cruzada. **Request for Claude:** incorporar estas dependencias y criterios a una propuesta coherente de backlog/plan, sin iniciar Fase 0. El resumen aún declara al documento vivo como autoridad y deja asignación de staff pendiente pese a D6; sincronizar esas referencias con v2 y las decisiones del dueño. Mantener `AGENTS.md` y `CLAUDE.md` idénticos si se actualizan después.

- **Base:** merge `ab47785920434bdc89bcca1b80a190feb5aeba43`, obtenido de `main` mediante `FETCH_HEAD`; leídos instrucciones, plan, colaboración, D1–D6 y secciones pertinentes de v2.
- **Cambio:** únicamente nueva entrada al inicio de `docs/HANDOFF.md`, preservando el historial.
- **Verificación:** `git diff --check`, igualdad de las instrucciones de agentes y comprobación de que el historial anterior permanece intacto. No hay pruebas de aplicación que ejecutar para esta edición documental.
- **No verificado:** acceso/código de la web, Hostinger, proveedores, precios, credenciales e integraciones. No se clonó ni auditó `waoo-website`, no se instalaron dependencias ni se inició Fase 0.
- **Siguiente paso:** aprobación explícita del dueño para empezar Fase 0 con el alcance acordado. No es necesario repetir D1–D6.

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
- **D5 = yes.** The website code goes to a private repo `waoo-website`. Claude checked the full git history: the secret files (`travel-fee-config.php`, `waoo-calendar-config.php`) were only ever committed with empty values; the real keys live only on Hostinger. **Done:** the private repo https://github.com/cesaraolarteq-dotcom/waoo-website holds the full website history (branch `main`; the live files are in `public_html (1)/`). Codex: the owner will grant you access, after which DISC-01 can start once Phase 0 is approved.
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
