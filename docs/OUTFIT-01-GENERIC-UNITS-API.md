# OUTFIT-01 enmienda — unidad física y vestuario de la fiesta

2026-10-19 · contrato antes del código, pedido explícito de César. Amplía OUTFIT-01-API y sustituye la restricción de que un outfit pedido solo pueda usar una unidad fija con ese outfit. No precio adicional ni inventario de vestuarios inferido.

## Regla de capacidad

Una unidad costume es un recurso físico reutilizable (p. ej. cabeza de Mickey). `resources.outfit=null` permite vestirla con una opción vigente de `characters.outfits`. Una unidad con outfit fijo solo cumple ese outfit. Outfit no pedido permite cualquier unidad del personaje. Un outfit pedido exige unidad fija coincidente **o** genérica del mismo personaje con esa opción en el catálogo. Nunca otra versión fija, otro personaje ni más usos simultáneos de una misma cabeza.

Se mantienen matching global, compatibilidad intérprete/unidad, conductor, bloqueos y traslados. No selección greedy que consuma una unidad genérica necesaria para otra versión. `characters.outfits` sin cantidades son opciones, no unidades ni un límite físico de1 por opción. Cantidades explícitas de #88 siguen creando unidades fijas; no duplicar cabezas a partir del número de opciones ni eliminar inventario anterior automáticamente.

## Entrada compatible para las tres confirmaciones

Los selectores existentes por personaje en borrador/reserva/cotización siguen enviando `characters[*].outfit`; una preferencia ya acordada no se modifica desde la asignación.

Agregar a `ConfirmCapacity`, `ConfirmPaidCapacity` y `ConfirmImportedCapacity` un campo opcional:

```json
{"costume_outfits":[{"resource_id":123,"outfit":"Disneyland","expected_character_version":4}]}
```

Lista hasta100, resource_id distinto y seleccionado en resource_ids; outfit texto1–60, versión positiva. Solo para unidades costume genéricas. Cada elección exige opción vigente del personaje y versión del personaje mostrada; ajena/fija/inactiva/stale =>422/409 antes de efectos. La elección operativa puede concretar una preferencia ausente; no sustituye una preferencia del acuerdo. Si contradice todos los slots posibles, confirmar falla. Ausencia equivale a lista vacía para clientes anteriores. No cambio del servicio, contrato, finanzas ni precio por esta elección operativa.

El matching deriva la opción por unidad de la elección explícita cuando existe, o del slot pedido; una unidad fija sin preferencia usa su opción fija. Si una genérica sigue sin preferencia/elección, el snapshot conserva null (pendiente), no elige la primera opción. Reintento de confirmación compara la lista normalizada con la guardada; no permite cambiar vestuario de un evento confirmado mediante replay. Correcciones posteriores requerirán una acción separada, no entregada aquí.

## Snapshot y portal del staff

BookingEvidence.staff_assignment_snapshot mantiene todos los campos driver-v1 y añade `costume_policy_version=costume-outfit-v2`, `costume_choices` (lista normalizada exacta) y `costume_assignments` (unidad, character_id/name, outfit elegido, event_service_id/requirement_index/occurrence). Las entradas staff vinculadas por costume_unit_id incorporan character_id, character_name y outfit de esa asignación real. No datos personales nuevos ni información financiera.

ReadStaffWeek conserva `jobs[*].character` / `outfit_version`, pero para snapshots v2 lee la etiqueta congelada, incluyendo null y "0", en lugar del outfit actual de Resource. Un renombre posterior del catálogo no reescribe lo que se asignó. Snapshots históricos sin v2 siguen la lectura anterior; no se inventa vestuario retroactivo.

Lock order: event → personajes ordenados → unidades ordenadas, consistente con SaveResource personaje→unidad. Hints anteriores a locks se revalidan; unidad cuyo personaje cambió exige recargar. Lecturas de compatibilidad y reservas son actuales bajo locks para no aceptar una compatibilidad retirada mientras se esperaba. Matching, snapshot, evidencia/auditoría y reservas confirman en la misma transacción; fracaso no deja recursos ni dinero nuevos.

Claude hace los selectores opcionales en Apartar recursos sobre unidades genéricas, con el character.version mostrado. Puede omitir el campo mientras implementa la UI y usar las preferencias ya guardadas. No controles para unidades fijas ni cambio de una preferencia firmada; mostrar conflictos y pedir recargar al409. La ficha del staff no necesita otro campo de API.

## Verificación

Pruebas sintéticas:2 cabezas y varios outfits sin multiplicar unidades; cantidades explícitas por versión; genérica acepta opción vigente; fija incorrecta no; catálogo eliminado/stale no; elecciones fuera de resource_ids/fijas/personaje no; combinación fija+genérica cubre A/B por matching; una cabeza no cubre dos fiestas simultáneas (carrera MariaDB); snapshots/portal conservan elección tras renombre, "0" y null; replay con otra elección no altera evidencia; versiones antiguas y caminos manual/pagado/importado siguen compatibles. Sin despliegues/importaciones/cobros/envíos reales.
