# COMMS-01 — contrato antes de implementación

2026-10-16 America/Chicago. Propuesta de Codex para revisión de Claude. Este documento publica el contrato; **los nuevos campos/servicios/rutas no están implementados**. No autoriza DNS, proveedores, envíos ni despliegues. Prioridad del dueño: recuperar/copiar fácilmente el enlace y enviarlo desde su teléfono.

## Alcance de esta primera entrega

Solo recuperación administrativa del mismo enlace activo. No plataforma de mensajería ni cola de envíos nueva. Los botones de #54 siguen abriendo aplicaciones del dueño; no llaman APIs de Twilio/Meta ni registran entrega. El texto de copia puede ser genérico EN/ES («Revisa tu reserva de Waoo Party: …») para no repetir saldos/estados antiguos al recuperar un enlace. Las pantallas pueden consultar sus datos vigentes por los caminos existentes.

## Datos y compatibilidad

- `portal_grants.encrypted_token`: TEXT nullable, oculto en serialización y fuera de entradas masivas/formularios. Nuevas emisiones guardan dentro de la misma transacción el token cifrado y autenticado con el encrypter de Laravel/APP_KEY; el hash SHA-256 sigue siendo lo que autentica a la clienta. No guardar URL completa ni cambiar la autenticación por hash.
- Payload cifrado versionado y ligado a grant_id/event_id/customer_id/expiry/scopes; antes de devolverlo verificar esos vínculos y, con comparación constante, SHA-256(token)==token_hash. Evita reutilizar un ciphertext de otra ficha. No cifrar dos veces mediante cast y llamada explícita.
- `IssuePortalGrant::execute` conserva firma, validaciones, alcances, expiración ≤30 días, DTO inicial y recuperación post-core de QuickBook. El token solo lo genera el servidor; no acepta token/URL/ciphertext del navegador. La emisión y el guardado cifrado son atómicos. No incluir atributos secretos al auditar el modelo.
- Grants anteriores siguen funcionando con su hash. No se puede reconstruir su token: `encrypted_token=null` no se rellena con valores inventados. La UI ofrece «Crear enlace nuevo» por la emisión existente y permite anular el viejo explícitamente. No renovar vencimiento ni anular otros enlaces al pulsar Copiar.
- RevokePortalGrant conserva su firma/candado y borra el material cifrado en la misma transacción. El hash y la evidencia histórica se conservan. El vencimiento siempre impide recuperar, incluso antes de cualquier limpieza posterior de ciphertexts. Rotación atómica/reemisión y purga programada quedan como entregas separadas; no se añade cron en este contrato.
- Respaldos con DB cifrada + claves tienen capacidad de recuperar enlaces activos: conservar controles separados de acceso a APP_KEY/backups y su rotación/APP_PREVIOUS_KEYS. Perder la clave hace imposible recuperar esas copias, aunque el enlace original aún pueda autenticarse por hash. No pedir ni copiar claves por chat/repositorio.

## Servicio

```php
App\Services\Portal\RevealPortalGrantLink::execute(
    User $actor, int $eventId, int $grantId
): RevealedPortalLink
```

DTO solo `grant_id`, `event_id`, `url`, `expires_at`. Sin propiedad token/ciphertext. Ambos admins completos pueden recuperar; no limitar al emisor original. Reusar sandbox_documents + ambiente local/testing/staging, deny production y RecordActor fresco (rol, activo y 2FA confirmada). No desafío TOTP adicional por cada copia.

Bajo candado de la misma fila de grant que usa revoke/signature: validar event/grant/customer vigentes, no revocado/no vencido, descifrar/verificar y registrar `portal.link_revealed`. Solo metadata (event_id/grant_id; actor lo aporta Audit), nunca URL/token/ciphertext/mensaje. Fallo de auditoría/persistencia no devuelve la URL. El orden grant→event si se necesita lock del evento conserva el protocolo de firma; no introducir event→grant para una fila existente. Revalidar estado al adquirir el candado, no usar un modelo hidratado por el navegador.

La acción no cambia saldo, firma, presentación PDF, scopes, expires_at, viewed/opened ni grant de QuickBook. URL construida sobre el origen seguro configurado y `/portal#token=…`; no token en query/ruta ni host aportado por el navegador. La validez es a la lectura bajo lock: si otro admin anula después, la clienta verá el enlace anulado.

## HTTP administrativo

`POST /admin/api/events/{event}/portal-grants/{grant}/reveal-link`

Body vacío. Sesión owner+2FA y CSRF reales en staging, TLS, throttle admin-write existente; nunca endpoint público/portal. Usar POST porque revela un secreto con auditoría y no debe dispararse por prefetch de un GET. Botón explícito «Copiar enlace», sin reveal al cargar/listar la pantalla.

200:

```json
{
  "grant_id": 17,
  "event_id": 42,
  "url": "https://crm.example.test/portal#token=FICTIONAL_TOKEN_ONLY",
  "expires_at": "2026-10-20T20:00:00+00:00"
}
```

Los IDs/token son ficticios; en ejecución se usa token aleatorio criptográfico del formato existente. Respuestas de éxito y error: `Cache-Control: no-store, private`, `Pragma: no-cache`, `Referrer-Policy: no-referrer`, `X-Content-Type-Options: nosniff`. Sin redirección al portal, storage del navegador ni token en atributos HTML/listados serializados fuera del resultado explícito. El botón intenta copiar y mantiene un campo visible para copia manual si falla el clipboard; no declara éxito al fallar.

Errores estables `{"code":"…","message":"…"}`:

| HTTP | code | Pantalla |
| --- | --- | --- |
|401/403| auth/gates existentes | Login/2FA o función de pruebas deshabilitada; no revelar datos |
|404| grant_not_found | Grant inexistente o no corresponde a evento/clienta |
|410| grant_inactive | Vencido/anulado: ofrecer crear otro, sin resucitarlo |
|409| link_not_recoverable | Grant antiguo sin copia cifrada, clave/material no recuperable o binding/hash inválido; ofrecer crear otro |
|429| rate limit existente | Reintentar después sin emitir otro enlace |
|503| link_reveal_unavailable | Origen/auditoría/persistencia no disponibles; reintentar, sin revelar secretos |

Auth/CSRF/throttle pueden usar el formato existente del framework; no se promete cambiar globalmente sus errores. CSRF419 se mantiene. Errores/logs de descifrado usan un código fijo, sin excepción con ciphertext o input sensible. Listados/proyecciones de admin pueden indicar solo `can_reveal_link` (estado activo + copia presente, no garantía de descifrado); las proyecciones de clienta nunca exponen copia ni metadata administrativa. No añadir una ruta de listado solo para esta marca; usar el presenter existente.

## B y C: separación de alcance

Email recomendado Postmark, From `Waoo Party <reservas@mail.waooparty.com>`, Reply-To `sales@waooparty.com`, sujeto a verificación de dominio/proveedor y aprobación antes de DNS/envíos. SMS automatizado, si se necesita después, con número dedicado/proveedor y A2P/consentimiento; WhatsApp API solo tras comprobar coexistencia/opt-in/templates. **No se publican rutas ficticias de envío ni webhooks sin elegir/verificar proveedor**: sus firmas/autenticación, deduplicación, estados y opt-outs tendrán contrato propio antes del código. Leer recomendaciones por sección en docs/tickets/COMMS-01.md.

## Criterios de aceptación antes de conectar pantallas

- Copiar/reabrir el MISMO enlace nuevo activo; sigue funcionando en portal. La operación no crea grants/pagos ni altera expiración/contrato. César y Maybeli autorizados; clienta/staff/admin sin2FA/producción denegados, CSRF/TLS/no-store reales.
- Grant de otro evento/customer404, vencido/anulado410, antiguo/ciphertext alterado/clave ausente/binding intercambiado409 sin exposición. Modelos/listados/audit/logs/backups de prueba no contienen plaintext; error de auditoría falla sin devolver URL.
- Carrera real reveal/revoke linealiza sobre la fila y nunca revela DESPUÉS de un revoke ya confirmado. La firma conserva su protocolo de locks. Revocar borra ciphertext; no borra firma/evidencia ni invalida la función de otro grant.
- Nueva emisión guarda hash+ciphertext+audit juntos; un fallo revierte todo, incluida la emisión post-core sin revertir pagos que ya se confirmaron (recuperación QuickBook existente).
- UI solo accede por servicio, maneja copy failure, no representa abrir SMS/WhatsApp como envío/entrega y conserva fallback. Probar iOS/Android/desktop autorizados antes de prometer compatibilidad de sus compositores; sin mensajes a clientas para verificarlo.
