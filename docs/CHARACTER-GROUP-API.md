# CHARACTER-GROUP — contrato de datos y colaboración

2026-10-19 · Codex · contrato publicado antes del código.

## Escritura y almacenamiento

`characters.group`: texto opcional, máximo 120 caracteres; `null` significa sin grupo. No es una relación, una categoría de paquete ni una regla de disponibilidad. Se conservan los nombres definidos por César; no se infieren shows del nombre del personaje.

La firma existente no cambia:

```php
SaveCharacter::execute(User $actor, array $input, ?int $characterId = null): Character
```

Se agrega `group` a los campos permitidos. Crear sin `group` guarda `null`. Actualizar sin la clave conserva el valor existente; enviar `null`, cadena vacía o solo espacios lo borra. Se recortan espacios exteriores; `"0"` es válido. Un valor no textual o mayor de 120 caracteres devuelve 422 antes de escribir. Actualizar sigue exigiendo `expected_version` bajo candado; el cambio incrementa `version` una vez y aparece en la auditoría existente. Las lecturas existentes de Character incluyen `group`; no hay endpoint nuevo.

En esta base no existe una clase `RecordVersion`: la versión del personaje es `characters.version`, verificada por SaveCharacter y fijada por `EditCharacter::$displayedVersion`. Se conserva ese mecanismo y se prueba el rechazo de ediciones obsoletas, sin crear un segundo contador.

Migración aditiva nullable sin rellenar grupos de registros existentes; rollback elimina únicamente la columna nueva.

## Importador

`waoo:import-catalog` lee `characters[*].group` del export. La clave ausente conserva el grupo existente; una clave presente aplica la misma normalización/validación, incluso cuando no cambian las versiones de traje. La coincidencia de personajes por nombre y la unión de outfits existentes no cambian. Un cambio simultáneo de grupo y outfits usa una sola escritura auditada y un solo incremento de versión. Repetir el mismo export no escribe, no incrementa versiones y no genera auditoría. `--dry-run` valida el grupo sin modificar datos.

## Pantallas — Claude

En Reserva rápida, cotización y borrador, agrupar las opciones permitidas por `character.group`; usar `Sin grupo` / `Ungrouped` para null. Mantener ids como valores y nombres como etiquetas; un cambio de grupo nunca cambia una selección ni amplía la lista de personajes permitidos por el paquete. Ordenar grupos y personajes por etiqueta, conservando el campo como texto escapado. No interpretar `"0"` como vacío. Conservar búsqueda, personaje ya seleccionado y validación del servidor.

Claude implementa los selectores y los campos de edición, incluyendo el envío opcional de `group` en `CharacterResource::toServiceInput`; Codex entrega migración, modelo, servicio, importador y pruebas. El backend no cambia requisitos, precios, personal ni reservas.

## V2 — categoría, show y público (pedido César2026-10-19)

Esta enmienda se publica antes de su implementación. `group` sigue siendo el show, **no** la categoría. Agregar `category` nullable texto120 y `audience` enum `girls|boys|all`, predeterminado `all` (sin filtro restrictivo). Categorías iniciales del dueño: Disney, Superhéroes, Programas infantiles, Videojuegos, Tendencias, Celebridades, Navidad, Otros. Se almacenan como texto del dueño, sin inferirlas del personaje ni convertirlas en reglas de capacidad; la API permite etiquetas futuras, la UI ofrece esas8.

SaveCharacter conserva firma, control expected_version y auditoría. Los tres campos son opcionales al escribir: ausencia conserva; null/blank limpia category/group, y restablece audience a all. La API devuelve422 para audience distinto de girls/boys/all y para category/group no textual o mayor de120. La migración420000 agrega category/audience;410000 ya agrega group. No inventar categorías para personajes existentes ni incrementar sus versiones por el backfill de audiencia no restrictiva.

El importador lee `characters[*].category`, `.group`, `.audience` y valida también en dry-run. Cambios de metadata y outfits se guardan juntos una vez; repetición sin cambios no escribe. Mantener #88 head8fe518b: vestuarios sin cantidades son opciones de characters.outfits y no crean unidades por vestuario; las unidades genéricas salen de costumes. Solo listas con cantidad explícita en **cada** versión crean unidades fijas por versión. No convertir ni borrar silenciosamente unidades ya importadas por una versión anterior: inventario existente requiere revisión del dueño.

Claude: agrupar Categoría → Show y filtrar público. girls incluye girls y all; boys incluye boys y all; Todos muestra todos. Sin categoría/Sin grupo como etiquetas de presentación para null; escape de texto y filled() para conservar "0". Son filtros de descubrimiento, nunca prohibición por género al reservar. Mantener IDs, búsquedas y lista permitida del paquete. Campos opcionales al formulario de personaje y toServiceInput. No inferir público a partir del nombre/tipo de fiesta.

Precisión del importador: cantidad por versión significa un entero separado del nombre por espacio; dígitos pegados al nombre (R2D2, V1) o un nombre completo "0" se conservan como nombre. Una cantidad explícita0 produce0 unidades, nunca se redondea a1. La semántica de cantidades explícitas vs opciones genéricas sigue la enmienda #88.
