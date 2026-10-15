# Waoo CRM — Plan maestro

Resumen para el dueño. **La especificación oficial es `docs/PROJECT_PLAN.md` (v2)**, con las decisiones D1–D6 de abajo y el orden de trabajo en `docs/BACKLOG.md`. Si algo de este resumen contradice al v2 o a una decisión registrada, mandan el v2 y la decisión. El documento de Claude Docs es solo una copia de lectura.

Recomiendo construir un CRM propio de Waoo Party, por fases, en crm.waooparty.com. Reemplazaría HoneyBook ($80 al mes) para unos 40 eventos al mes y 2 usuarios, con Stripe para tarjetas, Zelle como pago principal, Twilio para SMS y un portal donde la clienta firma y paga. HoneyBook sigue funcionando en paralelo hasta que el sistema nuevo esté probado.

## Requisitos

| Tema | Lo que necesita Waoo |
| --- | --- |
| Clientes | Base de datos de todas las clientas, con historial de eventos, contratos y pagos |
| Contratos | Crear contratos desde una plantilla y que la clienta los firme en línea |
| Facturas y pagos | Facturas con depósito y saldo; Zelle como pago principal y Stripe para tarjeta |
| Calendario | Ver y controlar todos los eventos en un calendario |
| Portal del cliente | La clienta ve su contrato y su factura, firma y paga |
| Mensajes | SMS y correo con notificaciones; WhatsApp si se puede, no obligatorio |
| Usuarios | 2 personas: el dueño y su esposa |
| Volumen | Unos 40 eventos al mes; unas 250 clientas archivadas para migrar |
| Uso | Principalmente en computadora; también desde el celular |
| Hoy | HoneyBook unos $80 al mes; Hostinger Business Web Hosting |

## Cómo fluye una reserva

1. **Consulta.** Llega del formulario de la web (fecha, ZIP, paquete y costo de transporte ya calculado), de WhatsApp o de una llamada.
2. **Disponibilidad.** El CRM revisa el calendario: eventos del día, horarios y tiempo de traslado.
3. **Cotización.** Paquete y extras del catálogo + costo de transporte + descuento (solo manual) + depósito del 50 %. Se envía por correo y SMS.
4. **Contrato.** Al aceptar, se genera con los datos del evento y las políticas. La clienta lo firma en su portal.
5. **Depósito.** Zelle (marcado a mano) o tarjeta por Stripe (automático). Confirmación a la clienta.
6. **Confirmado.** El evento queda bloqueado en el calendario y en la disponibilidad de la web.
7. **Recordatorios.** 7 días y 1 día antes; saldo 24 h antes (cláusula 1.2).
8. **Evento y cierre.** Saldo pagado, gracias y pedido de reseña en Google.

## Módulos

| Módulo | Qué hace | Fase |
| --- | --- | --- |
| Clientes | Ficha con datos, eventos, contratos, facturas, pagos y mensajes; búsqueda y etiquetas | 1 |
| Eventos y tablero | Fecha, hora, dirección, ZIP, paquete, personajes, niño/a, edad, tema, notas; tablero por etapa | 1 |
| Calendario | Vista mes/semana/día, bloqueos y tiempo entre eventos; envío a Google Calendar | 1 |
| Cotizaciones | Catálogo con precios reales, costo de transporte, descuento y depósito del 50 % | 1 |
| Contratos | Plantilla con las políticas, datos del evento, firma en línea con registro; PDF | 1 |
| Facturas y pagos | Depósito y saldo, Zelle manual, tarjeta con Stripe, recibo automático | 1 |
| Correos | Cotización, contrato, recibo y confirmación desde @waooparty.com | 1 |
| SMS y bandeja | SMS por Twilio con respuestas en una bandeja | 2 |
| Automatizaciones | Recordatorios, aviso de saldo, gracias y reseña, avisos internos | 2 |
| WhatsApp y reportes | WhatsApp Business por Twilio (opcional); ventas, depósitos, paquetes y zonas | 3 |

## Portal del cliente

- Entra con un enlace seguro (correo o SMS), sin contraseña; los enlaces vencen y se pueden reenviar.
- Ve los detalles de su fiesta, acepta la cotización y firma el contrato.
- Paga con tarjeta o ve las instrucciones de Zelle con monto exacto y referencia.
- Descarga contrato firmado y recibos en PDF. Español e inglés.
- Nadie ve los datos de otra clienta.

## Tecnología y servicios

| Pieza | Elección | Por qué |
| --- | --- | --- |
| Dónde vive | crm.waooparty.com en el plan Business Web Hosting de Hostinger | Sin costo extra; SSH, cron, MySQL y despliegue con Git (verificar en Fase 0) |
| Base del sistema | Laravel (PHP) + MySQL, panel de administración tipo Filament | Mismo lenguaje que la web; usuarios, correos, tareas y PDFs resueltos |
| Pagos con tarjeta | Stripe (Checkout y webhooks) | Nunca guardamos tarjetas; el pago se marca solo |
| Zelle | Registro manual | Zelle no permite automatizar el cobro |
| SMS | Twilio, número actual | Envío y respuestas; requiere registro A2P 10DLC |
| Correo | Resend o Postmark desde @waooparty.com | Entregabilidad (SPF/DKIM) |
| Calendario | Propio + envío a Google Calendar | Visible en el celular; la disponibilidad de la web pasará a leer el CRM |
| Web actual | El formulario inteligente crea el prospecto | Reutiliza la calculadora de transporte |
| Copias de seguridad | Copia diaria de la base de datos fuera de Hostinger | No perder información |

## Datos que guarda

| Registro | Contiene | Se conecta con |
| --- | --- | --- |
| Clienta | Nombre, teléfono, correo, idioma, notas, etiquetas | Eventos, mensajes |
| Evento | Fecha, hora inicio/fin, dirección, ZIP, costo de transporte, paquete, extras, personajes, niño/a, edad, tema, etapa | Clienta, cotización, contrato, facturas |
| Cotización | Líneas (paquete, extras, transporte), descuento, total, depósito | Evento |
| Contrato | Versión de plantilla, texto final, firma (nombre, fecha, hora, IP, navegador), hash del documento, PDF | Evento |
| Factura | Número, líneas, total, depósito, saldo, vencimientos, estado | Evento, pagos |
| Pago | Método (Zelle, tarjeta, efectivo), monto real recibido, cargo del 3 %, referencia, fecha | Factura |
| Mensaje | Canal, dirección (enviado/recibido), texto, estado | Clienta, evento |
| Plantilla | Correos, SMS y contrato, EN y ES | Automatizaciones |
| Usuario | Dueño y esposa, con 2FA | Registro de cambios |

## Contrato como plantilla

Las 10 secciones de términos del contrato actual (HoneyBook) quedan igual; solo se llenan los datos del evento.

| Parte del contrato | Fuente en el CRM |
| --- | --- |
| Datos de la clienta | Ficha de la clienta |
| Fecha, hora de inicio y fin | Evento |
| Dirección del evento | Evento (campo propio) |
| Servicios: paquete, duración, incluye, descripción | Catálogo de paquetes |
| Personaje | Evento (obligatorio solo si el paquete incluye personaje) |
| Costo de transporte | Calculado por ZIP o manual |
| Calendario de pagos | 50 % al reservar; "Remaining 50% balance due" 24 h antes |
| Términos 1–10 (cambio $100, espera $150/h, hora extra $200/h, 48 h, clima) | Texto fijo con versión y fecha |
| Firmas | Portal con fecha, hora, IP y correo (cláusula 10, UETA y E-SIGN) |

Decisiones del dueño: se registra el monto exacto recibido y el saldo se recalcula; el texto del saldo dice "Remaining 50% balance due"; el personaje solo se pide si el paquete lo incluye (Star, Magic, Music & Games 2.0, Waoo; no en Music & Games).

## Fases

1. **Fase 0, preparación (1 semana).** Verificar Hostinger, crear crm.waooparty.com, Stripe en modo de prueba, correo de envío, registro de SMS, cotización de ejemplo. Pasa cuando: el subdominio responde con HTTPS y Stripe está en modo de prueba.
2. **Fase 1, núcleo (3–4 semanas).** Clientes, eventos, calendario, cotizaciones, contratos con firma, facturas, Stripe y Zelle, portal y correos. Pasa cuando: 3 reservas de prueba completas sin errores.
3. **Fase 2, mensajes y automatizaciones (2 semanas).** SMS con bandeja, recordatorios, saldo, gracias y reseña. Pasa cuando: un mes de eventos reales en paralelo con HoneyBook sin fallas.
4. **Fase 3, extras.** WhatsApp, reportes, disponibilidad web desde el CRM.
5. **Migración y cierre.** Importar ~250 clientas archivadas y eventos futuros, guardar PDFs, cancelar HoneyBook.

## Costos mensuales (aproximados, por confirmar)

| Concepto | Costo aproximado |
| --- | --- |
| Hosting | $0 extra |
| Stripe | ~2.9 % + $0.30 por cobro; 3 % trasladado solo en tarjeta de crédito |
| Twilio | Número ~$1–2; ~400 SMS ~$4–8; A2P: ~$20 único y ~$2–10/mes |
| Correo | $0–15 |
| WhatsApp (opcional) | Centavos por conversación |
| **Total** | **~$10–35/mes** frente a $80 de HoneyBook |

## Riesgos

| Riesgo | Cómo lo cubrimos |
| --- | --- |
| Falla que afecte reservas | Fases con pruebas reales; HoneyBook en paralelo |
| Seguridad de datos | HTTPS, 2FA, enlaces que vencen, copias diarias; nunca guardar tarjetas |
| Validez de la firma | Registro completo (ESIGN/UETA); contrato revisado por abogado |
| Recargo del 3 % | Solo crédito y avisado; confirmar reglas de Stripe y redes de tarjeta |
| SMS bloqueados | A2P 10DLC y "STOP" |
| Correos en spam | SPF y DKIM en waooparty.com |
| Mantenimiento | Código documentado en Git, respaldos, cambios por fases |
| Límites de Hostinger | Verificar en Fase 0; si no alcanza, plan superior o VPS |

## Decisiones del dueño (2026-10-15)

- D1: primero el carril A (reservas confirmadas a mano) y después el carril B (disponibilidad automática).
- D2: la clienta firma y paga en el mismo paso; con Zelle, la reserva queda pendiente hasta verificar el pago.
- D3: Stripe cobra siempre el monto exacto; con Zelle, el admin registra y puede ajustar el monto real recibido (con nota). El saldo se recalcula.
- D4: **firma propia** (decidido el 2026-10-15; sin costo por contrato). Queda detrás de un adaptador por si algún día se quiere usar un proveedor.
- D5: el código de la web va a un repositorio privado `waoo-website`.
- D6: staff, trajes, equipos y vehículos entran desde el inicio, con asignación manual.

## Pagos y cuentas (2026-10-15)

- Medios de pago por el enlace de Stripe: tarjeta, Klarna, Zip y Afterpay (la clienta elige). Se cobra el 3 % en todos esos medios; en Zelle no. Antes de activar el 3 % en el sistema se confirman las reglas de recargo de cada medio.
- Cuentas de administrador: cesar@waooparty.com y maybe@waooparty.com.

## Pendientes del dueño

- [x] Aprobar el plan (D1–D6)
- [x] Aprobar el inicio de la Fase 0 (2026-10-15)
- [x] D7: no se aparta nada hasta recibir el pago
- [x] D8: correo de envío sales@waooparty.com
- [x] D9: contrato solo en inglés
- [x] Contrato actual
- [ ] Cotización y factura de ejemplo (sin datos de clientas)
- [x] Plan de Hostinger (Business Web Hosting)
- [x] Cuenta de Stripe
- [ ] Estado del registro A2P 10DLC en Twilio
- [ ] Correo de envío
- [x] Saldo 24 h antes
- [ ] Contrato en español (sí/no)
- [x] Asignación de staff, personajes, trajes, equipos y vehículos: sí, manual al inicio (D6)
- [ ] Exportación CSV de HoneyBook (en la migración)
