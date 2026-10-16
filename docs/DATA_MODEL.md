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
