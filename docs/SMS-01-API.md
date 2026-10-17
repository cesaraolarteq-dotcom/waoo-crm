# SMS-01 — contrato v1, transporte desactivado

2026-10-17 · autorización de Fase 6, después de REPORT-A y STAFF-01. Contrato previo al código. No compra número, registra campaña, contacta Twilio ni envía mensajes. Backend Codex, pantallas/textos EN/ES Claude; revisión cruzada. Los botones manuales «Abrir SMS/WhatsApp» de COMMS-01 siguen siendo acciones manuales, no prueba de envío o consentimiento.

## Activación y A2P

`DisabledSmsTransport` es la única implementación inicial. Flags independientes `sandbox_sms`, `sms_transport_enabled=false`; ambiente production bloqueado, pruebas solo con destinatarios sintéticos/números de prueba autorizados. Preparar/ver una intención no debe llamar al proveedor. No reutilizar el interruptor de MAIL ni habilitar SMS al activar un correo.

Cuenta y número nuevo de Twilio en área346/281/832. El AT&T de César permanece igual: no puede usarse como remitente de la API. La dueña tramita en la consola segura la marca Waoo Party LLC y A2P10DLC, EIN y demás datos comerciales; nunca pegarlos con claves/tarjetas en chat o git. Campaña y casos de uso deben cubrir enlaces/recordatorios transaccionales de clientas y trabajos del personal, o campañas separadas si Twilio lo exige. Preparar descripción, opt-in, ejemplos EN/ES, política de privacidad, términos, STOP y HELP. Aprobación efectiva del proveedor, número/Messaging Service asociado, copia y consentimiento aprobados, destinatarios de prueba, pruebas autenticadas de callbacks y aprobación explícita de César son requisitos antes de activar. Una casilla local «aprobado» no sustituye aprobación A2P real. Ninguna compra/registro se inicia con esta entrega.

## Consentimiento por canal y propósito

`communication_consents`: sujeto customer|staff_identity, contact_version, teléfono normalizado E.164 y cifrado + hash indexable, channel=sms, purpose=booking_updates|staff_jobs, status granted|withdrawn, versión/hash/texto exacto de la declaración aprobada, locale, fuente formulario/portal/dueño con evidencia explícita, timestamp UTC, IP/UA según fuente y límites de retención documentados. Registro append-only, sin aceptar un booleano sin texto/evidencia. Casilla vacía por defecto. No inferir consentimiento de reservar, dar el teléfono, un correo, importar HoneyBook ni pertenecer al equipo. Consentimiento no obligatorio para reservar o cobrar. Cambiar número exige consentimiento para el nuevo; el anterior conserva su evidencia y supresión.

`phone_suppressions`: por teléfono normalizado, no solo por clienta/evento; scope SMS global, motivo/source_message_sid, vigente y versión. STOP de un número compartido impide mensajes tanto de clientas como de personal en ese número. Mantener consentimientos de email separados. START no reanima automáticamente todos los propósitos: registrar solicitud y exigir el flujo de reinscripción aprobado para el propósito correspondiente. STOP posterior gana frente a un job ya preparado; no prometer detener un mensaje que el proveedor ya aceptó.

Propuestas:

```php
RecordSmsConsent::execute(CommunicationContext $actor, array $input, string $key): array;
PrepareSms::execute(User $actor, array $input, string $key): array;
ReadSmsReadiness::execute(User $actor): array;
ReceiveTwilioInbound::execute(VerifiedTwilioRequest $request): void;
ReceiveTwilioStatus::execute(VerifiedTwilioRequest $request): void;
DispatchSms::execute(int $messageId): void;
```

CommunicationContext representa dueño activo+2FA, portal de clienta o staff verificado. El sujeto viene del contexto; dueño no puede fabricar un consentimiento. La evidencia manual exige nota/origen explícito y no cuenta como consentimiento web. Esto no es un permiso para reenviar por otro canal después de STOP.

## Intención, jobs y lecturas

`sms_messages`: environment=test, propósito, referencia de origen (evento/prospecto/invitación), versión/hash inmutables, recipient/contact_version, consent_id, template_version/hash, cifrado de payload renderizado, idempotency hash, estado y timestamps, provider_message_sid, errores enumerados. Jobs llevan solo message_id. Auditoría sin número, texto, token o enlace. Reutilizar referencias de dominio de MAIL, no crear filas falsas de dinero/evento para un prospecto. Hoja de trabajo del personal siempre por enlace privado; SMS sin importes, dirección privada completa ni pago del equipo.

Estados: prepared → blocked|ready → claimed → accepted → delivered|undelivered|failed; `unknown` para interrupción después de reclamar sin evidencia fiable. accepted no significa delivered, abrir app no significa sent. Deduplicación por origen+revisión+propósito+template_version; mismo UUID/payload devuelve mismo recurso, payload distinto409. Nunca reintentar a ciegas unknown; investigar por SID/evidencia. Callbacks tardíos/duplicados no retroceden delivered a sent; estados terminales contradictorios conservan evidencia y revisión, no se borran.

Antes de claim, volver a verificar feature, ambiente, readiness/A2P, destinatario permitido, identidad/contacto actual, consentimiento específico, supresión, fuente vigente, evento/invitación/grant no revocado, plantilla aprobada y horario/política aprobados. No inventar horario de silencio: hasta definirlo no activar. Bloquear/invalidar pendientes tras STOP o revocación. No locks durante llamada al proveedor; transición transaccional y claim único. En v1 DisabledSmsTransport bloquea sin red ni falsa entrega, aunque la intención se pueda inspeccionar.

Owner auth+2FA+CSRF, no-store/private:

- GET `/admin/api/sms/readiness` → `{enabled:false,transport:'disabled',brand_status,campaign_status,number_ready,approved_templates,missing:[code]}`. Sin AccountSID/AuthToken ni EIN.
- POST `/admin/api/sms/messages` + UUID → `{purpose,source_type,source_id,expected_source_version,template_key,locale}`; no recipient/body/url arbitrarios; consentimiento y destinatario se resuelven en servidor. 201 prepared/blocked, nunca «enviado».
- GET `/admin/api/events/{event}/sms-messages?page=1` → paginación25/max100, metadatos `{id,purpose,template_key,status,created_at,delivered_at,error_code}`; no texto/token/teléfono completo. Vista staff solo de sus comunicaciones, sin metadatos de clientas.
- POST `/portal/api/sms-consent` o `/staff/api/sms-consent` con CSRF y contextos distintos: `{purpose,expected_contact_version,consent_text_version,consent_text_sha256,granted}` +UUID. Texto actual aprobado en proyección de cada portal; stale409. No datos del sujeto desde formulario.

Errores401/403auth,404ajeno/off,409versión/idempotencia,422validación,429throttle. No endpoint público de enviar ni aceptar plantillas arbitrarias. Campos disponibles/estados son propuesta API, no código ya entregado.

## Recepción autenticada Twilio

POST `/webhooks/twilio/sms/inbound` y `/webhooks/twilio/sms/status`. Flags de recepción propios, inicialmente OFF. HTTPS, cuerpo form-urlencoded máximo16KiB, rate-limit sin descartar STOP legítimo por cliente, sin sesión/CSRF; autenticación obligatoria mediante validador oficial de Twilio para `X-Twilio-Signature` (HMAC-SHA1, URL pública exacta + parámetros según formato oficial). No crear un esquema SHA256/timestamp ficticio. Configurar URL canónica confiable: jamás construirla desde Host/Forwarded arbitrario; proxies confiables explícitos. Si algún formato futuro usa JSON/bodySHA256, habilitarlo solo con el algoritmo oficial y pruebas; no fallback permisivo.

Además de firma: AccountSid y MessagingServiceSid/To del servicio autorizado, formato MessageSid y destinatario en status coincidentes con el mensaje guardado. Un status desconocido no puede crear un sms_message ni asociarse por teléfono. Guardar recibos normalizados mínimos, hash y SID únicos; mismo replay200, contenido contradictorio409/revisión. Firma inválida403, recepción apagada404, payload inválido422 o413. No registrar cuerpo ni cabeceras de autenticación. La clave vive en secretos del entorno, nunca logs/git.

Inbound verificado: usar OptOutType cuando existe y comandos STOP/STOPALL/UNSUBSCRIBE/CANCEL/END/QUIT, con normalización de espacios/case; HELP/INFO como ayuda, START/UNSTOP como solicitud de reinscripción, no nuevo permiso global. STOP registra supresión primero y cancela lo no reclamado de todos los propósitos en una transacción. HELP no vende, no reinscribe, no elimina supresión. Registrar señales no reconocidas sin mandar una respuesta libre generada por IA. Responder200 con TwiML vacío: sin `<Message>` automático mientras transporte está OFF. Configurar posteriormente Advanced Opt-Out/respuestas del proveedor para evitar dos contestaciones; textos de marca, ayuda y baja requieren aprobación EN/ES antes de live.

## Pruebas y pendientes

Fixtures sin números reales: firmas válidas/inválidas, URL proxy alterada, cuenta/número/SID ajeno, replay/conflicto, callbacks fuera de orden, STOP vs claim, número compartido staff/clienta, consentimiento otro propósito/versión, revocación/cambio de número, limits y aislamiento por actor. Prueba de transporte que prohíba cualquier petición de red con flags OFF. Verificación real de A2P/callbacks/entrega requiere entorno proveedor autorizado aparte, no cubierta por tests sintéticos.

Falta decisión de César sobre horario permitido, lista real de personal/números en privado, textos aprobados y cuenta/A2P. No bloquea construir pantallas de solo lectura/consentimiento en sandbox contra este contrato, pero nada se activa. Contrato suma0% de implementación; backend, UI, review, main y aceptación se registran por separado.
