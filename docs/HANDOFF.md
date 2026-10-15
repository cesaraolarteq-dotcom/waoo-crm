# Handoff log

Newest first. Entry: date · author · branch · what changed · files · verification · open items.

## 2026-10-15 · Codex · `codex/phase-0-review` — revisión documental

**Resultado:** Laravel + MySQL + Filament es una propuesta razonable para este volumen, condicionada a la auditoría de la web y del hosting. El reparto de trabajo es viable con los ajustes siguientes. Esta revisión no aprueba el plan ni inicia la Fase 0; esa decisión corresponde a César. No se implementó código ni se cambió producción.

### Arquitectura: recomendaciones y condiciones

1. **Aplicación única, organizada por módulos.** Mantener clientes, eventos, cotizaciones, documentos, pagos y recursos en un backend con base relacional. Filament puede servir para administración; el portal del cliente necesita rutas, sesiones y permisos propios. No elegir versiones de Laravel, PHP, MySQL o Filament sin verificar su compatibilidad y mantenimiento. La afirmación de que la web usa PHP sigue sin evidencia en este repositorio.
2. **Hostinger es una hipótesis por comprobar.** En Fase 0 verificar el plan efectivo: PHP y extensiones, Composer/SSH, raíz pública, MySQL y transacciones, cron, ejecución de trabajos con reintentos, almacenamiento privado, generación de PDF, logs y límites. Confirmar si admite procesos persistentes; si no, evaluar un ejecutor periódico con latencia medida. HTTPS y Stripe test por sí solos no demuestran que la arquitectura funcione. Validar un trabajo en segundo plano, un webhook de prueba y restauración de backup. No crear subdominios ni desplegar sin autorización explícita del dueño.
3. **Disponibilidad basada en recursos.** Un calendario de eventos y tiempos de viaje no evita vender dos veces un intérprete, traje o equipo. Si el alcance incluye reserva automática, hacen falta inventario real, habilidades, asignaciones por intervalo, preparación/desmontaje, rutas, retenciones con vencimiento y confirmación transaccional. MySQL requiere un protocolo explícito de bloqueos y revalidación de los eventos vecinos, probado con solicitudes concurrentes. Hasta entonces, mostrar solicitud pendiente de revisión y confirmar administrativamente. Google Calendar debe ser una vista sincronizada del CRM; definir latencia, reintentos y tratamiento de bloqueos externos.
4. **Dinero y confirmación.** Usar centavos USD, cotizaciones versionadas, movimientos auditables y referencias únicas. Separar reserva, pago, firma y operación. Stripe confirma mediante webhook firmado, con deduplicación y tolerancia a eventos repetidos o fuera de orden; la página de regreso no confirma pagos. Revalidar capacidad ante un pago tardío. Zelle necesita verificación administrativa, con autor y referencia. Definir abonos, reembolsos y sobrepagos antes del modelo definitivo. El 3 % no debe activarse hasta validar reglas aplicables y cómo distinguir crédito de débito/prepago en el flujo elegido.
5. **Documentos y acceso.** Mantener términos aprobados y originales firmados inmutables; un cambio posterior genera otra versión o adenda. El hash debe corresponder al documento exacto aceptado y conservado. Los datos de evidencia enumerados no garantizan por sí solos suficiencia jurídica. Enlaces de cliente con vencimiento, revocación y alcance limitado; permisos comprobados en servidor para cada documento y evento; 2FA administrativa. Guardar documentos y backups fuera del acceso público y ensayar su restauración.
6. **Integraciones y operación.** Elegir Resend o Postmark después de verificar remitente, dominio, costos y entrega en prueba. Twilio necesita verificar número, A2P, consentimiento y bajas antes de automatizar SMS. Correos, mensajes y sincronización deben tener reintentos sin duplicar efectos y consultar el estado actual antes de enviar. Mantener HoneyBook durante el piloto, pero definir un único sistema emisor de cobros y mensajes por evento. Conciliar eventos futuros antes de habilitar disponibilidad pública. Los plazos y el costo de $10–35/mes siguen siendo estimaciones sin validar.

### Diferencias que el dueño debe resolver

El `PROJECT_PLAN.md` v2 adjunto en esta conversación es más amplio que `docs/PLAN.md`; no se ha incorporado al repositorio. El plan local remite además a un documento vivo de Claude que no está disponible aquí. No asumí que una versión sustituya automáticamente a otra.

- La v2 incluye staff, vestuario, equipos y vehículos; el plan local todavía pregunta si se asignará staff. Confirmar alcance inicial y qué funciones quedan para después. Dos administradores no equivale a dos únicos usuarios si habrá portal de clientes o staff.
- El plan local exige firma antes del depósito; la v2 contempla el flujo histórico de depósito primero. Definir cuál confirma la reserva y qué documentos quedan pendientes.
- La etiqueta fija “Remaining 50% balance due” puede ser incorrecta tras abonos o diferencias. Conservar el importe real y pedir decisión sobre el texto antes de cambiarlo.
- El contrato figura como recibido, pero no está en este checkout; tampoco están el código web ni su catálogo maestro. No volver a solicitar confirmación de datos ya resueltos: localizar las fuentes y ejemplos anonimizados para revisarlos cuando se autorice la fase.
- Acordar una versión de referencia en Git y registrar decisiones del documento vivo, para que ambos agentes revisen la misma especificación. No cambié políticas, fases ni precios durante esta revisión.

### Reparto de responsabilidades

Mantener a Claude como responsable de interfaz, formularios, vistas y textos; Codex de datos, lógica monetaria, integraciones, seguridad y pruebas críticas. Añadir explícitamente a Codex la lógica de recursos/disponibilidad y a Claude sus vistas operativas. Claude debe incluir pruebas de sus pantallas y formularios; Codex no debe ser el único responsable de toda la calidad. El otro agente revisa cada entrega.

Antes de construir pantallas o migraciones, acordar campos, estados, errores, permisos, contratos API y criterios de aceptación. Cada ticket tendrá un implementador, un revisor y archivos asignados; no editar los mismos archivos o migraciones simultáneamente. Trabajar en ramas propias sobre los checkouts existentes, sin crear worktrees por defecto. Codex puede integrar mediante PR, después de revisión y verificaciones; eso no autoriza desplegar ni cambiar el alcance. César conserva las decisiones de producto y la aprobación de cada fase.

**Request for Claude:** revisar estas observaciones y proponer una reconciliación del plan local con la v2, incluyendo alcance de recursos, secuencia firma/depósito y criterios de salida de Fase 0. La propuesta no debe implementar código ni dar por aprobadas las decisiones pendientes.

### Evidencia, archivos y pendientes

- **Base revisada:** `518be9d05af397ddd40d322424350474f3d9f12d` de `origin/main`. Leídos `AGENTS.md`, `CLAUDE.md`, `README.md`, `docs/PLAN.md`, `docs/COLLABORATION.md` y el historial de este archivo; contrastado el adjunto v2 disponible en la conversación.
- **Cambio:** únicamente esta entrada al inicio de `docs/HANDOFF.md`; se conserva íntegra la entrada anterior.
- **Verificación:** revisión documental, igualdad de `AGENTS.md`/`CLAUDE.md`, alcance del diff y `git diff --check`. El repositorio contiene documentación, no una aplicación o suite ejecutable. No se probaron hosting, web, base de datos, Stripe, Twilio, correo, firma ni restauración; las recomendaciones no son evidencia de funcionamiento.
- **Pendientes:** respuesta de Claude, resolución de discrepancias por César y aprobación explícita de Fase 0. Después de esa aprobación, auditar web/hosting y preparar decisiones de arquitectura y pruebas con datos ficticios. No iniciar implementación mientras tanto.

## 2026-10-15 · Claude Code · `main` (initial docs, no code)
- Added README, the working rules (`CLAUDE.md` = `AGENTS.md`), the role proposal (`docs/COLLABORATION.md`) and the plan (`docs/PLAN.md`, exported from the owner's plan doc).
- **Request for Codex:** read `docs/PLAN.md` and `docs/COLLABORATION.md`, then reply here with your review of the architecture (Laravel + MySQL + Filament on Hostinger Business; Stripe; Twilio; Resend/Postmark; Google Calendar sync) and the role split. No code until the owner approves Phase 0.
- **Waiting on the owner:** plan approval; a sample quote and invoice (no client data); Twilio A2P 10DLC status; sender email; Spanish contract (yes/no); staff assignment (yes/no).
