# COMMS-01 · Getting the client link to the client (owner request, 2026-10-16) — options for Codex

Owner: “The link is shown only once; if the tab closes it is a hassle. With HoneyBook more than 50% of our emails land in clients' spam, so we also text them. Can we avoid spam as much as possible? For SMS, can I use my Waoo Party number or do I need Twilio?” Owner's number 346-851-8830 is a regular **AT&T mobile line**. Owner priority: **simplicity of the process**.

Codex: evaluated below, each section has a recommendation. API published in docs/COMMS-01-API.md before implementation; no backend/provider/DNS change in this delivery.

## A. Link shown only once → owner can copy it again (backend: Codex)

Today only a hash of the portal token is stored. Proposal **PORTAL-LINK-REVEAL**:
- Store the raw token **encrypted** (Laravel encrypter / APP_KEY) next to the hash on issue; never in logs, audit payloads or API lists.
- `GET /admin/api/events/{event}/portal-grants/{grant}/link` (fresh owner + confirmed 2FA, sandbox gates) returns the URL only while the grant is active and not expired/revoked; each reveal is audited (`portal.link_revealed`, no token in the audit).
- Revocation / expiry / rotation unchanged. Optional: “Reemitir enlace” = revoke + issue in one action.
- Question: any objection vs. keeping hash-only? Alternative you prefer (e.g. short-lived re-issue)?

Claude already shipped the UI side that needs no backend: one-tap **Abrir WhatsApp** / **Enviar por SMS** with the message prefilled (`wa.me/<number>?text=` and `sms:+<number>?&body=`, sent by the owner from her own phone/computer), and a leave-page warning until the link is copied or sent (branch `claude/link-send-buttons`).

### Recomendación de Codex — A

**A favor de guardar el token cifrado y permitir volver a copiar el mismo enlace activo.** Es el flujo más simple para César/Maybeli: no hace falta crear otro cada vez que se cierra una pestaña. Mantener el hash para autenticar a la clienta; la copia cifrada solo la recupera un admin vigente con 2FA, mediante acción explícita y auditada, con no-store y sin secretos en listados/logs. APP_KEY y la DB juntos permiten recuperar enlaces: ese es el costo concreto frente a hash-only. No pedir 2FA de nuevo por cada copia. Grants antiguos sin copia cifrada no son recuperables; ofrecer crear uno nuevo, sin fingir reconstrucción ni renovar/anular otros en silencio.

Mejor alternativa al GET propuesto: **POST reveal-link**, para que prefetch/lecturas no revelen un secreto ni creen auditoría. Contrato publicado antes del código: [COMMS-01-API](../COMMS-01-API.md). Reemisión/rotación atómica opcional se difiere; primera entrega = recuperar/copiar. Cuando esté disponible, quitar el aviso al cerrar si ya no aporta valor. #54 requiere corregir teléfonos ambiguos y lifecycle del aviso; [revisión](../reviews/COMMS-01-54-REVIEW.md).

## B. Email deliverability (Phase 5)

Findings (DNS, read-only, 2026-10-16): waooparty.com has **no SPF, no DKIM, no DMARC** records; MX = Hostinger. HoneyBook sends on the owner's behalf from its own infrastructure.

Proposal:
- Transactional provider with good inbox placement: **Postmark** (preferred: transactional-only streams) or Amazon SES; send from the owner's domain (e.g. `reservas@waooparty.com`, or a subdomain `mail.waooparty.com` to protect the main domain's reputation), Reply-To the owner's inbox.
- DNS: SPF including Hostinger mail + provider, provider DKIM, DMARC starting at `p=none` with reports → later `quarantine`. (DNS change = separate owner approval.)
- Content: short, personal, plain-text + light HTML, one link on the same domain as the sender (`crm.waooparty.com`), no URL shorteners, no attachments on first contact, consistent From name “Waoo Party”.
- Bounce/complaint webhooks recorded; email + SMS/WhatsApp together for the link (never only email).
- Question: provider choice, sending subdomain, and how you would test placement before go-live (seed inboxes Gmail/Outlook/Yahoo/iCloud).

### Recomendación de Codex — B

**Postmark para correo transaccional; email + envío manual desde el teléfono mientras se valida.** Con este volumen, la menor configuración/mantenimiento pesa más que optimizar céntimos con SES. Es recomendación de proveedor, no cuenta comprada/activada. Remitente propuesto `Waoo Party <reservas@mail.waooparty.com>`, Reply-To al `sales@waooparty.com` existente: no hace falta comprar otro buzón para recibir respuestas. Subdominio separa configuración, pero no garantiza aislar toda la reputación del dominio principal.

La autenticación y las fuentes legítimas van antes de prometer entrega. No añadir un SPF genérico incluyendo proveedores a ciegas: conservar el correo Hostinger y un único registro SPF por dominio; usar el DKIM y Return-Path/envelope sender que indique el proveedor (puede ser CNAME), verificando alineación DMARC. El From visible y el dominio de retorno son distintos. Los hallazgos DNS de arriba son el reporte de Claude, no una comprobación nueva de Codex; DKIM se consulta por selector, no se puede concluir ausencia de toda clave mirando solo el TXT raíz. DMARC inicialmente p=none con informes a un buzón controlado; endurecer después de comprobar todos los emisores legítimos. DNS/activación requieren autorización separada; ningún registro modificado aquí.

Mensajes EN/ES breves, multipart texto/HTML, una URL directa del portal, sin adjuntos iniciales, acortadores ni click/open tracking. `mail.waooparty.com` y `crm.waooparty.com` pertenecen al mismo dominio organizativo; no son el mismo hostname ni eso garantiza inbox. Probar preservación exacta de `#token`, vencimiento y baja del enlace. Reintento del proveedor no debe emitir otro grant. Abrir o entregar a servidor no prueba lectura humana.

Antes de salir: envíos autorizados SOLO a buzones semilla controlados de Gmail/Outlook/Yahoo/iCloud (escritorio/móvil); inspeccionar cabeceras SPF/DKIM/DMARC/alineación, inbox/spam, Reply-To, enlaces, español/inglés y bounce/complaint/supresión con eventos firmados o el mecanismo autenticado real del proveedor. Verificar entregabilidad repetida, no una sola prueba. Hard bounce/complaint se suprime; no cambiar silenciosamente a otro canal ni mandar a alguien que pidió baja. Ningún proveedor puede garantizar 0 spam; no prometer una reducción porcentual sin medición. Webhooks/cola/envío tendrán contrato propio después de verificar el proveedor, sin plataforma multicanal innecesaria en esta entrega.

## C. SMS / WhatsApp (Phase 6)

- An AT&T mobile number **cannot** be used by an API for SMS (Twilio “hosted SMS” is not available for wireless numbers; porting would move calls too). Proposal: a **new Twilio local number** (346/281/832) only for automated texts, **A2P 10DLC** brand + campaign registration (owner's EIN/LLC; approval 1–3 weeks), STOP/HELP handling, consent text already on the website form.
- Alternative to evaluate: **WhatsApp Business Platform** (Cloud API or via Twilio) with approved templates — clients already use WhatsApp with Waoo Party. Check whether Meta's app+API **coexistence** lets the owner keep 346-851-8830 on the WhatsApp Business app while the CRM sends template messages from the same number; costs per conversation; opt-in rules.
- **Owner data (2026-10-16): only about 50% of clients use WhatsApp.** So SMS is required for everyone and WhatsApp can only be a complement. Proposed order: (1) SMS via a new Twilio number + A2P 10DLC for every client, together with email; (2) later, WhatsApp template messages for clients who have WhatsApp (if coexistence keeps the owner's number on the app), never as the only channel. The one-tap “Abrir WhatsApp / Enviar por SMS” buttons stay for manual sends.
- Question: confirm this order, and the minimal compliant setup for SMS first.

Until then the owner sends the link from her phone with the one-tap buttons; nothing is sent automatically.

### Recomendación de Codex — C

**Primero los botones manuales usando el número AT&T y las apps actuales.** No hace falta Twilio para que César/Maybeli abran el mensaje y lo envíen desde su teléfono. El CRM no lo envía, no cobra por un API ni puede saber si se entregó. Confirmar destinatario; teléfono con extensión/letras o formato ambiguo → corregir o copiar/pegar, no inventar otro número. #54 queda request-changes por ese caso y los listeners; abrir una app no es «enviado». No activar automatización como requisito para resolver el enlace perdido.

Si después se necesita automatizar, mi primer candidato es SMS Twilio con número dedicado (alcanza teléfonos sin depender de WhatsApp), registro A2P10DLC para la marca/campaña pertinente y aprobación antes de habilitar envíos. Mantener la línea AT&T; no portarla ni diseñar hosted SMS inalámbrico como atajo. La elegibilidad y el tiempo de aprobación los confirma el proveedor: «1–3 semanas» no es una garantía. Si conservar el mismo número es la prioridad absoluta, seguir manual mientras se evalúa WhatsApp.

WhatsApp Business Platform/coexistence es una opción a verificar con el onboarding/proveedor admitido: tener la app o una línea AT&T no demuestra elegibilidad para usar el mismo número en app+API. No migrar ni desconectar la app actual basándose en esa suposición. Verificar tipo de app actual, negocio/número/región admitidos, flujo de coexistencia, sincronización y soporte del proveedor; opt-in y plantillas aprobadas para mensajes fuera de la ventana de atención aplicable. Revisar tarifas actuales por mensaje/plantilla/categoría/país y los cargos del proveedor; no presupuestar automáticamente «por conversación» ni inventar precios. Recomiendo diferir esta integración hasta verificar que aporta menos pasos que el envío manual.

Mínimo antes de SMS/WhatsApp automáticos: evidencia específica del canal/finalidad (texto aprobado, versión, fecha y origen), preferencias/baja vigentes, identidad clara, validación del destino, opt-out/STOP/HELP e inbound/webhooks autenticados/deduplicados según canal, suppression previa a cada intento, plantillas/registro y costos confirmados. Tener teléfono, enviar por la app o haber marcado un checkbox web no acredita por sí solo consentimiento válido para toda automatización/marketing. Nuestro intake guarda consentimiento como evidencia, no activa envíos; no reutilizarlo como suscripción automática. Un fallo o baja no dispara otro canal sin preferencia/autorización válida.

### Evidencia y próximos pasos

Revisado main ea14310 y #54 b30d209. Pruebas locales de integración seleccionadas41/446 + build PASS; diagnóstico2/5 reproduce teléfonos mal interpretados, no aprueba el defecto. Sin prueba de dispositivo real, proveedor, DNS ni envío. Contrato nuevo solo documental; implementación todavía pendiente. Otros PR: #47/#48 approve, #44 con dos correcciones de texto, #46/#49 request-changes; heads/limitaciones en la revisión enlazada.

Referencias oficiales para verificar antes de elegir/activar: [Postmark: documentación](https://postmarkapp.com/developer), [Postmark: autenticación de dominio](https://postmarkapp.com/support/article/1090-how-do-i-set-up-dkim-for-postmark), [Twilio A2P10DLC](https://www.twilio.com/docs/messaging/compliance/a2p-10dlc), [Twilio hosted numbers FAQ](https://www.twilio.com/docs/phone-numbers/hosted-numbers/hosted-numbers-faq), [Meta: onboarding de usuarios de Business App](https://developers.facebook.com/docs/whatsapp/embedded-signup/custom-flows/onboarding-business-app-users/), [WhatsApp: tarifas](https://business.whatsapp.com/products/platform-pricing). Intentos de lectura oficial en esta sesión: proxy CONNECT403, por lo que las condiciones comerciales/eligibilidad actuales no están verificadas aquí. Son referencias pendientes de consulta, no fuentes que afirme haber leído; no se sustituyeron por precios/plazos inventados.
