# Reglas de equipo y recreadora extra · 2026-11-04

Fuente: respuestas de César registradas por Claude en #198, `21fd0839` + `2856291`.
Integración sobre main `eaecba0`, rama `codex/fix-staff-rule-review`.

## Pintacaritas dentro del equipo

Waoo requiere un animador y dos recreadoras; cualquiera de esos puestos puede cubrir pintacaritas. Una maleta `face_kit`
sin un puesto dedicado `face_painter` exige esa habilidad en un integrante de la asignación real. No se agrega una cuarta
persona para satisfacer una etiqueta y una persona fuera de los puestos elegibles no prueba cobertura.

- `CrewSkills::required` interpreta la receta grabada; un puesto dedicado ya exige esa habilidad por su propia elegibilidad.
- `PoolCapacitySolver` acepta `skills` en unidades de staff y `team_skills` en cada job. Son listas acotadas de etiquetas.
  Durante la búsqueda exige cobertura en el mismo equipo, junto con puestos distintos, conductor, parejas traje/intérprete,
  asientos, pins, bloqueos y separaciones de ruta. Un pintacaritas no sirve para dos fiestas que se cruzan.
- `WebsiteCapacity` usa la regla tanto en candidatos como en compromisos existentes. La pasada de inventario físico sigue
  separada: falta de personal requiere revisión del dueño, nunca una asignación privada `available` sin esa persona.
- `ConfirmBooking` nominal usa `MatchEventResources::executeForTeam`: matching exacto del equipo seleccionado para conservar
  pintacaritas y conductor a la vez. El matching estructural no sustituye los checks de agenda/traslado bajo locks.
- `AssignEventTeam` permite guardar un plan parcial. Un roster completo debe cubrir las habilidades de equipo; el rechazo
  revierte plan y reservas. Sugerencia/tablero usan la misma regla. La decisión de nombrar al staff la semana de la fiesta se conserva.

## Recreadora extra en Reserva rápida

La variante de catálogo debe durar lo mismo que el paquete principal. El formulario acomoda la selección cuando encuentra
exactamente una variante activa correspondiente. Si falta la versión o hay dos candidatas, conserva la elección para revisión;
el servidor rechaza la duración incorrecta antes de guardar la reserva. No crea precios ni sustituye versiones dentro de un cobro.

Entrada adicional opcional de `QuickBook` y `CreateUnpaidQuickBooking`:

```json
{"package_price_version_id": 12, "services": [{"price_version_id": 12}, {"price_version_id": 18}]}
```

IDs ficticios. El principal debe estar entre los servicios seleccionados y no ser la propia recreadora extra. Al omitirlo,
solo se infiere si existe un único servicio base. El formulario envía el principal automáticamente. Las peticiones anteriores
sin ese campo siguen funcionando cuando la selección es inequívoca. La edición manual genérica/histórica conserva sus snapshots.

`SameLengthExtras::assertForQuick` corre después de reconocer un replay, dentro de la transacción de admisión y antes de
crear cliente/evento/revisión financiera/recibo/lease. Lee las variantes bajo locks. El precio recibido y el plazo original
se conservan al reintentar: la regla no vuelve a cotizar una operación ya registrada ni renueva sus48h.

## Límites de esta entrega

La asignación privada y estas validaciones no completan S1 durable. Faltan retención conjunta y sus conexiones de checkout,
promoción, vencimiento/cancelación y reemplazo semanal; rutas reales y borde DST del plan; calendario real; extras y producción.
#200 (tiempo extra y depósito hoy/mañana) llegó después y necesita su propia revisión de dinero/disponibilidad: no está incluido.
Al revisar sus extensiones, comprobar la duración efectiva del paquete para la recreadora adicional.

Pruebas ficticias en MariaDB, sin despliegue, importación real, pagos, mensajes ni flags activados. Web en preview.
