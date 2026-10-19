# STAFF-01 — página semanal de solo lectura, contrato v2

2026-10-18 · Backend Codex, pantallas Claude. Contrato publicado antes de implementar. Sustituye por completo v1 de invitaciones/disponibilidad según decisión de César registrada en PHASE-6.md y HANDOFF de Claude. La publicación de este contrato precede al backend; los previews siguen siendo sintéticos y exclusivos de local/testing. La propuesta anterior de acceso de 7/30 días queda sustituida por el enlace duradero aprobado, no pendiente de esa pregunta.

## Alcance y capacidad

Una persona = un Resource kind=staff activo. César acuerda el trabajo directamente y usa las reservas/asignaciones existentes. El portal muestra únicamente sus asignaciones vigentes en eventos confirmados; no crea, acepta, rechaza ni modifica reservas. No existen invitaciones, disponibilidad declarada, vencimientos de respuesta ni holds adicionales en v2. Mantener los candados, evidencia, habilidades/trajes y regla de al menos un conductor del equipo existentes. No inferir aceptación del staff ni registrar decisiones inexistentes.

Semana actual de lunes 00:00 a lunes siguiente 00:00, America/Chicago; convertir ambos límites locales a UTC por separado (DST). El usuario no puede elegir otra semana/persona. Incluir eventos cuyo inicio cae en ese intervalo y cuyo fin es posterior a ahora; los ya terminados desaparecen. El lunes cambia automáticamente. Ordenar por inicio e ID. Solo assignments actuales no liberadas, recurso activo y evento confirmado con reserva vigente; excluir cancelados, borradores y versiones sustituidas. Una lectura no renueva ni confirma capacidad. Mostrar generated_at para aclarar cuándo se consultó; actualizar mediante nueva lectura al abrir/recargar, sin prometer notificaciones en tiempo real.

## Identidad y enlace

`staff_identities`: resource_id único, active, locale es|en, phone cifrado opcional (solo dueño), version. No necesita cuenta owner ni acceso /admin. Prohibir usar una identidad de dueño para impersonar staff.

`staff_access_grants`: identity_id, token_hash único (SHA-256 de al menos 32 bytes aleatorios), token cifrado para volver a copiar, generation/version, revoked_at, created_by, last_opened_at. Un solo grant activo por identidad. **Sin vencimiento automático del enlace**; rotar o desactivar invalida inmediatamente también sus sesiones. Claves de cifrado fuera de git. Lecturas y auditorías nunca devuelven hash/token/ciphertext.

Enlace `/staff#token` → intercambio POST → sesión exclusiva staff, HttpOnly/SameSite, Secure fuera de local/testing. Token solo en fragmento/payload de intercambio, nunca path/query/logs/jobs. El cliente elimina el fragmento tras intercambio. Sesión separada mediante cookie staff opaca HttpOnly y token almacenado solo como hash, con techo de 7 días, sin extender el grant; al vencer basta reabrir el mismo enlace personal. Eso no convierte el enlace duradero en invitación con vencimiento. Cada lectura revalida identidad, recurso y generación del grant; revocación/rotación corta cookies previas. Rechazar intercambio desde una sesión admin/clienta; no transformar su identidad. CSRF y protección de origen en intercambio y logout; rate limit en intercambio/lecturas. Mismo error genérico para token desconocido/desactivado.

Respuestas privadas `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, noindex/nofollow; sin analytics ni terceros salvo Maps abierto explícitamente con noreferrer. Un enlace reenviado expone la semana de esa persona hasta rotación; la dueña tiene acción para hacerlo. No alegar 2FA del staff.

## Servicios y rutas para Claude

Actor owner activo con 2FA, sandbox_staff habilitado, CSRF en mutaciones; UUID `Idempotency-Key` y expected_version obligatorios. Registrar IDs, transición y versiones, sin enlaces/teléfonos/instrucciones. Bloquear identidad/grant con orden estable; mismo UUID/payload devuelve misma respuesta, UUID reutilizado con otro payload o versión antigua →409. Nunca reproducir un enlace revocado mediante un replay; responder conflicto.

```php
SaveStaffIdentity::execute(User $actor, array $input, string $key): array;
IssueStaffGrant::execute(User $actor, int $staffId, array $input, string $key): array;
RevealStaffGrantLink::execute(User $actor, int $grantId, array $input): array;
RotateStaffGrant::execute(User $actor, int $grantId, array $input, string $key): array;
RevokeStaffGrant::execute(User $actor, int $grantId, array $input, string $key): array;
ReadStaffLinks::execute(User $actor, array $filters): array;
ReadStaffWeek::execute(StaffContext $staff): array;
```

- POST `/admin/api/staff/identities` `{resource_id,expected_resource_version,locale,phone?,active,expected_version?}` + Idempotency-Key: registra/actualiza explícitamente la identidad de un recurso staff existente. expected_version obligatorio al actualizar. No crea personas ni inventa teléfonos. Respuesta cerrada owner sin phone salvo issue/reveal/rotate. Desactivar revoca grants; reactivar no los revive.
- GET `/admin/api/staff/links?page=1`: listado paginado, `data:[{staff_id,resource_id,display_name,locale,active,version,grant:{id,version,last_opened_at}|null}],current_page,last_page`. Sin token. Sin conteos de aceptación.
- POST `/admin/api/staff/{staff}/grants` `{expected_version}`: emisión explícita; si ya tiene uno, conflicto y ofrecer copiar/rotar. Devuelve `{staff_id,version,grant:{id,version},link,phone}` no-store. phone es string|null del registro explícito, exclusivo del dueño; igual en reveal/rotate, nunca en listados o portal staff.
- POST `/admin/api/staff/grants/{grant}/reveal` `{expected_version}`: copiar grant mostrado bajo validación; no emite/reemplaza otro ni cambia versión. No marca enviado.
- POST `/admin/api/staff/grants/{grant}/rotate` `{expected_version}`: nuevo token, grant/generación nuevo, anterior inválido; devuelve envelope como issue. Owner modal queda fijado al ID/versión mostrados.
- POST `/admin/api/staff/grants/{grant}/revoke` `{expected_version}`: invalida acceso y sesiones; devuelve `{id,version,revoked:true}`. Desactivar staff/recurso también corta acceso. Reactivar no revive un grant revocado.
- POST `/staff/session` `{token}`: intercambio, sin staff_id; devuelve `{ok:true}` y cookie aislada. DELETE `/staff/session`: salir. Nunca devuelve token.
- GET `/staff/api/week`: contexto derivado de cookie/grant, nunca identity/resource/event ID aportado por la persona. `StaffContext` no se puede fabricar con sesión owner/clienta.
- GET `/staff`: pantalla real futura. No habilitarla con fixtures en producción ni confundir `/dev/staff/semana` con esta ruta.

Todos los parámetros ID con validación numérica; 401 acceso inexistente/revocado, 403 dueño sin permisos/flag, 409 stale, 422 payload inválido. Error de otra persona nunca revela existencia ni información.

## View-model semanal (adaptador del preview)

```text
lang: es|en
preview: false
staff: {display_name}
week: {starts_at,ends_at,from,to}   # límites UTC exclusivos + etiquetas locales
updated_at: etiqueta Houston
generated_at: ISO UTC
jobs: [{
  date,hours,arrival,setup_minutes,
  address,maps,parking,access,
  role,character,outfit_version,is_driver,
  team:[{first_name,drives}],
  bring:[texto],instructions,
  day_contact: null|{name,phone}
}]
```

`arrival`, montaje, equipo e instrucciones salen de datos operativos registrados: falta de dato → null/pendiente, nunca inferir una hora o tarifa. Campos de texto escapados. `outfit_version` puede ser `"0"`; verificar null/vacío, no truthiness. `maps` lo construye el servidor con URL HTTPS permitida y dirección codificada. `team` solo personal actual del mismo evento y primer nombre, nunca directorio global/teléfonos. Contacto de la fiesta solo durante su fecha Houston; fuera de ella null, sin datos ocultos en HTML/JSON. Mostrar únicamente el contacto operativo registrado. Lista cerrada: sin totales, depósitos, saldos, pagos, costos, honorarios, otros correos/teléfonos ni notas internas del CRM. Instrucciones operativas aprobadas para staff separadas de notas privadas; no volcar el modelo Event completo.

`lang_url` del preview no se copia al backend: cualquier cambio de idioma solo permite es/en y conserva el mismo contexto, nunca token en URL. Caso sin trabajos: `jobs:[]`, sin inventar asignaciones. Los botones del dueño abren aplicaciones; no registran envío ni entrega.

## Verificación necesaria antes de activar

Cruce de personas, identidad/recurso inactivo, rotación/revocación con cookie antigua, replay de emisión/rotación tras revocar, owner/client session aisladas, semanas Houston con DST y límites de lunes, eventos terminados/cancelados/borrador, assignments liberadas, falta de hora real, traje "0", privacidad en HTML y JSON, contacto solo el día local, control de origen/CSRF, no-store/noindex, sin tokens en logs y filtros por ID cerrados. Previews y pruebas exclusivamente sintéticos. No envíos ni deploys; la lista privada del staff y activación requieren el flujo separado del dueño.

## Datos operativos iniciales y límites (2026-10-19, antes de código)

Las reservas y BookingEvidence actuales aportan roles/recursos, personajes/trajes y equipos. arrival se deriva exclusivamente del starts_at y setup_minutes registrados; si setup_minutes es null, arrival queda pendiente. is_driver usa el driver_staff_ids del snapshot confirmado, no basta tener la habilidad driver. Un snapshot que no reconoce el recurso actual no se completa con otro staff ni datos de otro evento.

Parking/access/bring/instructions/contact se leen solo de una revisión operativa explícita, nunca de notas privadas ni teléfono/email general de la clienta. Servicio owner `SaveStaffJobInstructions::execute(User $actor,int $eventId,array $input,string $key):array`, POST `/admin/api/events/{event}/staff-instructions` con `{expected_event_version,expected_version,parking?,access?,bring:[],instructions?,contact_name?,contact_phone?}`. expected_version0 crea; versión positiva actualiza. Contacto incompleto422. No altera capacidad, horarios ni importes. Hasta registrar esos datos, null/lista vacía y texto pendiente. Identidad y revisión operativa se auditan por IDs/versiones, no texto/teléfono.
