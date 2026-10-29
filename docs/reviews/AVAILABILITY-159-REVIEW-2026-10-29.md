# Revisión de disponibilidad para Claude — 2026-10-29

Revisión estática del diff completo de PR159, HEAD f5b05656fe2601226e5664a9ae752c77f839b456, HANDOFF, comentarios, pruebas añadidas y estado CI. César solicita respetar sus instrucciones específicas y evitar conflictos. No se ejecutaron pruebas de esta versión ni se verificó el sitio desplegado. Las112 pruebas/838 aserciones previas corresponden exclusivamente al trabajo local Codex de pools/transferencias, no certifican este PR.

## Reglas del dueño que sí se respetan

HANDOFF documenta4 fiestas simultáneas,10 por día,24 horas, conductor por fiesta y falta de personal como aprobación del dueño; recursos físicos insuficientes como no disponible. No propongo sustituir estas decisiones por las reglas antiguas. Actualizar la política compartida/API y la confirmación interna para que usen la misma definición de día. Resolver con las instrucciones específicas ya recibidas la diferencia entre el piso de30 que implementó Codex y los30 adicionales del PR; no adoptar silenciosamente uno por encima del otro.

## Bloqueos encontrados en el código

1. **Personal contado varias veces.** WebsiteCapacity suma cada habilidad de cada persona como un cupo independiente. Una sola persona animadora/recreadora puede satisfacer dos puestos simultáneos. Hacer asignación conjunta por persona y por intervalo, respetando conductor integrante del equipo. Si no alcanza, conservar la regla aprobada owner_approval_required. Probar un único empleado multihabilidad requerido en dos puestos.
2. **Bloqueos y compromisos omitidos.** No consulta ResourceBlock; tampoco cubre explícitamente reservas/leases activas sin receipt/status/QuickBookingRecord. Probar equipo único bloqueado y lease activo. Usar la misma definición de compromiso que la confirmación, sin liberar por reloj una retención que aún sigue activa.
3. **Vestuario y recetas incompletos.** CheckWebsiteCandidates acepta outfit pero no lo valida ni lo aplica al inventario; WebsiteCapacity agrupa por personaje. Validar compatibilidad real, cantidad elegida y receta completa. Probar traje correcto de personaje pero vestuario incompatible. No tomar receta ausente como cero necesidades.
4. **Fiestas desaparecen por proximidad.** Se descarta cualquier evento externo cuyo inicio esté a±30 minutos de una fiesta CRM. Dos fiestas diferentes a10:00 y10:05 pueden desaparecer. El test actual incluso consagra este comportamiento. Deduplicar por identidad comprobada o conservar incertidumbre; agregar caso de dos fiestas distintas simultáneas.
5. **Traslado no comprobado.** Destino no se utiliza para factibilidad de ruta. El intervalo fijo no demuestra que el equipo pueda llegar a la siguiente dirección. Mantener revisión/estado incierto cuando falte evidencia; prueba de ruta larga y vecinos anterior/posterior. Setup/breakdown null de eventos existentes se convierten en0; no asumir tiempo libre. Defaults externos30/15 son estimaciones, no evidencia de duración real.
6. **Calendario ausente se interpreta libre.** GoogleCalendar::busyIntervals devuelve[] si no hay conexión y usa[] por defecto si una respuesta200 carece de calendars.primary.busy. Sin feed y sin Google no existe prueba de agenda vacía. Devolver desconocido ante fuente ausente/respuesta incompleta. Probar ambos casos.
7. **iCal incompleto puede ocultar ocupación.** RRULE/DURATION no se procesan; fecha inválida se omite; DTEND ausente inventa1 hora; basta BEGIN:VCALENDAR para aceptar/cachar un documento truncado. Soportar esos casos o rechazar como desconocido. Limitar cuerpo descargado y aislar caché por fuente/configuración sin exponer URL privada. Probar recurrencia, duración, documento truncado, error de fecha y cambio de fuente.
8. **Agenda externa sin recursos conocidos.** Un evento de calendario consume solo party/driver: no consume vestuario/equipo desconocidos. Si puede usar el mismo traje, no hay evidencia suficiente para anunciarlo libre. Requiere reconciliación o revisión explícita.
9. **Lecturas sin snapshot coherente.** Eventos, recibos, recursos y fuente externa se consultan por separado. Una reserva concurrente puede dejar una respuesta inconsistente. Validar generación/snapshot al terminar y revalidar al reservar; TTL2 minutos no equivale a garantía.

## Pruebas y despliegue

Al consultar este HEAD: checks y shards1–3 SUCCESS; shard4 IN_PROGRESS; shard5 FAILURE. Son estados observados, no diagnóstico de la causa. No atribuir el fallo a este código sin analizarlo; tampoco omitirlo para fusionar. Exigir nueva ejecución verde del commit corregido y regresiones anteriores.

HANDOFF reporta web LIVE con times=on contra crm-test, horarios9:00–20:30 cada30 minutos y comparación de5 fechas reales pendiente. Esto no fue verificado por Codex. Claude debe verificar inmediatamente si el cliente recibe available sin las pruebas completas y aplicar la corrección/contención apropiada dentro de la autorización que ya recibió. Documentar cómo esa lista web representa la regla24h; no inventar otra restricción comercial.

## Entrega solicitada a Claude

Responder con commit corregido, pruebas de los casos concretos, causa/resolución del fallo CI, reglas compartidas actualizadas y evidencia del comportamiento web/CRM. Mantener aprobación del dueño para personal insuficiente; no convertirla en rechazo automático. No enviar correos/SMS ni modificar proveedores por esta revisión. Codex no modifica la rama de Claude en paralelo.
