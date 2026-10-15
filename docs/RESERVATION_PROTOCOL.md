# RESV-A-01 / BOOK-A-01 — propuesta de protocolo y aceptación

2026-10-15 · Codex · Diseño de Fase 0 para revisión de Claude. **No implementado; no autoriza Fase 1.** IDs canónicos: BACKLOG.md de Claude. Relacionado con ARCH-01, PAY-01, PAY-02, ROUTE-01 y HOLD-01.

## Objetivo y límites

En A, el administrador elige recursos; el sistema debe impedir confirmaciones incompatibles. B añadirá búsqueda automática usando el mismo protocolo. Un calendario bonito, una advertencia o una consulta anterior de disponibilidad no garantizan capacidad.

Cada copia física es una unidad con ID propio. Dato recibido en el backlog: Mickey y Rumi tienen dos trajes; esto no prueba que existan dos intérpretes compatibles y disponibles ni que todos los personajes tengan dos copias. No generar inventario completo a partir de esos ejemplos. Una persona con varias habilidades sigue siendo una sola persona.

## Inventario operativo administrable — requisito confirmado por César

El dueño confirmó en esta conversación que necesita controlar trajes y staff como inventario para alimentar la smart availability. Esto concreta D6; no adelanta la autorización de implementación ni la activación de B.

| Registro administrable | Datos y acciones propuestos | Uso en disponibilidad |
| --- | --- | --- |
| Personaje comercial | Nombre e imagen, variantes y servicios compatibles | Identifica lo que pide la clienta, no una unidad física |
| Traje físico | ID por copia, personaje/variante, talla, ubicación, condición; alta, edición, limpieza, mantenimiento, retiro | Solo unidades elegibles pueden cubrir un intervalo; compatibilidad con intérprete |
| Staff | Miembro activo/inactivo, habilidades, roles, personajes/trajes compatibles, disponibilidad y bloqueos, asignaciones y aceptación | Verificar persona concreta, horario, capacidad y traslado; habilidades múltiples no multiplican personas |
| Equipo/vehículo | Unidad o capacidad, ubicación, mantenimiento, conductor y asignaciones | Verificar que el servicio completo puede ejecutarse y transportarse |

Pantalla propuesta: vistas de trajes y staff con búsqueda/filtros, ficha por unidad/persona, agenda de uso y alertas de conflictos. Administración controla altas, cambios y bajas; las acciones quedan auditadas. El acceso de staff, si se habilita después, se limita a sus datos y asignaciones, no al inventario financiero ni información de otros empleados. Claude diseña la interfaz; Codex el modelo y validación.

Separar condición física (listo/limpieza/mantenimiento/retirado), disponibilidad laboral y reservas por intervalo. No guardar un único interruptor “disponible” que ignore futuras asignaciones. Un traje puede estar listo hoy y reservado mañana; el sistema calcula disponibilidad para fecha y hora concretas. Registrar un mantenimiento con inicio/fin o fin desconocido debe afectar esos intervalos y alertar si compromete eventos ya confirmados; no cancelar ni sustituir recursos silenciosamente.

No borrar una unidad/persona con historial: desactivar/retirar conservando eventos. Cambiar ubicación, compatibilidad, horario o condición requiere reevaluar compromisos afectados. Si faltan datos esenciales, mostrar pendiente de revisión. El administrador no debe poder marcar “libre” para saltarse un conflicto físico.

**Aceptación adicional, por implementar:** crear dos copias de un personaje; reservar una y ver la otra elegible; poner una en mantenimiento y retirarla de nuevas ofertas; restringir staff por habilidad/horario y comprobar que la oferta cambia; detectar conflicto al cambiar un recurso asignado; conservar historial tras retiro. Son criterios futuros, no pruebas ejecutadas.

## Estados independientes y evidencia

| Dominio | Hecho persistido requerido | Lo que no debe inferirse |
| --- | --- | --- |
| Cotización | Revisión inmutable, líneas/moneda, aceptación | Un precio enviado por el navegador no es aceptado por el servidor |
| Capacidad | Unidades concretas, intervalos, logística, versión y vencimiento | Firma o pago no crea disponibilidad |
| Firma | Documento exacto, versión/hash, proveedor y evidencia verificable | Abrir el portal o dibujar una imagen no acredita todo el proceso |
| Dinero | Transacción verificable, monto aplicado, moneda y movimientos auditados | Redirect success, comprobante Zelle o saldo mostrado en caché no confirman dinero |
| Reserva | Confirmación explícita bajo las condiciones D2 y capacidad vigente | Un único estado no sustituye los cuatro anteriores |

D2 permite una experiencia de firma y pago seguida, pero ambos pueden completarse en momentos distintos. Persistir progreso y reanudar sin repetir cobro/firma. Una transacción de base de datos no puede deshacer un cobro ya completado por Stripe.

## Protocolo candidato en MySQL/InnoDB

1. Autenticar y autorizar la acción por evento. Validar versión de cotización/agenda e idempotencia de la operación. Distinguir borrador incompleto de solicitud de protección/confirmación.
2. Obtener el conjunto de recursos antes/después del cambio. En una transacción corta, bloquear sus filas en orden determinista por ID (incluidos recursos que se liberan). Las escrituras de disponibilidad, mantenimiento, reasignación y cancelación que afecten capacidad deben participar del mismo protocolo.
3. Releer el estado vigente y verificar la versión esperada. Examinar asignaciones protegidas/confirmadas y bloques externos; intervalos de ocupación incluyen montaje, desmontaje y fases del servicio. Validar cada recurso y las restricciones de personal/vestuario/equipos/vehículo conjuntamente.
4. Evaluar trayecto desde origen y hacia los vecinos anteriores/posteriores, margen, cambios y regreso cuando corresponda. Preparar estimaciones fuera de la transacción para no mantener bloqueos mientras se llama a Google; dentro de ella, verificar que siguen correspondiendo a las direcciones/versiones/vecinos actuales. Si cambió una entrada o falta evidencia suficiente, rechazar/reintentar o dejar en revisión; nunca asumir cero tiempo.
5. Guardar asignaciones, versión de agenda, auditoría y evento outbox en la misma transacción. Commit antes de llamadas a proveedores. Deadlock/timeout implica rollback y reintento acotado de una operación idempotente; no guardar parcialmente.
6. Al confirmar, repetir validación bajo el mismo bloqueo y verificar cotización, firma exacta y pago verificado. Una corrección de dirección/duración exige revisar también los eventos vecinos afectados, no solo el evento editado.

Propuesta conservadora inicial: serializar por recurso completo, no por fecha, para no omitir cruces de medianoche. Debe medirse con el volumen y consultas reales antes de aceptar el diseño. Bloquear solo filas de reservas existentes no protege un calendario vacío frente a dos inserciones simultáneas; las filas de recursos estables proporcionan el punto de serialización. La integridad depende de que todas las escrituras usen el protocolo; no se promete garantía por usar MySQL sin más.

Guardar instantes UTC y zona America/Chicago, conservando intención local. Una hora inexistente/ambigua en cambio de horario requiere resolución explícita. Los intervalos de servicio pueden tratarse como [inicio, fin); que dos servicios se toquen no elimina montaje/viaje.

## Firma, pago y vencimiento (BOOK-A-01)

| Situación | Comportamiento propuesto |
| --- | --- |
| Administrador prepara propuesta | Borrador editable sin promesa de reserva; protege recursos antes de habilitar pago |
| Firma y pago en curso | Mantener estados independientes y ventana de protección visible calculada por servidor |
| Clienta declara Zelle | Pago pendiente; no registrar dinero recibido por una imagen ni confirmar reserva |
| Administrador verifica Zelle | Registrar monto real, referencia y autor; recalcular saldo, invalidar cobros obsoletos cuando sea posible y revalidar capacidad |
| Expira la protección | Liberación idempotente y auditable; la tarea de expiración y la confirmación toman los mismos bloqueos y consultan hora del servidor |
| Llega dinero después de vencer | Registrar dinero una sola vez; comprobar capacidad. Si no cabe, incidencia para resolución autorizada; no vender otro recurso silenciosamente |
| Pago y firma válidos, capacidad vigente | Confirmación con evento outbox; notificación después del commit |
| Nueva cotización/contrato | Invalidar aceptación/sesión incompatible; conservar documentos y dinero históricos |
| Cancelación o corrección monetaria | Movimientos auditados; no borrar pagos. Reembolso/crediticio según decisión aplicable, no política inventada |

**D7 pendiente:** duración para firma/pago, ventana de verificación Zelle, renovación, recordatorios y liberación. No asumir 15 minutos para Zelle ni un plazo para todos los servicios. **D3:** monto exacto por Stripe y registro real por Zelle; no tolerancia automática. Si total × 50% produce medio centavo, BIZ-02 debe fijar redondeo explícito antes de cobrar; “exacto” no hace representable medio centavo.

Para evitar cobros concurrentes, controlar el importe comprometido por intentos activos de pago además del saldo ya recibido. Un Zelle verificado y una sesión Stripe que ya completó pueden producir sobrepago real: registrarlo y resolverlo, no hacer desaparecer uno. Guardar claves de idempotencia con alcance, hash del payload y resultado; mismo ID con distinto payload debe rechazarse.

## Contrato mínimo que deben acordar backend e interfaz

Sin fijar todavía URLs ni esquema de migraciones, las operaciones serán: crear borrador, calcular/revisar cotización, proteger recursos elegidos, emitir recorrido firma/pago, declarar/verificar Zelle, confirmar, reprogramar y cancelar. Cada operación documentará permisos, datos obligatorios, versión esperada, idempotencia y resultado.

Resultados diferenciados para la interfaz: recursos en conflicto, logística sin verificar, versión obsoleta, protección vencida, pago pendiente, firma pendiente, confirmado e incidencia de conciliación. No exponer nombres/datos de otros clientes en respuestas de conflicto. Administración puede ver razones operativas según permisos; el cliente solo mensajes apropiados EN/ES. Claude revisa la presentación antes de fijar el API.

## Pruebas de aceptación por implementar después de autorización

Todas son **no ejecutadas**: requieren el futuro esquema y flujos, además de MySQL real. No confundirlas con probes de PHP de la web.

| Caso | Resultado exigido |
| --- | --- |
| Dos admins eligen el único traje al mismo tiempo | Solo una protección; la otra falla de forma recuperable |
| Dos trajes Mickey y dos intérpretes compatibles | Dos eventos simultáneos pueden protegerse si equipos/logística también caben |
| Dos trajes pero un solo intérprete | No confirmar dos funciones simultáneas de esa persona |
| Mantenimiento compite con reserva | Una serialización consistente; no reservar una unidad no disponible |
| Evento cruza medianoche o cambio DST | Bloqueos y conversiones coherentes; no hora perdida o duplicada silenciosamente |
| Cambio de dirección rompe trayecto al evento siguiente | Rechazo/revisión antes de confirmar el cambio; estado anterior preservado |
| Error Maps o cambio de vecino durante consulta | No confirmar con una ruta ausente o calculada para otra versión |
| Expiración y confirmación concurrentes | Un único desenlace consistente; no liberar una reserva confirmada |
| Firma completada y navegador cerrado | Reanudar pago sin crear otro documento firmado |
| Webhook repetido o fuera de orden | Un movimiento por transacción; no degradar estado con un evento antiguo |
| Zelle registrado mientras Stripe cobra saldo | Reconocer ambos importes y resolver sobrepago; nunca perder dinero |
| Corrección de Zelle | Historial original intacto, razón/autor y saldo recalculado |
| Precio total con centavos impares | Redondeo acordado, depósito + saldo = total exacto |
| Cliente modifica ID o versión | Sin acceso ajeno ni cobro sobre otra revisión |
| Worker falla tras envío o pago | Conciliación/deduplicación evita efectos repetidos; incidente observable |

Para revisión de Claude: validar estados necesarios en pantallas, mensajes de conflicto, reanudación, expiración y Zelle. Estas decisiones técnicas no resuelven D7–D9 ni cambian políticas del dueño.

## Logística compartida y reutilización de equipos — ampliación confirmada

César confirmó cuatro speakers y transporte por él, Maybeli o miembros del staff que también pueden trabajar en la fiesta. El protocolo debe reservar conjuntamente conductor/persona, vehículo, pasajeros y carga por tramo, además de sus actividades en el evento. No se deduce la cantidad de vehículos del número de conductores.

Propuesta de ficha de traslado: origen/destino, ventana de salida/llegada, conductor, vehículo, personas, trajes/equipos transportados, carga/descarga y conexión con recogida/retorno o siguiente evento. Las asignaciones pueden viajar juntas; un recurso no se teletransporta por estar libre en el calendario. Validar asientos y espacio para los speakers y demás carga. El costo cobrado al cliente sigue siendo independiente de esta planificación interna.

Un conductor que participa en la fiesta comparte identidad con su ficha de staff: bloquear su trabajo y conducción en la misma agenda. Para dejar un grupo y volver por él, verificar ambos tramos y el siguiente compromiso del conductor; no bloquear automáticamente toda la fiesta ni liberarlo automáticamente sin saber si participa. Cualquier cambio de grupo, conductor, dirección o equipo exige revalidar conexiones afectadas.

Para cada recurso que pasa de A a B:

`fin de uso en A + desmontaje/carga + viaje A→B + margen + descarga/preparación en B <= inicio requerido en B`

Cada término depende del recurso/servicio; no asumir que un speaker está libre mientras aún se desmonta o que la artista termina cuando termina su primera actividad. Si varios recursos deben estar juntos para montar, todos deben llegar a tiempo.

### Casos adicionales de aceptación (todavía no ejecutados)

| Caso | Resultado esperado |
| --- | --- |
| Cuatro fiestas simultáneas, una unidad por fiesta, cuatro speakers listos | El requisito de speakers cabe; comprobar además todos los demás recursos |
| Quinta fiesta solapada que también requiere speaker | No confirmar con esas cuatro unidades; alternativas o revisión sin inventar un quinto equipo |
| Una fiesta requiere dos speakers | Reservar dos unidades y reducir capacidad restante, sin regla fija de cuatro fiestas |
| Speaker termina en A y comienza B inmediatamente en otro lugar | Rechazar si no caben desmontaje, viaje y preparación |
| Quinto evento más tarde y traslado viable | Permitir reutilización de la unidad concreta; no aplicar límite de cuatro eventos diarios |
| Conductor anima en A y se lo pide para transportar B al mismo tiempo | Rechazar doble asignación de esa persona |
| Conductor deja grupo A, transporta B y regresa por A | Aceptar solo si todos los recorridos, capacidades y horarios son compatibles |
| Vehículo con asientos suficientes pero sin capacidad para carga | No confirmar el plan de transporte |
| Speaker entra a mantenimiento con evento ya asignado | Retirar capacidad futura y alertar compromiso afectado; no sustituir/cancelar silenciosamente |

Claude: contemplar vista por grupo/itinerario junto a inventario y calendario, con roles de conducir y participar visibles sobre la misma persona. Propuesta de UI a revisar, no pantalla implementada.

## Aprobación de última hora y estimaciones para el momento del viaje

**Requisitos del dueño:** menos de siete días requiere aprobación administrativa; estimar rutas para la fecha/hora del evento y agregar al menos 30 minutos sobre viaje para operaciones e imprevistos. Véase BUSINESS_RULES para la distinción entre decisión y precisiones pendientes.

### Flujo propuesto

1. Al solicitar reserva y antes de confirmar, evaluar el plazo con hora del servidor. Mostrar “Requiere aprobación por cercanía de fecha” sin prometer reserva, si aplica. Reprogramaciones que entren en la ventana también se revisan. No cancelar automáticamente un evento ya confirmado porque pasen los días.
2. Presentar a César/Maybeli una ficha breve: servicio, fecha, horas, dirección, grupo/staff confirmado, trajes/equipos, preparación pendiente, rutas/tiempos y conflictos. Acciones propuestas: aprobar, pedir ajuste o rechazar. Una sola acción puede aprobar si todos los requisitos están resueltos.
3. Registrar aprobación vinculada a versión; revalidar recursos dentro del protocolo de transacción antes de confirmar. El booleano de aprobación no exime firma/pago ni impide que la disponibilidad haya cambiado. Propuesta de UX: habilitar el cobro solo después de esta revisión para reducir cobros sin capacidad; acordar con el recorrido de D2 antes de implementar.
4. Expiración de una oferta o aprobación y duración de una protección siguen pendientes de D7. No renovar recursos indefinidamente ni inventar plazos. Si cambian los datos materiales, invalidar la aprobación incompatible sin borrar el historial.

### Rutas y tiempo adicional

- El adaptador de rutas debe recibir direcciones exactas y **salida prevista tras desmontaje/carga**, no solo fecha de inicio de fiesta. Incluir las paradas de recogida y el retorno cuando corresponda. Evaluar la cadena de cada persona/equipo/vehículo.
- Investigar en ROUTE-01 las modalidades de Google Routes que admiten tráfico y salida futura, horizonte de predicción, restricciones, cuotas y costo. Capacidad exacta del API/cuenta **no verificada** aquí. El código actual usa TRAFFIC_UNAWARE y ZIPs y no satisface este requisito.
- No asumir que el fin de semana siempre será más rápido: usar la estimación para esa fecha/hora. Conservar hora de consulta, fecha/hora objetivo y versión de ruta/direcciones según las condiciones del proveedor. Cachear por entradas temporales relevantes, no reutilizar una ruta mensual por ZIP como prueba logística.
- Si el proveedor no admite la fecha, falla o no ofrece información suficiente, marcar estimación limitada y pasar a revisión; nunca tratar el valor como tiempo garantizado. Reconsultar cerca del evento y alertar si el itinerario ya no cabe; no cancelar, cobrar o sustituir recursos automáticamente.
- Ejemplo confirmado: 16:00 + 28 min de viaje + 30 min adicionales = 16:58; ofrecer 17:00 solo si las tareas operativas caben en ese total. Si requieren más tiempo, ofrecer más tarde. No sumar una segunda vez el mismo desmontaje/setup que ya estuviera incluido en los intervalos. La distribución exacta de esos 30 minutos queda por servicio; recomendar un margen de imprevistos explícito sin fijar su cuantía aún.

### Aceptación adicional — no ejecutada

| Caso | Resultado esperado |
| --- | --- |
| Solicitud con menos de siete días y recursos elegibles | Pendiente de aprobación; no confirmación automática |
| Justo siete días y un instante por debajo | Aplicar límite estricto según definición temporal acordada; cubrir DST |
| Reserva antigua confirmada entra en ventana de siete días por paso del tiempo | Sigue confirmada, no se convierte retroactivamente en última hora |
| Aprobación y conflicto nuevo de staff/speaker | Revalidar y bloquear confirmación incompatible |
| Cambian fecha/dirección/servicio tras aprobación | Revisar versión y logística; no reutilizar aprobación inválida |
| Trayecto de 28 min desde evento que acaba a las 16:00 | No ofrecer antes de 16:58 bajo el mínimo; 17:00 si cabe toda la operación |
| Montaje/desmontaje y contingencia requieren más de 30 min | Usar total mayor; no ofrecer 17:00 solo por la regla mínima |
| Consulta un jueves para evento del domingo | Solicitar estimación del domingo a la salida prevista, no tráfico del jueves |
| Tráfico no disponible para fecha objetivo | Revisión explícita; no cero ni garantía falsa |
| Reconsulta cercana al evento empeora el itinerario | Alerta operativa y revisión de asignaciones afectadas |

Claude: reflejar aprobación de última hora en bandeja administrativa y estado del portal, junto con la fecha/hora objetivo de las estimaciones. Las decisiones de día de corte y grilla de horarios siguen siendo precisiones por resolver, sin bloquear la documentación del requisito.
