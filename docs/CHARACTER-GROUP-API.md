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
