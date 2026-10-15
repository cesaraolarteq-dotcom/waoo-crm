# Backlog de Fase 0 y dependencias del frente A

2026-10-15. Fase 0 autorizada. Fase 1 y despliegues no autorizados. Cada ticket tendrá un implementador y un revisor; no editar en paralelo los mismos archivos/migraciones. Las asignaciones siguientes son propuestas para coordinación.

## Fase 0

| Ticket | Implementa / revisa | Alcance y salida | Estado |
| --- | --- | --- | --- |
| DISC-01-WEB | Codex / Claude | Auditar checkout web, mapa de reutilización, rutas/formulario/calendario, evidencia local | Entregado en PHASE0_AUDIT; sin cotejo live |
| DISC-01-HOST | Codex + administrador autorizado / Claude | Inventario de capacidades reales, evidencia staging aislado | Bloqueado: no existe staging; requisitos preparados |
| BIZ-01 | Claude con dueño / Codex | Completar variantes piloto, roles, tiempos, inventario y dotaciones | Base observada documentada; recetas/inventario pendientes |
| BIZ-02 | Codex con dueño / Claude | Contrastar transporte activo; reglas de impuestos/cargos/redondeo/excepciones | Fórmula checkout probada; live y decisiones pendientes |
| DOC-SOURCE | Claude / Codex | Contrato completo y ejemplos anonimizados, versión EN/ES | Documentos no accesibles en checkout |
| ARCH-01 | Codex / Claude | ADR validado por evidencia de hosting; selección firma y agenda, costos | Propuesta escrita; faltan viabilidad, fuentes y D4 final |
| STAGE-PROBE | Codex / Claude | Webhook Stripe test firmado, job con fallo/reintento, PDF privado, backup restaurado en destino separado | No ejecutado; requiere staging autorizado/bindings |
| PLAN-SYNC | Claude / Codex | Unificar precedencia documental, actualizar pendientes resueltos por D6 y fases A/B | Solicitado; conservar D1–D6 |
| PHASE0-EXIT | Dueño / ambos | Revisar evidencia, resolver bloqueos y aceptar cierre; autorizar siguiente incremento por separado | No listo |

No-alcance de estos tickets: rehacer web, desplegar CRM, procesar pagos reales, enviar mensajes a clientes o cancelar HoneyBook. Revertir entrega documental mediante commit normal si se rechaza; no reescribir commits compartidos. Las pruebas futuras en staging tendrán plan de limpieza/rollback separado sin afectar producción.

## Dependencias A antes de implementar

1. DATA-01 y AUTH-01: esquema, fixtures ficticias, permisos/2FA y auditoría tras ADR aprobado.
2. OPS-01: catálogo, recursos, eventos y asignación manual. Agregar **RESERVE-A**: protección transaccional de recursos elegidos, ventanas de viaje/preparación y ciclo de vencimiento/revalidación. Depende de OPS-01 y logística ROUTE-01 donde aplique. Dos administradores no confirman el mismo recurso; datos logísticos faltantes bloquean confirmación hasta revisión.
3. QUOTE-01 + ROUTE-01: cotizaciones versionadas y transporte fiable. LEAD-01 persiste lead antes de notificaciones, con recuperación de errores.
4. JOBS-01 + **MAIL-A**: outbox y correo transaccional mínimo antes del portal/firma/pagos. Incluye identidad del remitente, errores, deduplicación y destinatarios de prueba.
5. **PAY-01-A** depende de QUOTE-01 + RESERVE-A + JOBS-01, no de búsqueda automática AVAIL-01. Firma verificada, depósito exacto, sesión obsoleta, Zelle concurrente, webhook repetido/tardío y pago sin capacidad son pruebas obligatorias. PAY-02 registra abonos, ajustes auditables y sobrepagos.
6. DOC-01 depende de contrato completo, proveedor elegido y DATA-01; PORTAL-01 de AUTH-01, PAY-02, DOC-01 y MAIL-A. Pruebas de acceso cruzado y recuperación de sesión interrumpida.
7. IMPORT-01 importa muestra y compromisos futuros, conciliando documentos/saldos/capacidad. PILOT-01 exige esa conciliación, flujo completo, jobs/correo, backup restaurable y runbook. Un único sistema emisor por evento. Recordatorios esenciales en Fase 5 consultan el estado vigente antes de enviar.
8. B incorpora AVAIL-01/HOLD-01 con búsqueda de combinaciones tras piloto A; reutiliza la integridad de RESERVE-A. Activación por servicio tras pruebas de concurrencia y logística y autorización correspondiente.

Estos tickets corrigen la dependencia circular de adelantar PAY-01 (fase 4) mientras HOLD-01/AVAIL-01 (fase 3) se posponen. No eliminan la necesidad de proteger capacidad. Antes de código: rutas/estados/errores/IDs y permisos acordados en contrato API; archivos y migraciones asignados a un solo implementador, prueba de aceptación y rollback por ticket.
