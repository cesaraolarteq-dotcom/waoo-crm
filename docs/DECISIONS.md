# Decisiones técnicas — historial de propuestas de Fase 0

**Vigente:** [ARCH-01 firmado técnicamente](arch/ARCH-01-ADR.md), con evidencia P1–P5, D4 = firma propia, ambos owner-admin equivalentes y reportes a sales@waooparty.com. Las propuestas siguientes se conservan como historial y quedan sustituidas donde difieran. Cierre de Fase 0 e inicio de Fase 1 dependen del dueño.

2026-10-15. Estado: **propuestas para revisión; arquitectura final pendiente de evidencia de Hostinger**. D1–D6 están aprobadas en HANDOFF; la elección de stack/proveedor no lo está. Fase 0 está autorizada; Fase 1 no.

## ADR-001: conservar web y separar CRM modular

La inspección confirma HTML/PHP sin framework. Recomiendo conservar esa web y crear un monolito modular Laravel + MySQL/InnoDB con Filament para administración, sujeto a capacidades del hosting. Portal y endpoints tendrán autorización por objeto en servidor; Filament no sustituye el portal. Catálogo, cotizaciones, pagos, contratos, recursos y mensajes comparten estados persistidos y transacciones.

Alternativas: ampliar PHP sin framework ahorra arranque pero obliga a construir auth, migraciones, jobs y controles repetidos; Node/TypeScript añade otro runtime sin una aplicación previa que reutilizar. No se descartan si la viabilidad/costo lo justifica. La elección PHP encaja con lo observado, pero no se instala Laravel ni se eligen versiones finales hasta comprobar compatibilidad mantenida de PHP/Laravel/Filament/MySQL en el destino. PHP 8.4.26 local es un runtime de auditoría, no una versión exigida al hosting.

## ADR-002: agenda y reservas manuales seguras

CRM como fuente maestra; Google Calendar como espejo con IDs, reintentos y alerta por retraso. El iCal existente no representa recursos ni expande recurrencias; importar compromisos con revisión, no convertir sus niveles diarios en disponibilidad.

D7: A selecciona recursos tentativamente y comprueba viaje/preparación sin reservar capacidad. La protección transaccional se aplica al depósito verificado y solo si la revalidación permite asignar recursos. Diseño candidato: bloqueo de filas de los recursos en orden estable para serializar operaciones concurrentes, seguido de consulta/revalidación de intervalos y vecinos; todas las escrituras de agenda usan ese protocolo. Debe probarse con MySQL real, incluyendo eventos que cruzan medianoche, cambios de zona horaria, reasignaciones y dos administradores. No basta confiar en una validación del navegador. No hay holds antes del pago por D7. La aprobación de excepciones no aparta horario; ver APPROVAL_FLOW.md.

B añade búsqueda automática de combinaciones, no otro mecanismo de integridad. Un conflicto físico no resuelto bloquea confirmación. Los errores de Maps o de inventario pasan a revisión, sin capacidad inventada.

## ADR-003: dinero y efectos externos

Cotizaciones versionadas y movimientos en centavos. Pagos Stripe: intención vinculada al saldo/revisión, webhook firmado sobre cuerpo original, persistencia, deduplicación por evento y transacción; confirmación según estado confiable. Zelle: verificación manual, referencia, autor y ajustes auditables. No aplicar tolerancia automática ni convertir diferencia contable en dinero recibido. Si falta depósito, completar o registrar excepción explícita autorizada.

Jobs/outbox durables desde la entrega de firma/pago. Un job consulta el estado actual antes de mandar un recordatorio; reconciliación recupera efectos externos si un proceso cae. La experiencia “misma sesión” permite reanudar firma y pago sin duplicarlos. Un pago tardío sin capacidad entra a incidencias; se conserva el movimiento. Definir resolución/reembolso aprobado, no ejecución automática inventada.

## ADR-004: documentos, firma y acceso

Comparar proveedores antes de decidir (SIGNATURE_COMPARISON.md). Contrato y evidencia inmutables, documento exacto con hash, copia accesible al cliente, adendas tras cambios. Enlaces expiran, se revocan y otorgan acceso limitado al evento; no dar acceso a todos los eventos de un contacto por un enlace aislado. Administradores con 2FA; logs sin tokens/PII innecesaria. Almacenamiento privado y restauración comprobada.

## ADR-005: hosting, jobs y recuperación

Hostinger se evalúa con HOSTINGER_STAGING.md. Preferir worker supervisado si disponible; alternativa cron con lock, tiempos/reintentos medidos y monitoreo. Si no satisface requisitos, presentar hosting alternativo y costos para aprobación. No mover producción ni comprar servicios en esta fase.

Datos requeridos para costos: plan/límites efectivos; tamaño documentos y backups; consultas Maps; correo/SMS; firma API; necesidad de worker; mantenimiento. Los objetivos propuestos de recuperación de v2 se contrastan con esas capacidades. No prometer que hosting extra cuesta cero.

## Fuente documental

Propuesta: decisiones más recientes del dueño → PROJECT_PLAN v2 con ajustes A/B aprobados → resumen PLAN. PLAN ya refleja v2 y las decisiones del dueño como autoridad; la copia viva es de consulta. Esta entrega no reescribe políticas ni instrucciones previas silenciosamente.

## Evidencia nueva de hosting y revisión de proveedores

Fuente: inventario de solo lectura de Claude en HOSTINGER_INVENTORY.md, integrado desde main `81287c2`. No repetido por Codex: PHP 8.3.35, Composer 2.9.8, Node ausente, cron desde hPanel, exec/symlink deshabilitados; subdominio de prueba HTTPS creado por el dueño. Base creada según reporte, versión del servidor/credenciales/transacciones aún no comprobadas. Ya existe staging; sustituye referencias históricas a su ausencia. Escrituras DISC-02 siguen pendientes de autorización específica/acceso; esta entrega no escribe en Hostinger.

Consecuencias propuestas: compilar assets fuera del hosting; evaluar PDF puro PHP (p. ej. dompdf) sin binario externo y probar fuentes/imágenes/plantilla real. Aplicación, vendor, .env y documentos fuera de public_html; servir archivos privados mediante controladores autorizados, sin depender de storage:link. Cron con lock y ejecución acotada de cola, midiendo solapamientos y reintentos; no asumir worker permanente. Verificar servidor MySQL/MariaDB real y transacciones, no inferirlo por versión del cliente. Ni espacio actualmente usado ni ausencia de error HTTP prueban cuota, capacidad o integración completa.

Comparación de precios: se incorpora la evidencia de Claude en arch/ARCH-01-esign-email.md, no una verificación independiente de Codex. BoldSign + Resend es una candidata razonable, aún no elección D4. Revisar exportación PDF/certificado, retención, límites de sandbox y excedentes antes de decidir. Para Resend, 400 correos/mes no garantiza respetar 100/día en importaciones o reintentos: programar cola, gestionar límites y medir entregabilidad. El rango de USD 0–30 es subtotal de firma/email, no costo operativo total: faltan Maps, backups, monitoreo, procesamiento y mantenimiento. Costos de sobrecuota DocuSign/Dropbox no confirmados no implican que subir de plan sea la única opción.

## 2026-10-26 · César (chat with Claude and his checklist page)
- Test data: every party created inside the CRM on crm-test was test data. Claude removed the 8 CRM-created events (with their payments, contracts, links and Google Calendar copies) and 3 test customers; the 23 HoneyBook events stay. DB backup `~/backups-crm-test/db-before-purge-tests-*.sql.gz`.
- WhatsApp Business number: the one ending in **8830**.
- HoneyBook: no cut-off date; César keeps paying until he decides it is no longer needed.
- Extras: any service already in the catalog; no owner approval; allowed when the system shows availability and until **7 days** before the party.
- Website availability rules for AVAIL-WEB-01 confirmed: parties 8 a.m.–1 a.m. Houston, maximum 10 parties per operating day, under 14 days of notice needs owner approval.
- History import: approved; Claude runs the dry run first and imports after showing the summary.
- Production: once crm-test is complete, move to crm.waooparty.com and connect live Stripe.
- Twilio: no number transfer; waiting for Twilio's approval.

## 2026-10-26 · César · Email provider: Resend
- César chose **Resend** on the comparison page (estimate 40 parties + 80 non-booking inquiries ≈ 490 emails/month, inside the free plan: 3,000/month, 100/day).
- Sender proposed by Claude: `Waoo Party <sales@waooparty.com>` with replies to sales@waooparty.com (the address the website already uses). Domain verification through Resend's DNS records (DKIM + SPF/MX on Resend's `send.` subdomain, so Hostinger mail is not affected) plus a DMARC record if none exists.
- Activation stays explicit: sandbox/crm-test first, live only after César's OK.

## 2026-10-27 · César · Resend preparation without outgoing email
- Owner explicitly: “no quiero que aun slagan correeo hasta que el crm este 100% operativo”. Preparation of provider/DNS/keys/backend is separate from activation; no real test email to clients or owners while this hold is active.
- Resend/provider/key selection does not open sending. Independent delivery gate defaultsOFF, no automatic percentage/CI/time activation; operational acceptance and a new explicit sending authorization are required. Existing MAIL-RESEND-01 binding instructions from October26 are superseded to that extent.
- No host setting, DNS change, provider registration or actual email was performed by Codex in implementing this restriction. Claude must retain/check the host hold without a test send. Actual deliverability verification waits for the later sending authorization.

## 2026-10-28 · César · Short-notice approval: 14 days for the website only
- Asked whether the 14-day owner-approval rule should also apply inside the CRM, he answered **"b"**: only bookings that come **from the website** need owner approval under 14 Houston calendar days. Inside the CRM (owner "Confirmar capacidad" form and `ConfirmBooking`), the short-notice reason stays at **under 7 days**, because the owner is already deciding when he confirms. Quick booking is unchanged.

## 2026-10-28 · César · Staffing of the animation packages
- Música y Juegos, Música y Juegos 2.0 and Waoo = **1 animador + 2 recreadores**. In Waoo one of the two recreadores paints faces (3 people in total).
- Applied on crm-test through `SaveVariant` with the owner as actor (audited): Música y Juegos 2.0 v4 (`host` 1 → 2); Waoo v4 (`entertainer` 1, `host` 1, `face_painter` 1). Música y Juegos was already 1 + 2.
- **Production catalog import must carry the same staffing** (the owner catalog source still had the old values for 2.0 and Waoo).

## 2026-10-28 · César · Notes from the owner's phase checklist
**Availability is capacity by role pools, not named people (AVAIL-WEB-01 S2 input).** César: the system should keep a count of the staff available that day by skill and block *combinations*, not specific people. Example: 3 parties at once (2 Waoo + 1 Música y Juegos) use 3 animadores + 6 recreadores (2 of them face painters) + drivers; if those counts are used up at that time, only earlier or later start times are offered. A person counts once at a time: an animador already used cannot also count as a recreador. Drivers are a pool too. Equipment is a pool as well: if two Waoo parties overlap and there are only two face-paint kits ("maletas"), a face-painting add-on cannot be sold for that time. When a pool is full, suggest the nearest earlier/later times (same day first). Named assignment stays an owner step at confirmation; the pool check is what the website and quick booking rely on. César offered to go deeper on this; Claude will collect the exact counts with him.

**Pickups.** Drivers always pick every staff member up at home. Later: store each staff address, compute the pickup route (driver's home → each staff home → party) with a start time for each person, and let the owner remove someone who goes straight to the party.

**Staff pay (STAFFPAY-01).** Known rates stand (recreador $25/h, animador $35/h, personaje $60/h, personaje de traje $80/h). "Personaje de traje" = wig characters (Disney princesses, K-Pop Rumi…). Preparation time is included in the rate (that is why it is higher). Only event time is paid, with a **$50 minimum per service** (e.g. a 1-hour service pays at least $50). Driver transport pay varies with the number of stops and distance (formula still to be given). Tips are split equally among everyone at the party.

**Costs and margin.** Materials: about **$30 per service** as a default. Gas is part of the travel fee, and the **travel fee is not profit** (exclude it from margin).

**Setup/breakdown defaults.** The 6 services without times now have 20/20 minutes on crm-test (SaveVariant, audited): Mural creativo, Pintacaritas agregado, Personajes inflables y bolas disco, 2 chisperos, 4 chisperos, Pool Party + Cañón de espuma.

**Weekly blocks** (vacations, days off) are entered week by week by the owner.

## 2026-10-28 · César · How the signed contract looks
- César saw the final PDF with an empty client line and "Provider acceptance pending" and did not recognise it as a signed contract (the proof was only on the appended last page).
- **Decision 1:** the final PDF must look signed on the contract pages too: the client's typed signature and date on the "Client" line, the electronic-signature record under the signature table, and a visible "SIGNED / FIRMADO" mark next to the contract number on page 1. The appended "Electronic Signature Record" page stays. The presented (unsigned) HTML/PDF and their hashes stay stored unchanged as evidence.
- **Decision 2 (option a):** Waoo Party LLC accepts at the moment the client signs (César issued the contract). The provider line shows "Signed <same date/time>" instead of "Provider acceptance pending", and the record page states that provider acceptance was recorded at the client's signature by owner decision of 2026-10-28.
- To be included in the one-page summary for the lawyer (go-live checklist).

## 2026-10-28 · César · Driver pay
- There is no formula for the driver's pay: the owner decides it per party and enters it by hand as a "Transporte del staff" line in the event's "Costos y margen". STAFFPAY-01 must not compute it; it reads the amount entered per party.

## 2026-10-28 · César · How Claude and Codex split the work from now on
- César finds progress too slow. From now on **Claude builds whole features** (screens and the backend behind them, with tests), and **Codex reviews** what touches money, signatures or security, and keeps building the availability engine. Codex merges in the priority order below and keeps PRs small and quick to review.
