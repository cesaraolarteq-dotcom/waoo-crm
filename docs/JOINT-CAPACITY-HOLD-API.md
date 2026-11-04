# S1 · Asignación conjunta y retención antes del pago

2026-11-04 · Codex · Rama `codex/fix-joint-capacity-hold`.

## Implementado en esta entrega

`WebsiteCapacity::allocation(from, to, HoustonDay, requirements, pinnedIds)` usa la misma evaluación de `check` y devuelve
al servidor `{state, assignment}`. La asignación contiene las unidades de **una única solución final** del solver, después
de comprobar los traslados. Incluye intérpretes compatibles, vestuario, equipo y, si la receta lo requiere, vehículos/asientos.
El conductor pertenece al equipo. Una persona no cubre dos puestos simultáneos.

Las unidades fijadas se conservan, incluyendo su traslado: no se sustituyen por otras libres para fabricar un resultado
positivo. Pins inválidos, calendario sin respuesta, solución vacía o estado distinto de `available` devuelven asignación vacía.
La llamada siguiente no hereda los pins ni una solución anterior.

Las fiestas existentes también conservan intérpretes compatibles al resolver la capacidad de otra reserva. Los detalles de
vestuario se cotejan con `CostumeOutfits`; `CostumeWearers` decide quién puede usar el traje: cabeza admite Personaje o
Personaje con peluca; peluca requiere esa habilidad; una excepción grabada prevalece. Los roles de intérprete unidos a un
traje toman su elegibilidad de esa misma autoridad, no de una etiqueta genérica contradictoria.

El resultado es **privado y de lectura**. No es un apartado, no crea reservas, no asigna un plan de staff ni avisa a nadie.
Ningún controlador público devuelve IDs de staff/inventario. No cambian los límites de 4 simultáneas/10 por día Houston,
la ruta real +30 extra, montaje/desmontaje, precios, depósitos ni contratos.

## Pendiente para completar S1 (aún sin implementar)

Antes de conectar un cobro automático, otra transacción debe volver a comprobar la prueba y reservar físicamente **todas**
las unidades juntas. Orden: DailyCapacity global → actor/grant → evento → hold/lease → días → personajes → recursos.
Google/calendario y Stripe se consultan fuera de locks; sus pruebas deben caducar y contrastarse de nuevo dentro.

La retención durable necesita evidencia propia e idempotencia sin renovar las 48h originales. No se modifica el sobre V1
histórico de trajes para introducir más unidades. Resultado desconocido del proveedor o aviso Zelle pendiente debe conservar
el equipo entero, incluso después del plazo. Un pago válido siempre se registra; capacidad inválida pasa a revisión.

Faltan las conexiones completas de checkout, promoción, vencimiento/cancelación y reemplazo del equipo la semana de la fiesta.
El reemplazo debe comprobar una solución completa y las rutas antes de liberar las unidades anteriores. Un resultado del
solver aislado, #176 o un recheck antes de Stripe **no completan S1**.

Sin despliegue ni activación. Web en preview, pagos y envíos apagados; no se activa nada por hostname o APP_ENV.
