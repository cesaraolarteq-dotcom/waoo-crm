# Revisión Codex — viabilidad, UX y plan de Fase 1

2026-10-15. Rama de Claude revisada e integrada: `7b7986e` (`claude/phase-0-review`), incluyendo el commit posterior a main `ef4cbd9`. Dictamen: **acepto P1–P5 como evidencia de viabilidad del host y firmo técnicamente ARCH-01**. Recomiendo presentar EXIT-0 al dueño; Fase 1 no comienza con este dictamen. No reejecuté probes remotos ni usé credenciales de Claude.

## Revisión del kit y resultados

| Prueba | Resultado reportado por Claude y aceptado | Límite comprobado al revisar el código |
| --- | --- | --- |
| P1 cron/reintento | Fallo intento 1, éxito intento 2; ticks cron observados, cola vacía | ProbeFlakyJob prueba retry, no idempotencia de efectos. `routes/console.php:13`: timeout/retry_after y recuperación de lock deben fijarse para producción; max-time no impone timeout individual. |
| P2 PDF privado | Dompdf sirve PDF por URL temporal firmada; unsigned/tampered/direct paths fallan | `routes/web.php:11–14` solo middleware signed, sin usuario/permiso por evento. Prueba control por posesión de enlace, **no e-sign del cliente ni autorización de objetos**. README atribuye autorización de permisos a P2: esa frase debe corregirse. |
| P3 Stripe | Payload local inválido/repetido y evento real test PaymentIntent 22750; una fila, entrega 200 | `routes/web.php:25` UNIQUE provider/event_id: no deduplica dos event_ids de la misma transacción. `insertOrIgnore` puede ocultar errores distintos del duplicado; en CRM distinguir duplicado real de fallo y no ACK sin persistencia válida. Falta ledger, binding a factura, validación modo/cuenta/importe, reordenamiento y conciliación; aceptable para probe. |
| P4 MariaDB | Dos procesos bloquean fila, contador final 2 | Prueba SELECT FOR UPDATE de fila existente, no búsqueda de solapamientos, inserciones vacías, multi-recurso, viajes, cambio de staff ni deadlocks. Usar filas estables y pruebas de aplicación en Fase 1. |
| P5 backup/restore | Dump cifrado off-host en Mac, restore DB separada, 500 fixtures/counts/MD5 iguales; 4 s/1 s | Aceptar evidencia narrada; script/comandos completos y manifiesto no incluidos en kit. No independencia de verificación ni RPO/RTO de producción. CBC/PBKDF2 no autentica; MD5 no prueba autenticidad. Backups de documentos, automatización, clave recuperable y destino durable pendientes antes del piloto. |

Comprobación propia: los cinco archivos PHP del kit pasan sintaxis en contenedor aislado PHP 8.4.26. Esto no reproduce Laravel 13/PHP 8.3.35 ni los resultados Hostinger. El kit no incluye composer.lock, configuración completa, cron exportado ni script de backup: pedir manifiesto de versiones/comandos sin secretos como mejora de reproducibilidad; no declarar falsas las pruebas por esa ausencia. Los resultados detallados prevalecen sobre la sección antigua “no verificado” de HOSTINGER_INVENTORY; actualizarla conservando su fecha original.

No se modifica ni despliega el kit en esta revisión. No borrar probes/endpoint test al cerrar sin coordinar retirada y preservar evidencia. Un PDF por enlace firmado no es un contrato electrónicamente firmado: DOC-01 sigue siendo trabajo pendiente con D4 propia.

## UX-SPEC y prototipo frente al modelo lógico

Referencia: DATA_MODEL.md (diseño lógico; todavía sin migraciones). No se hizo QA visual en navegador ni se afirma contraste AA verificado.

| Prioridad / referencia | Hallazgo | Ajuste exigido antes de implementar esa función |
| --- | --- | --- |
| Alta — UX-SPEC tabla Cotización; prototype.html quote() | Extra personaje universal +150 y hasta 3 sin catálogo de variantes; Star aún pregunta si tiene ese precio | Usar variante/PriceVersion explícita; no inventar precio o compatibilidad. Ocultar/bloquear configuración sin precio aprobado. |
| Alta — prototype.html quote(), `t || 0` | ZIP sin precio deja total y depósito calculados como si transporte fuera cero mientras dice calculando | Estado de cotización incompleta; no total definitivo ni habilitar cobro hasta resolver. |
| Alta — UX principio 4/portal | “Fecha asegurada al pagar” incompleto ante carrera de pagos/aprobación última hora | Mostrar verificación en curso y confirmación solo tras revalidación; incidencia si capacidad se agotó, manteniendo dinero recibido. D7 prohíbe holds. |
| Alta — Staff y trajes | “Disponibilidad por día” y roles simples no cubren unidades/intervalos, habilidades, conducción/participación ni equipos | Ficha por persona/copia y ocupación por intervalo; cuatro speakers, mantenimiento, vehículos/carga, trayectos y estados de aceptación. Datos insuficientes = revisión. |
| Alta — Prototype reportes y REPORT-A | “Te queda”/“neto = ingresos − staff” combina caja de pago y costo por fecha de evento y omite otros costos | Etiquetar indicador parcial y separar periodos/cohortes; margen de contribución usa todos los costos directos. No presentarlo como utilidad neta. |
| Alta — Portal/costos | Marco “solo tú y Maybeli” es visual; prototype contiene todos los datos ficticios | Políticas y respuesta backend sin costos para cliente/staff; ambos admins equivalentes. Casos de acceso cruzado obligatorios. |
| Media — UX estados | Badges pago/contrato/crew son buena base, faltan reserva/capacidad/aprobación explícitas | Estados separados de DATA_MODEL; aprobaciones de <7 días y hasta 15 minutos antes no reservan. Estándar 45 min más viaje, no copiar bloques ilustrativos de 30 como regla. |
| Media — Cotización envío SMS | CTA promete SMS en A aunque requiere Twilio/A2P y está diferido | Email primero; SMS deshabilitado hasta integración/consentimiento/registro. |
| Media — Portal 3% y firma | Ejemplo muestra recargo/afirmación jurídica categóricos | Recargo condicionado a reglas/método; copy de firma revisado con evidencia/proceso; D9 solo texto contractual inglés, resto EN/ES. |

TPL-01 encaja con TemplateVersion/ContractInstance/Artifact, nunca edición de contratos ya firmados. CHANGE-A requiere propuesta de cambio, aceptación de adenda, actualización de capacidad y precios, conservación del original si falla y outbox; no basta arrastrar una tarjeta del calendario. COST-A necesita tarifas efectivas y horas/transportes reales, sin deducir sueldo por habilidad. REPORT-A se envía a sales@, accesible a ambos admins; definir límites de semana/mes y envío idempotente antes de activarlo.

## PHASE-1-PLAN: aceptable con condiciones de implementación

El orden es correcto: bootstrap → datos → auth → shell → catálogo/recursos/eventos → asignación → calendario → aceptación. Precisión por paso:

- 1: Laravel 13 validado como plataforma; Filament/versión concreta aún debe resolverse con Composer, lockfile y pruebas de runtime. Assets fuera del host. No copiar probes al producto ni asumir storage:link. Fijar DB MariaDB/InnoDB en CI para pruebas que dependen de locks.
- 2: DATA_MODEL define dominios e invariantes, no un esquema ya existente. Crear solo el núcleo necesario, conservando interfaces para COST-A/REPORT-A/CHANGE-A/TPL-01. No implementar pagos, reportes o portal anticipadamente.
- 3: Codex implementa auth/policies/2FA y Claude sus pantallas como subticket distinto; ambos owner-admin tienen permisos iguales. Crear cuentas reales solo en entorno autorizado por mecanismo seguro, no seeds con contraseñas compartidas.
- 5–7: dividir explícitamente subtickets de datos/backend (Codex, Claude revisa) y pantallas (Claude, Codex revisa), con archivos asignados y contratos acordados; “Codex + Claude” sin dueño de archivo incumple la regla de un implementador.
- 8: distinguir selección tentativa sin pago de reserva firme respaldada por depósito. D7 permite solapamiento de propuestas, no de compromisos confirmados. En Fase 1 sin pagos integrados usar fixtures ficticias de compromisos verificados; para operación real requerir evidencia de compromiso externo/manual, nunca inventar pago para probar bloqueo. Viaje/preparación no puede faltar en confirmación: ROUTE-01 es dependencia para automatizar; mientras tanto, revisión logística documentada sin falsas garantías.
- 9: calendario refleja estados reales, tramos y buffers; no editar directamente fechas saltando protocolo. Rumi #2 solo es alternativa si intérprete, equipo, transporte y preparación caben.
- 10: ampliar aceptación con acceso no autorizado, igualdad de admins, selección tentativa vs firme, conductor/animador simultáneo, cuatro speakers, mantenimiento, DST y auditabilidad. Ensayos multi-recurso en MariaDB, no solo repetir P4. Capturar evidencia antes de declarar la fase terminada.

Inputs de catálogo/inventario pueden llegar durante el bootstrap. No inventar datos: los servicios incompletos no pasan a uso confirmado. El plan no permite enviar reportes, mensajes ni cobros en Fase 1. Cierre de Fase 0 y comienzo de Fase 1 siguen requiriendo la decisión del dueño.

## Resultado y seguimiento

**Sin bloqueo técnico del hosting para escoger la arquitectura.** Las brechas anteriores son criterios de implementación/piloto, no razones para repetir pruebas de infraestructura ya exitosas. Firmo ARCH-01 en arch/ARCH-01-ADR.md, con firma propia D4. Claude debe incorporar correcciones a UX/plan y completar costos como PLAN-01 sin contabilizar subtotal de email/firma como costo total. Dueño: revisar EXIT-0 y decidir cierre; hasta entonces solo documentación/revisión, sin Fase 1.
