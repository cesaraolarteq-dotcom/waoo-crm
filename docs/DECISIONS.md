# Decisiones técnicas — propuestas de Fase 0

2026-10-15. Estado: **propuestas para revisión; arquitectura final pendiente de evidencia de Hostinger**. D1–D6 están aprobadas en HANDOFF; la elección de stack/proveedor no lo está. Fase 0 está autorizada; Fase 1 no.

## ADR-001: conservar web y separar CRM modular

La inspección confirma HTML/PHP sin framework. Recomiendo conservar esa web y crear un monolito modular Laravel + MySQL/InnoDB con Filament para administración, sujeto a capacidades del hosting. Portal y endpoints tendrán autorización por objeto en servidor; Filament no sustituye el portal. Catálogo, cotizaciones, pagos, contratos, recursos y mensajes comparten estados persistidos y transacciones.

Alternativas: ampliar PHP sin framework ahorra arranque pero obliga a construir auth, migraciones, jobs y controles repetidos; Node/TypeScript añade otro runtime sin una aplicación previa que reutilizar. No se descartan si la viabilidad/costo lo justifica. La elección PHP encaja con lo observado, pero no se instala Laravel ni se eligen versiones finales hasta comprobar compatibilidad mantenida de PHP/Laravel/Filament/MySQL en el destino. PHP 8.4.26 local es un runtime de auditoría, no una versión exigida al hosting.

## ADR-002: agenda y reservas manuales seguras

CRM como fuente maestra; Google Calendar como espejo con IDs, reintentos y alerta por retraso. El iCal existente no representa recursos ni expande recurrencias; importar compromisos con revisión, no convertir sus niveles diarios en disponibilidad.

A selecciona recursos manualmente, comprueba viaje/preparación y reserva capacidad de forma transaccional antes de habilitar el pago. Diseño candidato: bloqueo de filas de los recursos en orden estable para serializar operaciones concurrentes, seguido de consulta/revalidación de intervalos y vecinos; todas las escrituras de agenda usan ese protocolo. Debe probarse con MySQL real, incluyendo eventos que cruzan medianoche, cambios de zona horaria, reasignaciones y dos administradores. No basta confiar en una validación del navegador. Duración/liberación de asignaciones pendientes requiere decisión operativa.

B añade búsqueda automática de combinaciones, no otro mecanismo de integridad. Un conflicto físico no resuelto bloquea confirmación. Los errores de Maps o de inventario pasan a revisión, sin capacidad inventada.

## ADR-003: dinero y efectos externos

Cotizaciones versionadas y movimientos en centavos. Pagos Stripe: intención vinculada al saldo/revisión, webhook firmado sobre cuerpo original, persistencia, deduplicación por evento y transacción; confirmación según estado confiable. Zelle: verificación manual, referencia, autor y ajustes auditables. No aplicar tolerancia automática ni convertir diferencia contable en dinero recibido. Si falta depósito, completar o registrar excepción explícita autorizada.

Jobs/outbox durables desde la entrega de firma/pago. Un job consulta el estado actual antes de mandar un recordatorio; reconciliación recupera efectos externos si un proceso cae. La experiencia “misma sesión” permite reanudar firma y pago sin duplicarlos. Un pago tardío sin capacidad entra a incidencias; se conserva el movimiento. Definir resolución/reembolso aprobado, no ejecución automática inventada.

## ADR-004: documentos, firma y acceso

Comparar proveedores antes de decidir (SIGNATURE_COMPARISON.md). Contrato y evidencia inmutables, documento exacto con hash, copia accesible al cliente, adendas tras cambios. Enlaces expiran, se revocan y otorgan acceso limitado al evento; no dar acceso a todos los eventos de un contacto por un enlace aislado. Administradores con 2FA; logs sin tokens/PII innecesaria. Almacenamiento privado y restauración comprobada.

## ADR-005: hosting, jobs y recuperación

Hostinger se evalúa con HOSTINGER_STAGING.md. Preferir worker supervisado si disponible; alternativa cron con lock, tiempos/reintentos medidos y monitoreo. Si no satisface requisitos, presentar hosting alternativo y costos para aprobación. No mover producción ni comprar servicios en esta fase.

Datos requeridos para costos: plan/límites efectivos; tamaño documentos y backups; consultas Maps; correo/SMS; firma API; necesidad de worker; mantenimiento. Los objetivos propuestos de recuperación de v2 se contrastan con esas capacidades. No prometer que hosting extra cuesta cero.

## Fuente documental

Propuesta: decisiones más recientes del dueño → PROJECT_PLAN v2 con ajustes A/B aprobados → resumen PLAN. El encabezado de PLAN todavía menciona precedencia del documento vivo; Claude debe reconciliarlo para que Git sea una referencia verificable. Esta entrega no reescribe políticas ni instrucciones previas silenciosamente.
