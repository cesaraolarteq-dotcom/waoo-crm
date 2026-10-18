# STAFF-01 — portal del personal, contrato v1

2026-10-17 · Fase 6 autorizada por César · diseño antes de implementación. Backend Codex, pantallas Claude, revisión cruzada. No invita, notifica ni cambia reservas esta entrega. Depende de REPORT-A y de la lista privada de personal de César; las pantallas pueden usar fixtures sintéticos. STAFFPAY es otro contrato: no calcular salarios hasta confirmar sus reglas.

## Identidad y acceso

`staff_identities`: user_id único (role=staff), resource_id único (Resource kind=staff), active, locale obligatorio es|en, version. El dueño registra el idioma explícitamente; portal y SMS usan ese valor, no el nombre/teléfono. Mantener usuarios owner_admin y acceso /admin separados; no convertir una cuenta de dueño en personal. Una persona = un recurso. Desactivar cualquiera de los dos revoca inmediatamente el acceso.

`staff_access_grants`: identidad, hash del token aleatorio de alta entropía, scopes cerrados, expiry, revoked_at, versión; bóveda cifrada solo si la dueña necesita volver a copiar. Máximo 7 días de vigencia inicial, configurable por la dueña dentro del límite; no extender al consultar. Nunca token en URL de consulta, logs, auditoría o jobs. Enlace con fragmento → POST de intercambio → sesión exclusiva del portal, con rotación. Cookies HttpOnly/SameSite/secure en staging; respuestas privadas no-store, sin analytics y sin referrer. El intercambio rechaza sesiones admin/clienta y no inicia una sesión de admin. CSRF después del intercambio, límites de intentos; token expirado/revocado no sirve aunque quede cookie. El PIN es opcional y queda fuera de v1: no fingir que el enlace es 2FA.

Owner: `IssueStaffGrant::execute(User $actor,int $staffId,array $input,string $key):array`; `RevokeStaffGrant::execute(User $actor,int $grantId,array $input,string $key):array`. Actor activo owner_admin con 2FA; require sandbox_staff y HTTPS seguro en staging. El enlace se copia manualmente; no crea un SMS ni marca enviado. Vigencia de invitación y de acceso son distintas.

## Capacidad: aclaración al plan

Disponibilidad declarada ≠ aceptación. Solo una aceptación explícita compromete a la persona. **Una invitación pendiente también debe bloquear provisionalmente ese recurso**, con vencimiento, para no ofrecerlo a dos fiestas a la vez. No cuenta como aceptación ni como cobertura final. Esto aclara la frase del plan «solo aceptadas cuentan»: no permite ignorar pendientes al buscar conflictos.

No cambiar retrospectivamente reservas confirmadas ni inventar aceptaciones del personal. Reservas existentes conservan su evidencia y pueden mostrar «aceptación por recabar». En el flujo nuevo, preparación comercial y estado operativo van separados: invitaciones pendientes → cobertura aceptada → confirmación normal de capacidad. No vender disponibilidad automática de un evento que quedó incompleto.

Aceptar valida bajo candados el evento/versión, identidad/recurso activo, rol y habilidades, traje/versión, disponibilidad/bloqueos y todos los solapamientos/traslados. Se reutilizan los intervalos, margen de 45 minutos y revisión excepcional existentes, sin recalcular con una política nueva. Condición de conductor: al menos una persona del equipo asignado maneja; no sumar personas ni exigir ambas habilidades al mismo puesto. Revalidar el conjunto al confirmar/reemplazar. Las asignaciones ya aceptadas no cambian de persona o instrucciones silenciosamente.

Rechazar/expirar libera únicamente el hold de esa persona, conserva el resto del equipo/equipamiento y genera una alerta persistente de cobertura pendiente. Una reserva ya confirmada queda en revisión operativa; no borrar su snapshot ni anunciar capacidad completa. El vencimiento lo controla el servidor además de una tarea idempotente. Reemplazo exige decisión explícita del dueño, nueva invitación y revisión de transporte/conductor; no asignación automática.

## Modelo y servicios

- `staff_availability_rules`: identidad, día ISO, hora local inicio/fin, timezone America/Chicago, rango de vigencia y version. Intervalos que cruzan medianoche se expresan como dos tramos.
- `staff_availability_exceptions`: fecha local, disponible/no disponible y tramos. La excepción del día sustituye la regla semanal. No elimina compromisos; cambios incompatibles crean alerta.
- `staff_invitations`: evento, identity/resource, puesto estable de EventResource/rol, event_version, assignment_revision, deadline UTC, status pending/accepted/declined/expired/cancelled, instruction_revision, decision_at, version. Un solo intento activo por puesto/recurso, conservando historial.
- `staff_assignment_holds`: referencia a invitación/recurso y intervalo ocupado, expires_at/released_at. Mismo protocolo de candados de recursos, orden ascendente, que reservas; nunca una segunda tabla ignorada por el buscador de disponibilidad.
- `staff_job_instructions`: revisión inmutable de campos aprobados para el personal, hash y created_by; aceptación reconoce una revisión concreta. Cambio relevante requiere reconocimiento nuevo, conservando anterior.
- `staff_actions`: idempotencia por actor+acción+UUID y hash del payload exacto. Auditoría de IDs, versiones y transición, sin tokens/teléfonos/cuerpo de instrucciones.

Servicios propuestos:

```php
ReadStaffDashboard::execute(StaffContext $staff, array $filters): array;
SaveStaffAvailability::execute(StaffContext $staff, array $input, string $key): array;
InviteStaff::execute(User $actor, int $eventId, array $input, string $key): array;
DecideStaffInvitation::execute(StaffContext $staff, int $invitationId, array $input, string $key): array;
ReadStaffJob::execute(StaffContext $staff, int $invitationId): array;
ExpireStaffInvitations::execute(): int;
ReadStaffCoverage::execute(User $actor, int $eventId): array;
```

`StaffContext` se obtiene del grant/sesión verificados, nunca de staff_id enviado por formulario. Los dueños no pueden aceptar en nombre del personal. Bloquear filas de evento y recursos en el orden del protocolo existente y luego invitación; revalidar permisos y versiones dentro de la transacción. El mismo UUID/payload devuelve la misma respuesta; UUID con otro payload →409. expected_version diferente →409 y recargar. No retener locks durante proveedores externos.

## Rutas y payload para Claude

Owner, auth+2FA+CSRF, privadas:

- POST `/admin/api/staff/{staff}/grants` `{expected_version,expires_at}`; respuesta `{id,version,expires_at,link}` no-store; revoke POST `/admin/api/staff/grants/{grant}/revoke` `{expected_version}`.
- GET `/admin/api/events/{event}/staff-coverage`: `{event_id,event_version,status,driver_covered,slots:[{slot_id,resource_id,staff_label,role,invitation_id,version,status,deadline_at,instruction_revision}],alerts:[{id,kind}]}`. Sin sueldo. Status complete exige equipo aceptado y validación vigente.
- POST `/admin/api/events/{event}/staff-invitations` `{expected_event_version,slot_id,resource_id,deadline_at,instruction_revision}`. 201; reintento devuelve misma invitación. No interfaz «enviado».

Portal staff, grant+CSRF, sin token en las rutas siguientes:

- POST `/staff/session` `{token}` →204; DELETE →204. Intercambio con Origin permitido/rate-limit, sin aceptar credenciales admin/clienta.
- GET `/staff/api/me`: `{staff:{display_name},timezone,version,availability,upcoming:[invitation],alerts}`. Paginación acotada, máximo50 y cursor; no directorio del equipo.
- PUT `/staff/api/availability` `{expected_version,weekly:[{weekday,starts_local,ends_local}],exceptions:[{date,available,intervals}]}`. Sin inferir disponibilidad por ausencia: mostrar unknown hasta declaración/confirmación del dueño.
- POST `/staff/api/invitations/{id}/decision` `{expected_version,instruction_revision,decision:accept|decline,reason?}` + Idempotency-Key UUID. Confirmar Houston+offset explícito para cualquier instante ambiguo CDT/CST; fechas inexistentes se rechazan.
- GET `/staff/api/invitations/{id}/job`: solo propia invitación aceptada y vigente; sin acceso por cambiar id. `{invitation_id,version,instruction_revision,event:{date,starts_at,ends_at,arrival_at,setup_minutes,timezone,address,parking,access},assignment:{role,character,outfit_version,bring,instructions,is_driver},day_contact:null|{name,phone}}`. day_contact solo en la fecha Houston del evento y desde campo aprobado específico. No copiar notas privadas de la clienta. address y detalles completos solo después de aceptar; invitación pendiente muestra fecha, horario, rol y zona aproximada suficiente para decidir.

Nada de total, depósito, saldo, pagos, precios, financial_revision_id, contrato, email de clienta, teléfonos de otros empleados o sueldo propio/ajeno en v1. Mostrar «quién maneja» como rol/is_driver; directorio/contacto del conductor solo si se aprueba expresamente. Texto EN/ES mediante locale, claves y fechas estables.

401 sin sesión; 403 identidad inactiva/admin incompatible; 404 registro ajeno o grant revocado/expirado; 409 versión/slot/deadline/consentimiento cambiado; 422 entrada inválida; 429 límite. No diferencias que permitan enumerar personal.

## Pruebas y salida

Cross-staff y acceso admin/clienta denegados; revocación inmediata; expiración/cambio de rol; doble invitación y carreras aceptar/rechazar/expirar; horario de verano; cambio de disponibilidad incompatible; conductor que rechaza; reemplazo con conflicto de traslado; idempotencia; hoja sin dinero y contacto restringido al día local. CI MariaDB y render móvil antes de review. Este documento suma 0% de implementación. No agenda ni envío real hasta aprobación correspondiente.

## Respuesta a Claude — próxima invitación y sesión

`GET /staff/api/me` incluye `staff:{display_name,locale}` y `upcoming[]` con este esquema cerrado, compatible con la vista previa44b6941:

```json
{ "id": 41, "version": 1, "status": "pending", "deadline_at": "2026-10-28T23:00:00Z", "deadline": "mié. 28 oct., 6:00 p. m. CDT", "instruction_revision": 1, "event": { "date": "sáb. 7 nov.", "hours": "2:00–4:00 p. m. CST", "arrival": "1:15 p. m. CST", "area": "Katy, TX 77494" }, "assignment": { "role": "Personaje", "character": "Personaje de ejemplo", "outfit_version": "Gala" } }
```

Ejemplo ficticio; el servidor produce las etiquetas según locale y la fecha Houston, incluyendo CDT/CST cuando aplica. deadline_at es el instante UTC autoritativo; deadline es etiqueta, no entrada para decisiones. Sin vencimiento pendiente: ambos null. `url` opcional solo para trabajo propio aceptado, construida en servidor sin token ni dirección dentro de la URL. Área antes de aceptar = ciudad/ZIP desde datos estructurados aprobados por el dueño; si falta ciudad, mostrar ZIP/zona pendiente, nunca extraer o filtrar una dirección completa a ciegas. Sin calle, contacto ni directorio del equipo en invitación pendiente.

Sesión larga: Claude propone canjear enlace de≤7d por sesión30–60d. Pregunta enviada a César; **hasta su respuesta se conserva el límite vigente de7d y la sesión no sobrevive al grant**. No implementar prórroga silenciosa. Cualquier futuro plazo mayor requiere entidad de sesión separada y revocación inmediata por identidad/recurso/grant; no una cookie que ignore su expiración. Los holds pendientes exclusivos fueron acordados, sin cambiar la regla de aceptación explícita.
