# Reserva físicamente realizable — contrato de integración para Claude

2026-10-29 · Codex · PENDING · César: «si el cliente reserva y físicamente no podemos eso será un problema». Diseño de integración y matriz de aceptación; NO implementación completa ni autorización de despliegue. Respetar decisiones más recientes que César entregue a Claude y registrarlas con versión.

## Decisiones existentes y límite de esta entrega

Según HANDOFF de Claude HEAD1fcdadc:4 fiestas simultáneas,10 por día de Houston,24 horas; personal insuficiente permite solicitar aprobación del dueño;1–4 personajes automáticos solo si comprobados,5+ requieren aprobación; personaje desconocido/Other no admite pago antes de confirmar qué desea y su disponibilidad. Aviso web14 días. Staff/habilidades/inventario vienen del CRM. No solicitar de nuevo cantidades al dueño.

La función pública consulta; no aparta ni confirma. Un resultado favorable no autoriza por sí solo una transacción de pago. El avance a cobro requiere la comprobación vigente y una retención válida de TODOS los recursos necesarios. Apartar solo trajes o contar10 fiestas no basta para garantizar la fiesta.

## Estados y permisos que debe imponer el servidor

| Estado | Puede mostrarse | Puede iniciar cobro nuevo | Puede confirmar capacidad |
|---|---|---|---|
| Consulta factible de snapshot | Horario estimado | No, falta admisión/retención | No |
| Incompleto/desconocido | Solicitud/revisión, sin garantía | No | No |
| Falta personal o excepción comercial aprobable | Solicitud al dueño | No hasta resolución registrada | No hasta recursos asegurados |
| Inviable físicamente | Alternativa comprobada o no disponible | No | No |
| Retención completa, vigente, versiones válidas | Apartado pendiente de pago | Sí por intento idempotente autorizado | Solo tras condiciones de reserva aplicables |
| Pago verificado + retención válida | Avance normal | No duplicar cobro | Sí, promoción atómica |
| Pago tardío/sin capacidad válida | Pago recibido pendiente de resolución | No otro cobro | No fabricar reserva ni ocultar dinero |

Mapear estos estados a los existentes; no crear un segundo ciclo de pagos. La aprobación del dueño no crea mágicamente un empleado o traje: registrar la solución real/compromiso externo verificable y recalcular. Si acepta una reserva manual pendiente, distinguirla claramente de confirmación automática al cliente. El servidor aplica la regla aunque el cliente manipule JSON, enlace o botón.

## Una prueba conjunta de factibilidad

- Receta versionada completa: duración, montaje, desmontaje, personas por rol, compatibilidad traje/intérprete, extras, conductores, vehículos, asientos y carga cuando aplique. No interpretar campos ausentes como0. Cambios de paquete/personajes/cantidad/horario/dirección invalidan la prueba.
- Persona/unidad física identificada internamente. Una persona puede tener varias habilidades pero no cubrir dos puestos simultáneos. Cada rol continuo conserva una persona durante su intervalo; cambios de equipo solo con segmentos explícitos aprobados. Conductor puede ser miembro del equipo según regla ya registrada, no añadir empleado extra por suposición. Si César cambió esa regla, documentar versión antes de integrar.
- Incluir todas las fiestas/retenciones que consumen capacidad, aunque no tengan asignación final, además de bloqueos y mantenimiento. Asignaciones ya fijadas no se mueven para hacer que otra fiesta quepa. Dos bloqueos del mismo traje no equivalen a dos trajes menos.
- Comprobar toda la cadena anterior→candidato→siguiente para CADA recurso reutilizado; salida tras desmontaje, ruta en fecha/hora, llegada antes del montaje. Comprobar origen, transporte del equipo y retorno cuando sean requisitos. Una ventana extra de60 minutos no prueba una ruta. Si falta dirección/evidencia o el servicio falla, revisión; no instantáneo. El código local de30 usa travel + max(30, breakdown_before + setup_after + contingency); Claude debe alinear con instrucciones posteriores, no sumar dos políticas diferentes.
- Tiempos UTC internamente y America/Chicago para reglas locales; cruce de medianoche/DST; extremos contiguos solo son factibles si también cumple logística. Una fiesta no cuenta dos veces al cruzar medianoche. Usar misma definición de día en web, apartados y confirmación.
- Fuentes externas: identidad comprobada al deduplicar, horario completo y datos vigentes. Una coincidencia de horas es solo una hipótesis. Un evento HoneyBook sin receta puede consumir el mismo equipo: revisión hasta reconciliar. Fuente ausente/malformada/no accesible no significa agenda vacía.
- Evaluación acotada: si excede presupuesto de cálculo o hay demasiados datos, devolver desconocido, nunca inventar capacidad. No exponer nombres, domicilio, roles internos ni IDs del staff al cliente.

## Admisión atómica antes de pago

1. Validar identidad, catálogo/receta, fecha/hora/dirección completos y aprobaciones ligadas a la versión del evento. Leer evidencia externa fuera de locks, con fecha de comprobación y versiones.
2. En una transacción: adquirir los locks comunes en orden documentado, releer todos los compromisos y versiones, verificar vigencia de evidencia y resolver la asignación completa. Reutilizar DailyCapacity y disciplina existente; no introducir locks en orden inverso.
3. Crear una retención duradera de capacidad para el conjunto completo o rechazar sin efectos. Dos clientes compitiendo por el último recurso: exactamente uno obtiene el apartado. No aceptar check-then-insert sin exclusión mutua. Una clave idempotente repetida no duplica ni extiende plazos.
4. Registrar el intento de cobro vinculado a esa retención y al acuerdo. Llamar al proveedor fuera de la transacción, con idempotencia. Revalidar autoridad antes de devolver un enlace; conservar el vínculo si el proveedor responde tarde. No mantener locks durante red.
5. Al recibir pago verificado: registrar el dinero exactamente una vez y promover la misma retención atómicamente, sin consumir otro cupo. Si falta capacidad/vence/cambia el acuerdo: estado pagado con revisión; no confirmar, no borrar pago, no sustituir silenciosamente equipo. No ejecutar reembolso automático sin política/autoridad existente.
6. Vencimiento: cotización y retención son objetos distintos; respetar máximo48h aprobado sin renovarlo por clic/reintento. No liberar capacidad por el reloj si hay pago en proceso/resultado desconocido o aviso Zelle que exige conciliación. Cerrar o verificar el intento y liberar exactamente las unidades del apartado en orden consistente. El estado pendiente puede requerir conciliación aunque ya no se acepte iniciar un nuevo pago.
7. Cancelación/reprogramación: devolución de dinero no equivale por sí sola a liberar agenda. Reprogramar adquiere primero capacidad nueva y conserva la original si falla; después libera la anterior atómicamente. Nunca dejar dos eventos reclamando el mismo recurso.
8. Dirección final, horario, extras, extensión del servicio, plantilla o baja de recurso posteriores: invalidar aprobación/plan anterior y revisar impactos sobre todos los eventos vecinos. Aviso interno visible; no seguir enviando confirmación automática con datos viejos.

## Puntos concretos que revisar en el código actual

- WebsiteCapacity de1fcdadc arregla matching por instante, pero permite cambiar de empleado entre instantes de una fiesta continua. Reproducción sintética ejecutada: dos empleados, cada uno ocupado en una mitad distinta, candidato de10–12; retorna available aunque ninguno puede cubrirlo entero. PoolCapacitySolver sí rechaza con prueba de continuidad.
- PrepareQuickStripeLease actualmente aparta trajes; QuickPaymentCheckout verifica ese apartado; PromoteQuickPayment promueve dinero/apartado, no demuestra equipo completo. Son puntos para integrar la admisión común, no pruebas de que ya está implementada.
- TickQuickPayments libera filas antes de comprobar cierre remoto, que hace después best-effort. Revisar carrera con pago en proceso y eventos tardíos; no cambiarlo sin pruebas del protocolo actual. Mantener dinero verificado y excepciones durables.
- Rutas alternativas de cobro/confirmación: reserva rápida, cotización aceptada, portal normal, Zelle manual, Zelle notificado, importación activa, aprobación de datos y cambios internos. Todas deben respetar la misma autoridad de capacidad; la verificación del formulario web no cubre estas rutas.

## Matriz mínima de aceptación (datos ficticios)

| Caso | Resultado exigido |
|---|---|
| Dos clientes solicitan al mismo tiempo el último traje/equipo | Una sola retención; otro recibe conflicto/revisión; un solo cobro autorizado |
| Una persona con dos habilidades y dos puestos simultáneos | No disponible instantáneo; personal adicional sujeto a resolución del dueño |
| PersonaA libre solo segunda mitad yB solo primera mitad | No declarar que una de ellas cubre toda la fiesta |
| Empleado conductor forma parte del equipo | No duplicar plaza ni usar ese conductor en dos equipos simultáneos |
| Dos bloqueos solapados del mismo recurso | Se bloquea esa unidad una vez, no otra unidad libre |
| Traje adecuado, intérprete incompatible o vestuario equivocado | No aprobación automática |
| Hora exacta de salida/llegada; ruta demasiado larga; anterior y siguiente | Solo aceptar si ambos traslados caben con montaje/desmontaje |
| Ruta falla o devuelve estimación para otra fecha/hora | Revisión, no disponible automático |
| Cinco fiestas simultáneas / once en día | Rechazo conforme límites aprobados en todas las rutas |
| Cadena cruza medianoche/cambio horario | Mismo criterio web/admisión/confirmación; no doble conteo |
| Dos eventos externos con horas idénticas, sin identidad común | No eliminar uno por coincidencia |
| Calendario no configurado, truncado, recurrente no soportado | Desconocido; ningún cobro automático |
| Cliente cambia dirección/paquete/hora tras revisión | Prueba anterior inválida; recalcular antes del cobro |
| Otro evento modifica agenda mientras se calcula ruta | Releer versiones y repetir; no aceptar snapshot viejo |
| Dos clics / webhook duplicado o fuera de orden | Una reserva y un recibo, sin renovar vencimiento |
| Pago pendiente al vencer48h; respuesta de red desconocida | Conciliación, no vender el mismo cupo mientras exista riesgo de pago aceptado |
| Pago verificado llega después de liberar cupo | Dinero registrado, excepción visible, sin confirmación imposible |
| Zelle con imagen/aviso, sin verificación | No tratar imagen como dinero ni completar reserva |
| Reprogramación no cabe / reembolso sin cancelar fiesta | Original sigue protegido / no liberar por devolución solamente |
| Recurso se avería o empleado queda bloqueado tras reserva | Incidencia operativa visible, detectar reservas afectadas; resolución del dueño |
| Fallo del cálculo o presupuesto agotado | Desconocido, sin efectos de reserva/pago |

No existe garantía absoluta frente a averías o ausencias futuras. El objetivo comprobable es que ninguna reserva automática nazca de una capacidad no demostrada y que cualquier cambio posterior quede detectado y atendido. No declarar100% operativo solo por pruebas unitarias verdes: falta integrar y probar el recorrido completo con concurrencia y proveedores simulados, y revisar5 fechas reales de forma privada según el plan de Claude.
