# ARCH-01 — arquitectura adoptada para el frente A

Fecha: 2026-10-15. Responsable técnico: Codex; revisión cruzada: Claude.
**Estado: decisión técnica final de Fase 0. Recomendada para cierre por el dueño; no autoriza Fase 1 ni despliegue.** Sustituye las propuestas de selección en DECISIONS.md. Evidencia revisada: rama `claude/phase-0-review`, commit `7b7986e`; probes/phase0/README.md y HOSTINGER_INVENTORY.md. Observaciones de código y límites: ../PHASE0_REVIEW.md.

## Contexto y decisión

Conservar la web comercial HTML/PHP. Construir un monolito modular Laravel 13 con MariaDB 11.8/InnoDB y administración Filament, portal separado por permisos dentro del mismo backend, jobs durables en base y almacenamiento privado de documentos. Hostinger Business es viable para el volumen inicial con los límites comprobados.

Laravel 13.36.0 / PHP 8.3.35 / MariaDB 11.8.9 son versiones **reportadas como ejecutadas** por Claude. Filament aún no fue probado: en el bootstrap de Fase 1 seleccionar una versión mantenida compatible, resolver con Composer y fijar lockfiles; si no es compatible, revisar esa capa sin cambiar silenciosamente el hosting o runtime de la web. Este ADR no introduce paquetes ni migraciones.

Descartados para el inicio: microservicios, un backend Node adicional sin runtime disponible en el host, un scheduler genérico como fuente de reservas y reconstruir el sitio comercial. La arquitectura reutiliza PHP y transacciones sin depender de workers permanentes.

## Host y despliegue

- Código, vendor, .env y storage fuera de public_html; únicamente front controller/assets públicos en la raíz del subdominio. Restricción de host y pruebas contra acceso desde el dominio comercial. No depender de symlink/storage:link.
- Assets compilados en CI/entorno de desarrollo, no en Hostinger (Node no disponible). Despliegue reproducible con artefactos y lockfiles después de autorización; nunca copiar secretos entre repositorios.
- PDF mediante biblioteca PHP (dompdf fue probada); validar la plantilla real, fuentes, saltos e imágenes. No depender de exec/wkhtmltopdf/Chromium en el host. Desactivar recursos remotos no controlados y no admitir HTML arbitrario en plantillas.
- Cron hPanel cada minuto ejecuta scheduler y cola acotada, sin worker persistente asumido. P1 reporta hasta aproximadamente un minuto por intento. Fijar timeout de jobs, retry_after y bloqueo de scheduler coherentes; `--max-time=50` no interrumpe por sí solo un trabajo que exceda ese tiempo. Alertar jobs atascados y lock obsoleto; probar recuperación tras caída.
- DB_HOST=localhost según grants comprobados. Usar MariaDB/InnoDB real en las pruebas de concurrencia, no SQLite como sustituto de bloqueos.

## Datos, permisos y dinero

Contrato lógico en ../DATA_MODEL.md. César y Maybeli son **administradores equivalentes**, con 2FA; ambos ven costos, remuneraciones y reportes. Cliente/staff reciben solo datos autorizados por objeto; ocultar un bloque de UI no sustituye autorización/serialización del backend.

Importes en centavos USD, cotizaciones/precios/tarifas versionados, historial de ajustes no destructivo. El ledger es la fuente de pagos, créditos, reembolsos y sobrepagos; estados de venta, capacidad, firma, dinero y operación son independientes. Stripe exige verificación de firma, modo/cuenta, importe/moneda y vínculo con intención de pago. Persistir la recepción antes de responder; deduplicar tanto eventos como transacciones financieras y reintentar procesamiento con conciliación. P3 no implementa ese ledger.

D7: cotización, firma o aprobación no aparta recursos. Revalidar y asignar capacidad en transacción cuando se verifica el depósito, con excepciones administrativas requeridas y conflictos resueltos. Un pago recibido sin capacidad sigue siendo dinero recibido y genera incidencia. D2: confirmar completamente cuando también esté la firma. Evitar liberar una asignación pagada por una expiración de sesión.

## Agenda, recursos y logística

CRM es la fuente de compromisos; Google Calendar es espejo, iCal actual solo una fuente importable con límites. Una persona con varias habilidades no se multiplica. Traje/personaje son entidades distintas. Speakers (4 confirmados), vehículos, conductores y carga se asignan por intervalo e itinerario. En A la selección es administrativa; B añade búsqueda automática sin cambiar la integridad.

Bloqueo transaccional ordenado de filas estables de recursos y revalidación de vecinos, preparación y trayectos. Rutas externas fuera de transacciones largas; validar dentro que la estimación corresponde a las versiones actuales. P4 prueba bloqueo de una fila, no el algoritmo completo. Inventario, recetas y tiempos incompletos impiden confirmar servicios afectados, no crear borradores.

Tráfico para salida prevista, margen estándar de 45 minutos sobre viaje (mayor cuando lo exija la operación), solicitud hasta 15 minutos antes con aprobación, y menos de siete días con aprobación. Estos permisos no saltan conflictos físicos. APPROVAL_FLOW.md define la revisión y el no-hold; hora de corte de siete días/grilla siguen precisiones previas a activación automática. Maps actual TRAFFIC_UNAWARE/ZIP no satisface la logística futura.

## Firma propia (D4 definitiva)

Adoptar firma propia detrás de SignatureProvider; no contratar proveedor por defecto. Contrato solo inglés (D9), portal/email EN/ES. Consentimiento e intención explícitos, identidad mediante acceso de cliente, documento/revisión exactos presentados, hash SHA-256 de bytes conservados, evidencia de aceptación, timestamps UTC/zona local, IP/user agent cuando proceda, PDF final y evidencia exportables. Si se genera un PDF final distinto del presentado, conservar ambos artefactos y el vínculo de aceptación; no atribuir al cliente la firma de bytes distintos sin evidencia.

Versiones de plantilla inmutables una vez usadas; cambios generan versión nueva y, para acuerdos existentes, adenda aceptada o contrato nuevo. Acceso autenticado y autorizado por evento, además de enlaces temporales/revocables. Enlace firmado P2 no demuestra firma del cliente ni autorización por objeto. Revisión contractual y pruebas de evidencia antes de lanzamiento; no se afirma suficiencia legal por elegir este diseño.

## Costos, reportes y cambios

COST-A: tarifas efectivas por rol/persona, trabajo/horas/preparación y transporte del staff, costos estimados y reales separados. No confundir costo logístico interno con costo de transporte cobrado. No asumir remuneración para pelucas/preparación todavía pendiente. Margen de contribución = ingreso del servicio sin impuestos menos todos los costos directos; no llamarlo utilidad neta.

REPORT-A: sales@waooparty.com, también acceso de ambos admins. Separar caja por fecha de pago, cuentas por cobrar a fecha de corte y costo de eventos por fecha del servicio. “Caja menos staff” es indicador parcial, no neto. Períodos y hora exacta de envío por definir sin duplicación; calendario America/Chicago. Remitente sales@ (D8); Resend como opción inicial recomendada, con dominio/entrega aún por probar, 100/día según comparación de Claude. No confundir dirección de reportes con autorización para enviar ahora.

CHANGE-A: una reserva ya pagada conserva su asignación original mientras se valida propuesta de reprogramación/adenda. Una vez aceptada, efectuar intercambio transaccional; si falla, preservar la original. La duración de una eventual protección de la propuesta debe acordarse, no inferirse como hold pre-pago ni liberar capacidad antes de tiempo. Fee/dispensa con motivo, revisión contractual, contador de cambios, nuevas versiones de agenda/cotización y replanificación idempotente de avisos; cancelar no borra firma/pagos ni ejecuta automáticamente cualquier devolución.

## Backups y operación

P5 demuestra dump cifrado, salida del host y restauración de fixtures en otra DB; aceptar esa viabilidad. La copia en el Mac y restauración en segundos de 500 filas no acreditan RPO/RTO de producción, recuperación de PDFs ni copias automáticas. Antes del piloto: destino externo automatizado, claves independientes/recuperables, cifrado autenticado o autenticidad comprobable separada, manifiesto con hashes fuertes, DB + documentos + configuración necesaria, retención, alertas y simulacro. AES-CBC/PBKDF2 cifra pero no autentica; MD5 de fixtures acredita comparación del ensayo, no integridad adversarial.

Observar jobs, webhooks pendientes, conciliación, rutas obsoletas y documentos. No copiar literalmente el kit de probes al CRM ni borrar evidencias por cerrar la fase. Retirar endpoints/cron/artefactos de prueba mediante plan autorizado, preservando pruebas y configuraciones que deban migrarse. No se ejecutó limpieza en esta revisión.

## Costo y condiciones de aceptación

Firma propia: sin tarifa de proveedor por contrato, con costo de construcción/mantenimiento. Email inicial puede caber en plan gratuito pero debe gestionar picos. Hosting existente no demuestra costo futuro cero. Maps, almacenamiento externo, monitoreo, mantenimiento, procesamiento de pagos y consumos deben presupuestarse antes del piloto; escenarios de referencia 40/60/80 eventos mensuales, sin declarar ahorros validados.

**Conclusión:** arquitectura viable para iniciar construcción tras aceptación del dueño de EXIT-0 y autorización de Fase 1. Inventario/catálogo incompletos no bloquean el esqueleto y permisos, pero sí los tickets/servicios que los necesitan. Las correcciones de UX/plan en PHASE0_REVIEW son condiciones de implementación, no funciones aprobadas o pruebas de producto ya superadas.
