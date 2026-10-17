# Modelo lógico para revisión — no son migraciones

2026-10-15 · Codex · Referencia de ARCH-01 para UX y DATA-01. Amplía PROJECT_PLAN §15 con decisiones del dueño. DATA-01 inicia su implementación por incrementos de Fase 1; los probes siguen siendo tablas de ensayo independientes. El esquema inicial incluye users/sessions, customers/events/event_services, personajes/unidades de recursos y habilidades JSON, compatibilidad de trajes, variantes/precios versionados, bloqueos/reservas/evidencia de compromiso externo, revisión operativa y audit_entries. No representa todavía el ledger, firma, viajes detallados, tarifas del staff, cotizaciones, mensajes o reportes de fases posteriores. Esas áreas conservan este contrato lógico y se añadirán con migraciones posteriores; no se declaran implementadas.

| Área | Entidades / relaciones propuestas | Invariantes y correspondencia UI |
| --- | --- | --- |
| Identidad | User, Role, Permission, Session, AuditLog | Dos owner-admin equivalentes con 2FA; staff no hereda permisos admin por tener ficha operativa. Clientes no acceden a documentos ajenos. |
| Clientes/eventos | Customer → Event; Contact; EventLocationRevision | Evento conserva fecha/hora, UTC y America/Chicago, dirección versionada. Información infantil mínima. No mezclar estado de lead, pago o contrato. |
| Catálogo | Service → Variant → PriceVersion; Requirement por segmento/rol/recurso; AddOnCompatibility | Una variante define cantidad/precio, no fórmula universal +150/personaje. Precios aceptados no cambian con edición del catálogo. |
| Recursos | Staff, Skill, StaffSkill, Availability; Character → CostumeUnit; Compatibility; EquipmentUnit; Vehicle | Personas con múltiples habilidades, trajes por copia, 4 speakers como unidades. Condición física separada de ocupación. Vehículo con capacidad/asientos/carga, conductor es Staff. |
| Agenda | TentativeAssignment; ResourceReservation; Maintenance/ExternalBlock; TimelineSegment | Tentativa no bloquea por D7; reserva firme respaldada por depósito verificado/asignación manual de compromiso ya verificado. Todos los cambios de capacidad usan el protocolo transaccional. |
| Logística | Trip, TripStop, ResourceTransfer, RouteEstimate | Conductor, pasajeros, equipo, vehículo e itinerario; salida y entrada a vecinos. Fuente/fecha objetivo/consulta y versiones de ruta; sin promesas por tráfico actual de otro día. |
| Excepciones | ApprovalRequest, ApprovalDecision | Motivos: última hora y adelanto hasta 15 min. Autor, decisión, evidencia, versión; puede resolver ambos en una acción, no bloquea recursos ni sobreescribe conflicto. |
| Cotización | Quote → QuoteRevision → QuoteLine; Acceptance | USD en centavos, estado de cálculo completo o requiere revisión. ZIP sin costo no se transforma en cero. Depósito/redondeo explícitos. |
| Dinero | Invoice/Line; PaymentAttempt; ProviderEvent; Payment; Allocation; Refund; Credit; Adjustment | Evento único del proveedor y transacción financiera única por cuenta/modo/proveedor; conciliación, saldo exacto y sobrepago. Correcciones Zelle preservan historial. |
| Firma | TemplateVersion; ContractInstance; SignedArtifact; SignatureEvidence; Addendum | Instancia fija versión y bytes/hash aceptados. PDF/documento y evidencia inmutables, adjuntos privados. Plantilla usada no se edita. Solo contrato inglés por D9. |
| Costos | RateVersion; WorkAssignment; TimeEntry; StaffCompensation; TransportCost; Expense; CostSnapshot | Tasas/horas por período y rol, estimado/real separados, reparto de costo compartido explícito. Datos admin-only en backend y exportaciones. |
| Cambios | ChangeRequest; RescheduleRevision; CancellationDecision | Versionar recursos, fecha, dinero/documentos; conservar original si falla propuesta. Motivo y actor para dispensa/override, no para doble asignación física. |
| Mensajes/reportes | OutboxJob; Message; Consent; ReportRun | Idempotencia por acción/revisión/canal; revalidación antes de enviar. ReportRun identifica período, zona, corte, definición y destinatario; no disparar dos emails al reintentar. |

## Estados que necesita la interfaz

Reserva: solicitud / capacidad asignada con pago / confirmada / cancelada / completada. D2 exige firma para confirmación completa; depósito verificado puede respaldar capacidad mientras falta firma. Registrar incidencias como entidad/alerta separada, no perder el pago ni esconder inconsistencias.

Pago: por transacción pendiente/procesando/recibido/fallido/reembolsado/disputado; saldo agregado calculado. Contrato: borrador/enviado/firmado/sustituido/anulado. Operación: sin asignar/asignada/staff confirmado/lista/en servicio/completada/incidencia. Aprobaciones tienen su propio estado. UI puede resumirlos en badges sin convertirlos en un único enum ni exponer todo a cada rol.

## Fase 1 sin pagos integrados

Se puede probar el modelo con fixtures ficticias de compromisos cuyo depósito ya se verificó y con registro administrativo explícito de esa referencia/evidencia. No fabricar un pago al crear un evento ni activar cobros como parte de DATA-01. Dos cotizaciones tentativas pueden competir; dos reservas firmes no. Para uso real antes de PAY-01/02, acordar un flujo manual verificable del compromiso externo; si no está disponible, limitar las pruebas a fixtures y no declarar reservas confirmadas.

## Semántica de reportes

Separar cantidad de eventos reservados, servicios/paquetes vendidos (un evento puede contener varios), cobros por fecha de pago, saldo al corte, costo estimado de próximos eventos y costo real del período. Las anulaciones/refunds no se esconden cambiando totales históricos. Definir período lunes/domingo y mensual, qué significa “esta semana” en un email del lunes y hora de envío con el dueño. Un nombre corto en la UI debe conservar una definición precisa accesible.

## Pruebas relevantes antes del piloto

Permisos por objeto y rol; concurrencia real de múltiples recursos/vecinos; recurrencias importadas; DST; webhook fuera de orden y dos eventos para un mismo pago; corrección Zelle/sobrepago; cambio de plantilla sin alterar firma histórica; reprogramación fallida conserva original; reportes con mismo ledger/cortes reproducibles; jobs que se reintentan sin duplicar efectos. Son criterios futuros, no resultados de los probes P1–P5.

## Próximos campos señalados en la revisión de Claude

| Dato todavía pendiente | Ticket responsable / momento |
| --- | --- |
| ZIP y detalles estructurados de ubicación | OPS-01: postal_code opcional ya incorporado en la rama operativa, como texto ZIP/ZIP+4; dirección estructurada adicional sigue pendiente antes de los formularios operativos. BOOK-QUICK reutiliza esa ubicación. Validar sintaxis del ZIP no verifica una dirección/ruta ni calcula el costo de transporte. |
| Personajes elegidos por servicio/evento | OPS-01, selección explícita por línea y revisión de catálogo antes de pasos 5–6. Character/CostumeUnit y requisitos ya existen; no se infiere una selección del cliente de una copia concreta. |
| Nombre/edad infantil, tema y notas opcionales | BOOK-QUICK, ampliación del formulario de detalles en Fase 4. Guardar solo lo necesario, con validación y acceso privado; no bloquear bootstrap ni requerir datos infantiles para un borrador. |
| Objetivo de depósito y saldo exacto | QUOTE-01 calcula objetivo/cotización versionada; PAY-01/PAY-02 aportan ledger, cobros verificados y saldo calculado; BOOK-QUICK integra esos valores en el flujo. La evidencia externa de Fase 1 no sustituye ese ledger ni un campo de saldo editable. |

Esta lista asigna las ampliaciones; no declara esos tickets implementados ni habilita cobros/portal en Fase 1.

## Incremento de personajes por evento y tipo (codex/record-extensions)

Implementado mediante migración aditiva `2026_10_16_140000_add_event_character_selections`: events.event_type nullable, service_variants.character_quantity (0 por defecto) / character_options JSON, event_service_characters (event_service_id, character_id, quantity, character_name_snapshot, único servicio/personaje). Las variantes existentes conservan sus recetas fijas; no se presume un catálogo configurable sin datos del dueño. Los personajes elegidos compilan requisitos efectivos dentro del snapshot por servicio; las selecciones incompletas no permiten confirmar capacidad. Personajes sin traje usan la receta de personal declarada, sin asignarles traje por inferencia. Cantidad de personajes elegidos y unidades físicas disponibles son medidas distintas.

La desvinculación de compatibilidad elimina solo el pivote actual, conserva la evidencia en audit_entries y rechaza una pareja con asignación conjunta futura/en curso bajo los mismos candados que la reserva. No hay registro de pareja/intérprete exacta en resource_reservations, por lo que esta protección es conservadora. La baja no invalida un compromiso ni borra historia de eventos.

### LEAD-01 website prospects (sandbox)

Immutable lead_submissions stores the validated original website payload, exact-body SHA-256, company-unique request UUID and submitted/received times. The signature authenticates timestamp + UUID + raw body; signatures/secrets are never stored. Mutable leads has one submission, guarded status/version/owner note and optional actual QuickBook/event linkage. Duplicate hints never merge or edit customer records. Booking linkage is part of QuickBook's existing core transaction; portal-link recovery remains after that core commit. No fee, event, customer or message is created by intake. See docs/LEAD-01-API.md for stateless transport and owner APIs.

### ROUTE-01 travel quotes and accepted evidence

Immutable travel_fee_quotes contains address/rules hashes, required route measures, rules/input snapshots, nullable fee/status/expiry and admin. Pricing hash excludes operational quota/TTL settings. travel_route_daily_usage reserves each attempted Google call under a row lock for the Chicago date. CRM counter is separate from the website's existing counter. events.travel_fee_evidence is nullable and hidden from serialization; owner API exposes it explicitly. Acceptance binds the normalized address/ZIP, rules and expiry; manual overrides preserve an owner reason. No price/availability is inferred from unknown results. Owner-approved rules copied from website5afc001/unchanged083563b; actual22 calibration cases remain pending.

### OUTFIT-01 optional costume versions

Migration280000 adds nullable characters.outfits (owner version-name list), resources.outfit (costume units only), event_service_characters.outfit and private owner note. The old character-only service selection unique is replaced by a supporting lookup index; compile enforces one row per exact character+outfit, with the total quantity still limited by the package. Rollback refuses before DDL if reinstating the former unique would collapse multiple selections; use a forward migration for populated versioned history. Existing140000 index-before-column rollback fix stays intact.

Character list edits and unit saves share a Character row lock; removal/rename of a version referenced by an active costume is rejected. Draft selection validates the current list and injects the exact outfit into costume requirements; a generic or different unit cannot satisfy it. A version without a declared costume recipe is rejected. No version changes money. Existing draft saves omitting all character selections preserve them; Claude's explicit row mappers must include outfit/note, and explicit null clears the choice.

Financial/contract character snapshots store nonnull outfit with the original owner spelling, never the owner note. Contract print and both client portal projections format `Character (Version)`. Owner-only event rows/audit retain notes; client detail approval preserves and compares both fields while retaining agreed financial values. No customer form can select/overwrite these internal fields. See docs/OUTFIT-01-API.md.

### Atomic contact corrections during QuickBook

No schema change. Optional customer_update is transient validated input, bound to the existing mutation receipt hash; it is never added to event fields. The existing customer row is locked/version-checked and changed through SaveCustomer inside QuickBook’s core transaction. Its update/version/audit rolls back with failed recipe/payment/booking writes. Successful retry lookup precedes the version check, preserving replay after the original correction and any later independent customer edit. Grant issuance/recovery remains after the durable core commit. See docs/QUICK-BOOK-CONTACT-API.md; Claude’s adapter must remove the separate pre-save.

### COMMS-01 recoverable portal tokens (sandbox)

Migration290000 adds nullable encrypted_token to portal_grants, no backfill. New issuance creates its row/hash, then authenticated encrypted versioned payload bound to grant/event/customer/expiry/scopes, then metadata-only audit, in the same transaction. Existing hashes still authenticate clients; missing old ciphertext cannot be reconstructed. The encrypted field is guarded/hidden and never included in generic owner/client projections or audit snapshots.

RevealPortalGrantLink locks grant→event, verifies active/current identity and cryptographic binding/hash, audits IDs and returns only the same URL/expiry DTO to a fresh authorized owner. It does not mutate link lifetime, money, signature, presentations or existing QuickBook linkage. Revoke clears ciphertext atomically; expired links cannot reveal regardless of deferred purge. Key rotation uses Laravel's existing previous keys; keep key/backup access controlled. A grant-storage failure after QuickBook's durable core commit preserves its payment and existing pending-link exception/recovery. No sending provider or automated communication schema is added.

### QUOTE-01 A: owner quote foundation (2026-10-17)

Migration2026_10_17_300000 adds quotes (one aggregate per existing source event, immutable customer binding, optional lead provenance, current revision/version/status/validity/locale/private note) and immutable quote_revisions. Each revision freezes source event version, customer/date/address/services/character outfits, private route evidence, explicit server-approved discount lines and integer gross/discount/final/deposit amounts. Historical supersession is derived from current_revision rather than rewriting old evidence. Quote writes do not create/change FinancialRevision, ledger, contracts, portal grants or reservations. Source88d53f3/API2d90794; QUOTE B/C adds the evidence and financial binding described below; independent review and UI activation remain pending.


### QUOTE-01 B/C: frozen presentation, client decision and financial binding

Migration310000 adds a nullable restrictive quote_revision_id FK to portal_grants, plus immutable quote_presentations (one per revision, client-whitelisted payload/hash, revision hash, private PDF path/hash, outcome-specific frozen consent), grant-specific quote_pdf_views, and immutable quote_decisions (unique quote/revision, outcome, typed identity, trusted IP/user-agent/time and exact presentation/consent hashes). Quote-only grants use encrypted codec v2 bound to revision; legacy event tokens retain codec v1 and existing token hashes unchanged. Revision invalidation/revocation clears only affected quote ciphertext. No public PDF path or private discount reason appears in the client projection.

Migration320000 adds immutable quote_conversions (unique quote/event/accepted decision/initial financial revision) and nullable quote_revision_id plus discount_cents/default0 and discounts_snapshot to financial_revisions. Explicit owner conversion copies the accepted immutable amounts and labels without creating money, a contract, a grant or resources. Current catalog changes cannot reprice this agreement. Later draft address/detail-only reconciliation may create a new financial revision with the same accepted money, while changes to services, characters/outfits/recipe, customer, party time or travel amount require the separate change/requote flow.

Quote-bound confirmation checks current financial/event versions and actual net test ledger receipts before using the accepted discounted deposit target; resource, short-notice and transport rules remain mandatory. Contract commercial snapshots print visible discount lines; legal terms and signature records remain separate. Migration down() drops dependent tables and foreign keys before columns; populated SQLite up/down/up and the full MariaDB suite exercise compatibility. Feature gates remain default off and approved quote consent config is empty; see docs/QUOTE-PORTAL-API.md.

### IMPORT-01 A: private source staging only

Migration330000 adds import_batches (current profile/version/status/fingerprint/hash/reconciliation), import_files (private immutable original bytes/hash/role and indexed headers), import_rows (current versioned necessary source/normalized values/errors/hints), immutable import_preview_versions (profile/preview hashes and non-PII counts), and a singleton import_staging_locks row. Unique active content+profile fingerprint and owner→singleton→batch locks deduplicate concurrent uploads; batch-only discard serializes against profile without acquiring the singleton in reverse order.

Generic mutation receipts for stage/profile reference the immutable preview-version ID. Returned mutation_result identifies the exact original preview even when current metadata has advanced; discard never resurrects its payload. Reprofile replaces only uncommitted staged rows, not original bytes or domain records. ImportProject identity is an explicit case-sensitive source ID/hash, not a row number or current catalog price. Future B/C must add durable domain linkage and honest historical-money/resource reconciliation; no such linkage, money, resources, contract, client portal grant or message is created by A.

Discard clears staged rows, profile/name/header payloads and active fingerprint before filesystem cleanup, preserving only non-PII evidence. cleanup_pending remains retryable until private deletion succeeds; discarded data cannot be read/reprofiled or recovered by an old UUID. Source path access is backend-generated, shape-checked and hidden from serialization; no public raw CSV URL. Unknown amounts stay null and totals disclose coverage/conflicts. Feature sandbox_imports defaults false and production is denied; source contract docs/IMPORT-01-API.md.

### IMPORT-01 fixed24 source agreement and normal capacity reconciliation

Migration340000 adds imported_project_links (case-sensitive source ID hash, unique event, current source revision and explicit version/intent), immutable imported_project_revisions (original24 cells/normalized source/mapping/file hash/event version), immutable imported_ledger_lines (signed source cents, original method, day-only received_date), and versioned import_service_mappings (suggestions only). Original money is never recalculated using current catalog prices. Only the current imported revision contributes to the source-money summary; old revisions remain evidence, not additive receipts. Existing customer linkage requires its displayed version and does not overwrite contact information.

booking_evidence receives nullable imported_revision_id/received_date and permits a nullable received_at for day-only source evidence; no invented timestamp. Rollback refuses before DDL if day-only evidence remains. Legacy data and timestamps survive populated SQLite up/down/up. The main MariaDB suite exercises up/down; synthetic evidence is explicitly removed only during disposable test teardown so the production rollback refusal remains effective.

Imported confirmation uses the common capacity engine and original historical total/paid source evidence. Resource/block/reservation reads after resource locks use current locking reads, including the neighboring event, so MariaDB repeatable-read snapshots cannot hide a competing committed reservation. Two actual process confirmations sharing resources yield one winner and one422 while both source ledgers remain intact. No fake test ledger, financial revision, CRM signature or portal grant. New CRM finance/contracts/quotes/links for imported events are blocked pending a separately reviewed cutover lifecycle; source contract_signed is only a HoneyBook reference.

Metadata-only audit scope prevents nested customer/event source cells from entering the audit log and restores normal auditing even on exceptions. Source data stays in private immutable domain records. Coverage separately compares source/imported money and flags invalid/unmapped/outdated/draft/reserved rows; file deletion is retryable and never implies completed capacity, complete HoneyBook export, pilot approval or public availability. Exact API: docs/IMPORT-FUTURE-V1-API.md. Driver/all-skills request from #46 remains a proposal in docs/STAFF-SKILLS-API-PROPOSAL.md, no active staffing policy introduced here.
