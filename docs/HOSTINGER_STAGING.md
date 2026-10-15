# Hostinger — requisitos del entorno de pruebas

Estado: propuesta, 2026-10-15. El dueño confirmó que no existe staging y pidió preparar requisitos. Este documento no autoriza crear subdominios, cambiar DNS ni desplegar.

## Preparación para revisión del dueño

Un administrador de Hostinger debe identificar una ubicación de pruebas separada, PHP/extensiones disponibles, versión y capacidad MySQL/InnoDB, acceso Composer/SSH, programación cron, límites de procesos, almacenamiento privado y disponibilidad de logs. Informar qué plan concreto y límites aplican; “Business” no basta para demostrar workers persistentes o frecuencia suficiente de cron.

Propuesta: sitio de prueba con acceso restringido, directorio distinto del `public_html` comercial y raíz web limitada a `public/` si se elige Laravel. Base y usuario exclusivos de pruebas con mínimo acceso necesario; almacenamiento de documentos fuera de la raíz pública; correo hacia destinatarios controlados; datos ficticios. Elegir nombre/ruta con el dueño. Mantener configuraciones de producción intactas y plan de retirada del staging.

Primero se revisa esta propuesta. Crear el sitio, DNS, SSL o cualquier cambio de hosting requiere autorización explícita. Las capacidades pueden documentarse mediante el panel o acceso técnico seguro; no pedir contraseñas, claves SSH, enlaces privados de calendario ni tokens por chat.

## Matriz de requisitos y evidencia

| Área | Verificación requerida | Evidencia sin secretos |
| --- | --- | --- |
| PHP | Versión mantenida compatible con versiones elegidas de Laravel/Filament, extensiones requeridas (PDO MySQL, mbstring, intl, curl, DOM/XML, fileinfo, openssl, etc.) | Versión y lista de módulos; no phpinfo público |
| Dependencias | Composer compatible, instalación reproducible y acceso a registros | Log resumido de instalación sin tokens |
| Base de datos | MySQL/InnoDB, transacciones, bloqueos por fila e índices; credenciales de prueba | Versiones y prueba ficticia commit/rollback/concurrencia |
| Jobs | Worker supervisado o cron sin solapamiento, reintentos y observabilidad | Fallo inducido y recuperación de un job ficticio, sin efectos duplicados; latencia medida |
| Webhook | HTTPS de pruebas, cuerpo original, validación de firma, persistencia y replay | Evento Stripe test aceptado una vez; firma inválida rechazada; repetición deduplicada |
| PDFs/archivos | Generación PDF compatible y almacenamiento privado, enlaces autenticados | PDF ficticio creado/descargado con autorización; acceso anónimo rechazado |
| Backup | Copia cifrada fuera del host, retención y recuperación independiente | Restauración en otra base/directorio; comparación de registros/documentos; tiempo medido |
| Correo | Proveedor seleccionado, remitente verificado, sandbox/destinatarios controlados | Envío/recepción de prueba y tratamiento de fallo; no campañas |
| Logs | Errores, trabajos fallidos y eventos correlacionados sin PII/tokens | Ubicación, permisos, retención y ejemplo redactado |

El objetivo v2 de pérdida máxima de una hora/recuperación de cuatro horas es una propuesta: medir costo y capacidad; una copia diaria no satisface una hora. Si el plan no alcanza, proponer alternativa y costo para aprobación, sin contratar servicios.

## Configuración requerida, aún no suministrada

- Acceso seguro de pruebas Hostinger y parámetros de base: a configurar por el mecanismo seguro disponible, no en Git o chat. No asumir que un secreto de proxy HTTPS puede sustituir una clave SSH o password MySQL.
- Stripe test: binding seguro para clave de API de prueba y secreto de firma de webhook de pruebas. Separa cuenta/modo y endpoint de producción. Una clave de webhook debe estar disponible al verificador local; no sirve un placeholder que solo se sustituya al salir por HTTPS.
- Maps/iCal: no son necesarios para las pruebas locales realizadas. Para validar integración real, decidir bindings, cuotas y una fuente de calendario de prueba con datos ficticios; no copiar configuraciones live al repositorio.
- Email y firma: proveedor por elegir; declarar bindings/destinos exactos solo después de esa decisión. Twilio/A2P sigue pendiente de verificación antes de activar SMS, no bloquea las pruebas sin SMS.

## Insumos de negocio para completar la auditoría

Localizar el contrato completo ya entregado a Claude y colocarlo anonimizado en un lugar accesible al equipo; añadir cotización e invoice sin datos reales. Proporcionar inventario operativo y roles mediante identificadores, capacidades y horarios, sin información personal innecesaria. Confirmar tiempos por paquete y dirección base por un canal adecuado.

La Fase 0 no se cierra solo con estos requisitos escritos: las pruebas de viabilidad deben ejecutarse después de disponer del entorno autorizado.
