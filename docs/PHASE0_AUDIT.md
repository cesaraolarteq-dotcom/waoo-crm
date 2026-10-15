# Fase 0 — auditoría y evidencia

Fecha: 2026-10-15. Autor: Codex. Estado: **en curso; no cumple todavía todos los criterios de salida**.
César autorizó iniciar Fase 0 en esta conversación y confirmó que no existe un entorno separado en Hostinger: pidió preparar sus requisitos. No autorizó desplegar ni iniciar Fase 1.

## Fuentes y alcance

- CRM: `ab47785920434bdc89bcca1b80a190feb5aeba43` de main, más revisión documental `4219226` (rama previa publicada, todavía no fusionada en el main observado). Esta entrega conserva esa revisión como antecesora; no contiene cambios de aplicación.
- Web: `cesaraolarteq-dotcom/waoo-website`, commit `5afc001cdd53fd6d4a3ce05df571055675b71b9c`. Acceso privado de lectura y clonación comprobados con autenticación existente.
- Se leyeron AGENTS/CLAUDE, PLAN, COLLABORATION, HANDOFF, PROJECT_PLAN v2, instrucciones de la web y sus manejadores PHP. No se consultó el contenido de la cuenta Hostinger ni se cotejó el checkout contra sus archivos desplegados.
- La copia web quedó intacta. No se enviaron formularios a producción, correos, SMS ni pagos. No se accedió al calendario privado.

## Inventario DISC-01

Todas las rutas web siguientes son relativas a `public_html (1)/`.

| Componente | Evidencia del código | Decisión propuesta |
| --- | --- | --- |
| Sitio comercial | HTML estático, CSS compartido `waoo-theme.css`, efectos `waoo-fx.js`, traducciones repetidas en páginas; sin manifiesto/build | Conservar diseño, contenido y URLs; integrar por etapas |
| Formulario | `contact.html:280` POST a `send-quote.php`; precios locales en `contact.html:1074` | Reutilizar UX; catálogo y cálculo definitivo pasan al backend |
| Recepción de lead | `send-quote.php:100` usa PHP mail; validación básica y honeypot; no persistencia en base ni cola | Crear lead durable antes de notificar; no confundir mail aceptado por el servidor con entrega al destinatario |
| Precio recibido | `travel_estimate` llega como campo del navegador y se inserta en correo | Es una estimación informativa, no un total confiable para cobrar; recalcular/versionar en servidor |
| Transporte | `travel-fee-lib.php`, configuración y siete excepciones ZIP | Conservar la fórmula observada como versión preliminar; no usar rutas ZIP como logística entre direcciones exactas |
| Calendario | `availability.php` y `waoo-calendar-lib.php`: demanda por cantidad diaria, fines de semana populares, iCal | No es un motor de disponibilidad; no trasladar sus estados a confirmaciones automáticas |
| Galería | `gallery-photos-list.php`, 27 imágenes WebP | Reutilizable; respuesta local validada |
| URLs y headers | `.htaccess`: rewrite, redirects, cache, headers | Requiere Apache/LiteSpeed compatible; PHP CLI no valida esas reglas |
| CRM/pagos/firma | No hay aplicación CRM, base, SDK Stripe/Twilio, portal, ledger o firma en estos archivos | Trabajo futuro; no afirmar capacidades implementadas |
| Despliegue | Instrucciones indican copia manual a Hostinger File Manager | La rama Git no prueba qué está en producción; inventariar diferencias sin sobrescribir configuraciones |

## Hallazgos que afectan el diseño

1. **Recurrencias iCal no expandidas (reproducido).** El parser procesa DTSTART/DTEND pero no RRULE. Una fixture ficticia de dos ocurrencias semanales cuenta solo la primera. Puede subcontar compromisos; no sirve como fuente única de bloqueos de reservas.
2. **Calendario sin garantía de frescura (código, sin prueba de caída real).** `waoo-calendar-lib.php:110` admite la última copia sin límite de antigüedad; eventos de varias jornadas con hora solo se cuentan en el inicio y los all-day tienen límite de expansión. Estos comportamientos necesitan tratamiento explícito antes de migrar compromisos.
3. **Demanda no equivale a capacidad.** Un evento produce `full`, dos `packed`, y fines de semana populares `filling` incluso vacíos. No se evalúan hora, roles, equipos ni viaje. El endpoint mensual devuelve mapa vacío si falta feed; no interpretar eso como mes libre.
4. **Rutas insuficientes para agenda.** Google se consulta con `TRAFFIC_UNAWARE`, ZIP de origen/destino, retorno simétrico y estimación de peaje de USD 5 si falta precio. El CRM debe distinguir estimación de costo de transporte y factibilidad de trayectos; un dato ausente no acredita viaje ni precio definitivo.
5. **Fiabilidad del lead.** No hay almacenamiento durable, deduplicación o reintento. El endpoint no valida fecha/ZIP/paquete contra un catálogo en servidor; no convertir su payload en una cotización aceptada sin validación.
6. **Límites de consultas.** El contador diario usa flock; el límite por visitante lee antes de tomar el lock de escritura. Puede perder actualizaciones concurrentes. No usarlo como límite financiero estricto sin revisión.
7. **Secrets: comprobación acotada.** En el checkout y en las revisiones que modificaron los dos archivos de configuración, `google_api_key` e `ical_url` están vacíos (2 revisiones por archivo). No aparecieron variables del proyecto en el entorno ni requisitos de credenciales configurados. Esto no es una certificación de ausencia de cualquier secreto en todo el repositorio.

No se corrigió código: estos hallazgos informan los tickets y límites de reutilización.

## Validación ejecutada

Runtime aislado: Docker 28.4.0, PHP 8.4.26, imagen oficial fijada a `php@sha256:1d2cf2b7d715b5b3aa959f0150ccbcc7b71e0181b637e443abf40a7fb56bf79a`. Descarga verificada por Docker, sin desactivar TLS/checksums. Checkout montado solo lectura; contenedor sin red externa; correo deshabilitado mediante `sendmail_path=/bin/false`.

| Comprobación | Resultado | Alcance |
| --- | --- | --- |
| Sintaxis PHP | 9/9 archivos válidos | PHP CLI 8.4; no compatibilidad con versión Hostinger desconocida |
| Probes de lógica | 16/16 expectativas cumplidas | Fórmula existente, ZIP especial, errores sin bindings, lectura inicial de iCal y reproducción de omisión RRULE |
| HTTP local | 16/16 | HTML de inicio/formulario, 27 fotos, respuestas de transporte/calendario, 404 en configuraciones/libs, GET/POST incompleto/honeypot antes de mail |
| Arranque guardado | Ejecutado y HTTP revalidado después de recrear nuestro contenedor | Proceso reiniciable; no publicación ni restauración en una nueva tarea |
| Estado Git web | Sin cambios | No hay archivos de producción modificados |

Las 16 expectativas lógicas incluyen detectar una limitación conocida: **no significan que las recurrencias funcionen**. No existe suite previa de aplicación. Las sondas temporales no reemplazan las pruebas críticas del futuro CRM. No se probó interfaz visual EN/ES/móvil, entrega de correo, Google Routes real, iCal privado, MySQL, workers Hostinger, backup, firma o Stripe.

Reproducción en este entorno: `/workspace/setup/waoo-phase0/install.sh` prepara la imagen; `start.sh` arranca y ejecuta `http-probe.php`. `probe.php` se ejecuta con la misma imagen y montajes de solo lectura. Estos auxiliares están fuera de los repositorios, sin secretos; pertenecen al entorno, no al producto. Las instrucciones reutilizables se guardan en el borrador del entorno. Un proceso vivo no se conserva al publicar/restaurar.

## Estado de salida

- DISC-01 web: auditado; comparación con despliegue pendiente.
- DISC-01 Hostinger y viabilidad: bloqueado por ausencia de staging/acceso; ver HOSTINGER_STAGING.md.
- Contrato completo, cotización e invoice anonimizados: no disponibles en los archivos de este entorno; que consten como recibidos por Claude no permite auditarlos aquí.
- BIZ-01/BIZ-02: catálogo/formula documentados; roles, inventario, tiempos y algunas reglas pendientes.
- ARCH-01: propuesta escrita en DECISIONS.md; versión final, capacidades, firma y costos no aprobados.
- Stripe test, webhook autenticado, job con reintento y restauración aislada: **no ejecutados**.
- D1–D6: respondidas; D4 exige comparación y elección posterior. Inicio Fase 0: autorizado. Cierre Fase 0 / inicio Fase 1: no autorizados.
