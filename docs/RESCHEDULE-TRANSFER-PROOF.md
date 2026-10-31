# Reprogramación: prueba vigente de traslado

2026-10-30 · Codex. Complementa CHANGE-A; no cambia precios ni cláusulas.

Antes de mover una fiesta, obtener fuera de transacción las rutas de cada unidad reservada con sus vecinos anterior y posterior. La prueba contiene huellas del evento y de todas las reservas vecinas, destino propuesto y hora de lectura. Una ruta desconocida no autoriza reutilizar la unidad, aunque exista una ventana grande. El margen es desmontaje anterior + manejo real + 30 extra + montaje siguiente.

Dentro de la transacción: mutex global DailyCapacity, usuario, evento, apartado, días anteriores/nuevos, personajes y recursos en orden. Releer la misma agenda y rechazar si cambió mientras se consultaba Google, o si la prueba tiene más de dos minutos. Comprobar bloqueos durante el traslado. No red bajo locks. El rechazo conserva horario, reservas, dinero y contrato originales. Una repetición idempotente devuelve el cambio previo sin necesitar otra ruta.

Esto protege las unidades ya apartadas; no constituye la retención completa S1 para el futuro cobro automático web. Los avisos/campañas nuevos requieren integración separada con el outbox y resultado desconocido durable antes de fusionar esa entrega.

Second and later signed date changes may reuse the original unchanged details only across a contiguous chain of completed reschedules with matching signed contracts and financial revisions. An unrelated version gap never qualifies. The original details and every signed artifact remain immutable.
