# Auditoría de disponibilidad y reserva — reporte actualizado para Claude

**Fecha:**29 de octubre de2026, Houston. **Veredicto:** requiere cambios antes de habilitar reserva automática con cobro. No fusionar como funcionalidad terminada solo porque CI está verde.

## Alcance y versión exacta

Revisados cálculo de disponibilidad, catálogo/recursos, fuentes de calendario, confirmación interna, pago rápido/ordinario, vencimiento y mensaje del portal. Código actual auditado: **PR159 HEAD `08e638d7e791b8e10f63adc6e44f78fbadaf3e87`**, base `claude/audit-fixes`; seis checks de GitHub SUCCESS. Se volvió a consultar y obtener el código antes de ejecutar las pruebas: este reporte reemplaza los señalamientos obsoletos de1fcdadc/f5b0565. No es una auditoría de todo el CRM ni certificación del despliegue.

Claude ya integró el solver de intervalos completos y conductor del propio equipo. Reconozco esa corrección: no sigo reportando el cambio de empleado a mitad de fiesta como fallo actual. La prueba añadida de asignación fija también pasa. El preview y pago apagado constan en HANDOFF, no se inspeccionó el host para verificarlos.

## Reglas de César que prevalecen

4 fiestas simultáneas,10/día,24h; falta de personal permite aprobación del dueño;5+ personajes siempre aprobación, Other/desconocido sin pago previo a confirmar; conductor normalmente miembro del equipo. **Nueva aclaración:** al reutilizar un recurso, desde final del desmontaje hasta inicio del siguiente montaje debe caber **ruta real +30 minutos extra**. Sustituye la interpretación anterior de mi PR160 `travel + max(30, breakdown + setup + contingency)`. No fusionar ese fragmento sin actualizarlo. No pedir otra vez datos de staff que ya están en CRM.

## Fallos reproducidos en08e638d

### P1 · R1. El portal anuncia reserva después de pago tardío y sin recursos

**Evidencia ejecutada:** crear apartado/checkout ficticio, avanzar49h, vencer y liberar stock, recibir webhook de pago completo, preparar/firma de contrato y GET `/portal`. Estado real `paid_needs_review`, **0 resource_reservations**, pero `vm.reserved=true`. Prueba1/12 aserciones: falla precisamente la última expectativa de que reserved sea false; pasos anteriores pasan.

Referencias: `Booking/PromoteQuickPayment.php` conserva revisión y dinero; `Booking/ReadQuickPayment.php:26` permite firma para paid_needs_review; `Documents/ClientContractSource.php:27` acepta ese estado; `Http/Controllers/Portal/PortalPageController.php:136` calcula reserved solo con firma+depósito. No se confirmó capacidad en DB: el fallo comprobado es la promesa visible al cliente.

**Cambio:** derivar confirmación visible de la admisión operativa vigente, respetar incidencias y paid_needs_review. Conservar el recibo real. Revisar si firma en ese estado debe permitirse conforme al flujo aprobado; aunque se permita, nunca debe convertir revisión en «reservada». Probar también contrato firmado ANTES del pago tardío.

### P1 · R2. Cinco personajes se ofrecen sin aprobación

`CheckWebsiteCandidates.php:80,103–116` suma cantidades para completitud pero no aplica total>=5 a la decisión de aprobación. Fixture con5 trajes y6 empleados retorna **available**, esperado owner_approval_required; prueba falló. No depende de escasez física ni de anticipación menor14d.

**Cambio:** imponer la regla en servidor y en admisión antes de pago, también si el cliente cambia cantidades o llama directamente al endpoint. Pruebas4/5 personajes y total repartido entre personajes.

### P1 · R3. Requisitos de vehículo se ignoran

`WebsiteCapacity.php:146–152` solo compila staff/costume/equipment; omite `kind=vehicle` aunque el solver soporta vehículos/asientos. Servicio con vehículo obligatorio y0 vehículos no devuelve unavailable; prueba falló. Leer vehículos en el constructor no basta si no se crean sus slots.

**Cambio:** incluir requisito y elegibilidad física/estado/capacidad. Asientos conocidos y conductor asignado cuando corresponde; no convertir asientos desconocidos en1 como prueba. Casos0 vehículos, capacidad insuficiente y vehículo bloqueado.

### P1 · R4. No se comprueba quién puede usar el traje

`WebsiteCapacity.php:146–151` cruza personaje/skill de forma independiente; no utiliza `costume_compatibilities`. `ConfirmBooking.php:112–128` sí exige la pareja traje/intérprete. Fixture con traje y performers pero0 compatibilidades devuelve **available**; prueba falló.

**Cambio:** resolver conjuntamente unidad de traje, outfit e intérprete apto; faltante de evidencia → revisión. No basta un filtro global «existe algún performer». También probar caso donde el único intérprete compatible está ocupado o es necesario en otro rol.

### P1 · R5. El parser iCal puede ocultar eventos

Tres reproducciones puras ejecutadas, sin llamadas externas:

| Entrada sintética | Resultado actual | Resultado seguro |
|---|---|---|
| DTSTART/DTEND con20261131 | Cambia el evento a1 de diciembre | Rechazar fecha inválida |
| Componentes begin:vevent y propiedades en minúsculas | Devuelve[] | Leer evento correctamente o devolver desconocido |
| rrule en minúsculas dentro de VEVENT | Devuelve solo primera ocurrencia | Expandir correctamente o rechazar todo el feed |

`HoneyBookCalendarFeed.php:52–66` hace comparaciones sensibles a mayúsculas; `:78–82` no valida round-trip/advertencias para fechas con hora (sí lo hace con all-day). No aceptar una fecha normalizada ni ignorar una recurrencia no soportada. Mantener el fallo del feed como unknown. El script incluido reproduce exactamente los tres resultados.

## Bloqueos confirmados por lectura de código; falta prueba integral específica

### P1 · S1. Cobro antes de asegurar todos los recursos

`PrepareQuickStripeLease.php:33–39` aparta trajes; `QuickPaymentCheckout.php:37–39` valida ese apartado. No prueba staff/equipo/vehículos/rutas completos. `Finance/CreateCheckoutIntent.php:24–47` protege finanzas y cobros duplicados pero tampoco asegura capacidad operativa de un draft. Es una limitación del diseño previo y una tarea que Claude ya reconoce, no una supuesta pérdida de dinero.

**Cambio:** admisión común antes del cobro para ambas rutas; comprobación conjunta y retención duradera bajo exclusión mutua. Mantener idempotencia y llamadas de red fuera de locks. No bloquear todo draft arbitrariamente: la confirmación antigua depende de pago previo y produciría un bloqueo circular. Resolver el nuevo paso de admisión como indica BOOKING-FEASIBILITY-CONTRACT.md. Prueba concurrente: dos clientes y último equipo/empleado, exactamente una admisión/cobro habilitado.

### P1 · S2. Se libera stock mientras el cobro sigue incierto

`TickQuickPayments.php:19,33–49` procesa estado processing, borra reservas al vencer y cierra/consulta Stripe después best-effort. Un timeout de cierre deja provider_close_pending con stock ya libre. Pago tardío no readquiere stock, protección correcta que se debe conservar; aun así puede haber dinero aceptado sin capacidad, agravado por R1.

**Cambio:** conciliar cobro pendiente/desconocido antes de liberar exclusión, sin permitir iniciar pagos nuevos ni renovar arbitrariamente la cotización48h. Cierre remoto fuera de locks y liberación final atómica. Pruebas: webhook durante expiración, timeout de cierre, reintentos y segundo cliente concurrente.

### P1 · S3. Ventana de60min no demuestra la ruta

`WebsiteCapacity.php:98–106` puede devolver available cuando ampliar60min no cambia el resultado. No usa dirección ni ruta real; un vecino reutilizando el mismo recurso más allá de la ventana se ignora aunque el viaje sea más largo. Esta regla contradice la aclaración nueva de HANDOFF: sin evidencia de traslado entre recursos compartidos → aprobación.

**Cambio:** recuperar los vecinos del recurso elegido y comprobar ambas rutas con fecha/hora, montaje/desmontaje y30 extra; no agregar separación entre equipos independientes. Si falta evidencia, aprobación/unknown. Integrar también confirmación interna: PR159 aún conserva max45 (`ConfirmBooking.php:232–234`); PR160 conserva la interpretación max30 anterior. Ninguna de ambas implementa por sí sola la aclaración final.

### P1 · S4. Ausencia de calendario se trata como agenda vacía; deduplicación no prueba identidad

`GoogleCalendar.php:193` devuelve[] sin conexión y `:204` usa[] si respuesta200 omite calendars.primary.busy. `CheckWebsiteCandidates.php:96–99` lo usa como fallback. No se revisó la agenda, pero se puede continuar como si estuviera libre. Exigir fuente disponible/esquema completo o modo explícito de CRM como única fuente tras la migración aprobada.

`WebsiteCapacity.php:74–79` aún absorbe un evento externo por inicio±5min/final±15min de un importado. La correspondencia uno-a-uno corrige el descarte múltiple anterior, pero una fiesta distinta con horario igual puede desaparecer. Deduplicar por identidad estable comprobada o conservar incertidumbre. Probar dos fiestas distintas con horario idéntico y una sola importada.

### P1 · S5. Datos incompletos existentes no deben desaparecer del cálculo

`WebsiteCapacity.php:157–160` omite un rol de una fiesta comprometida cuando no hay unidades elegibles. Esto no resuelve quién la atenderá y puede liberar aparentemente personal/equipo para otra venta. `:60–62` elimina pins de recursos inactivos y no marca esa inconsistencia como pendiente. Receta vacía puede terminar sin job (`:168–172`).

**Cambio:** tratar compromiso incompleto/inconsistente como revisión con evidencia del recurso externo asegurado, no como demanda0. No declarar disponible por omitir una obligación existente. La fuente de verdad sigue siendo el CRM, sin inventar plantilla ni asignaciones.

## Mejoras de corrección y operación (P2)

- **Asignación greedy de pins:** `WebsiteCapacity.php:156` fija el primer recurso elegible por orden de receta. Dos personas reservadasA(host+painter),B(host); requisitos host,painter: puede fijarA como host y volver a exigirA como painter, rechazando aunque B-host/A-painter encaje. Conservar la identidad del equipo pero resolver roles conjuntamente; no reasignar personas a otras fiestas. Prueba específica pendiente.
- **Trabajo del cron sin progreso:** `TickQuickPayments.php:19` toma siempre primeros IDs hasta limit, aunque no tengan acción vencida. Con limit1 y primer registro futuro, segundo vencido puede quedar sin procesar indefinidamente. Priorizar vencidos/paginar de forma estable. No modificar reglas de48h por este arreglo.
- **Límite de descarga tardío:** `HoneyBookCalendarFeed.php:31–36` descarga todo antes de comprobar2MB. El límite de parser existe; falta cortar recepción para que también sea límite de memoria/red. No imprimir URL privada en errores.
- **Snapshot y concurrencia:** lecturas de recursos/compromisos/fuentes separadas no prueban consistencia ante cambio concurrente. Validar versiones tras llamadas externas y en admisión final. Caché2min no autoriza una reserva. Probar cambio de dirección y bloqueo de recurso durante cálculo.
- **Rama apilada y política común:** PR159 tiene base claude/audit-fixes y no incluye aún DailyCapacity de main7bfbcf0 (no existe el archivo en08e638d). No mezclar resultados CI de ramas distintas; al integrar preservar límites, mutex, recepción de dinero y definir el día de Houston igual en todas las rutas. Ejecutar regresión de integración sobre el commit final.

## Evidencia ejecutada y límites

- `AvailabilityAuditRegressionTest` + `WebsiteCapacityTest`: **14 pruebas /42 aserciones;11 pasan y3 fallan** (R2/R3/R4),51.336s. Una prueba nueva de asignación fija pasa; los10 tests existentes pasan. Son fallos funcionales de expectativas correctas, no errores de entorno.
- Recorrido `QuickPaymentsTest::test_audit_late_payment_and_signature_never_display_a_reserved_party_without_stock`: **1 prueba /12 aserciones, falla al verificar vm.reserved=false**,4.291s. Cero reservas comprobadas antes y después de firma. Proveedor simulado, sin dinero real.
- Tres casos iCal reproducidos y resultados anotados en R5. CI actual seis checks verdes; los nuevos casos no estaban en esa suite.
- Entorno PHP8.3.36/MariaDB11.8, fixtures ficticios. No se realizaron llamadas a Stripe/Google/HoneyBook reales, envíos, pruebas del host ni cambios de flags. No se ejecutó una nueva prueba concurrente integral de reserva; S1/S2/Snapshot requieren esa verificación.

## Material para reproducir y orden de arreglo

En `docs/reviews/proofs/availability-08e638d-regressions.patch` están las pruebas añadidas contra08e638d. Aplicar en checkout aislado del commit indicado; fallan intencionalmente hasta corregir. `calendar-audit-proof.php` acepta como argumento la raíz de ese checkout con vendor. Los parches se guardan fuera de la suite normal para no romper CI del PR de soporte solo por entregar evidencia.

Orden recomendado: **R1 y admisión/expiración S1–S2**, luego rutas/vehículos/compatibilidad/R2, fuentes de calendario, datos incompletos y mejorasP2. Mantener preview hasta validar el recorrido completo; no activar correos/SMS. Solicitud a Claude: responder con commit corregido, regresiones antes/después y prueba concurrente de última unidad. Este reporte no requiere nuevas decisiones del dueño sobre reglas que ya están aprobadas.
