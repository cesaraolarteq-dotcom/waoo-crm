# Solicitudes de excepción — contrato funcional propuesto

2026-10-15 · Codex · Fase 0; para revisión de Claude. No hay interfaz, endpoints ni automatización implementados.

## Reglas que conecta este flujo

- Estándar de traslado: tiempo de viaje para fecha/hora previstas + al menos 45 minutos para desmontaje, montaje e imprevistos; ampliar si el servicio lo exige.
- El cliente puede solicitar hasta 15 minutos antes del horario recomendado; solo César/Maybeli pueden autorizar esa excepción.
- Solicitudes a menos de siete días requieren aprobación, aunque los recursos parezcan disponibles. El corte exacto en horas/calendario sigue por precisar.
- D7: una consulta, cotización, firma o aprobación **no aparta capacidad**. El primer depósito verificado que obtiene capacidad mediante validación transaccional la reserva. D2: la confirmación completa requiere además la firma y las aprobaciones aplicables.

Aprobar una excepción permite reducir el margen estándar de ese evento; no permite solapar uso físico, omitir preparación necesaria, inventar personal o ignorar un viaje imposible. No es una promesa de que cualquier diferencia de 15 minutos se puede aceptar.

## Recorrido del cliente

1. Mostrar horario recomendado y opción de solicitar uno hasta 15 minutos antes cuando exista un candidato viable para revisión. El backend calcula ambos; no confiar en una hora recomendada enviada por el navegador.
2. Mostrar claramente que el horario alternativo necesita aprobación y que la solicitud no reserva la fecha. No presentarlo como disponibilidad automática.
3. Guardar la solicitud con el horario pedido, evento, servicios, dirección y revisión de cálculo. Si faltan menos de siete días, incluir ambas razones en la misma solicitud; no obligar al cliente a enviar dos formularios.
4. Mostrar estado pendiente, aprobado, rechazado o alternativa propuesta. Aprobar no significa confirmar reserva ni registrar pago. La comunicación puede indicar cómo continuar, siempre con el aviso D7.
5. Si se propone otra hora, el cliente debe aceptarla expresamente antes de sustituir la solicitada. No mover el evento silenciosamente. Firma/pago siguen vinculados a la versión correcta de cotización/contrato.

Texto de referencia para revisión de Claude, no copy publicado:

| Situación | ES | EN |
| --- | --- | --- |
| Alternativa | Solicitar este horario — requiere aprobación | Request this time — approval required |
| Pendiente | Estamos revisando tu solicitud. La fecha aún no está reservada. | We’re reviewing your request. Your date is not reserved yet. |
| Aprobada | Solicitud aprobada. La reserva requiere pago verificado, firma y disponibilidad vigente. | Request approved. Booking requires verified payment, a signature, and current availability. |

No prometer plazo de respuesta que el dueño no haya definido. El contrato permanece en inglés según D9; portal y mensajes son EN/ES.

## Revisión de César/Maybeli

Una ficha muestra: fecha/hora solicitada y recomendada, motivo(s), anticipación, tiempo de viaje previsto y hora de consulta, desmontaje/montaje necesarios, margen restante, grupos/personas/roles, conductor/vehículo y carga, trajes/speakers, compromisos anteriores/posteriores y advertencias de preparación/staff.

Acciones: aprobar, proponer otra hora o rechazar. Un clic puede aprobar todos los motivos visibles y revisados; registrar qué excepciones cubrió, quién, cuándo y sobre qué versión. Si queda un conflicto físico, datos esenciales sin verificar o un motivo obligatorio sin resolver, no habilitar confirmación. No exponer al cliente nombres/direcciones de otras familias ni detalle interno del personal.

El administrador puede completar evidencia de preparación o asignación antes de aprobar. La experiencia simple no elimina las comprobaciones en servidor. Un botón deshabilitado en frontend no es autorización.

## Estado e integridad propuestos

| Estado | Significado | Reserva capacidad |
| --- | --- | --- |
| Pendiente | Petición recibida; falta revisión | No |
| Aprobada | Excepciones autorizadas para esta revisión | No |
| Alternativa propuesta | Espera aceptación de otro horario | No |
| Rechazada | Esa petición no fue autorizada | No |
| Requiere nueva revisión | Cambió una entrada material o evidencia relevante | No |

La aprobación se vincula a evento, revisión de cotización/agenda, horario solicitado, motivos, recursos propuestos y evidencia de ruta/preparación. Cambios materiales invalidan la aprobación afectada. No agregar un vencimiento arbitrario: decidir vigencia comercial aparte, sin convertirla en retención de recursos.

Guardar solicitud y decisión con idempotencia y versión esperada. Dos administradores que deciden sobre la misma versión no deben sobrescribirse silenciosamente. Repetir una petición no acumula descuentos de 15 minutos: comparar siempre con el horario recomendado de la revisión base. Si se recalcula, emitir nueva revisión y reevaluar elegibilidad.

Antes de permitir el recorrido de pago, verificar aprobaciones requeridas y disponibilidad actual sin apartar recursos. Al recibir el depósito Stripe verificado o verificar Zelle, bloquear recursos en transacción, reevaluar horarios/rutas/aprobaciones y asignar capacidad solo si cabe. El pago recibido se registra aunque no pueda asignarse capacidad; abrir incidencia para alternativa o devolución autorizada. No confirmar por redirect ni por comprobante Zelle.

Si el pago ya aseguró capacidad y falta firma, mantener separado “capacidad reservada con pago” de “reserva completamente confirmada”; no liberar automáticamente por haber terminado una sesión del navegador. La política de seguimiento/cancelación seguirá las condiciones aprobadas, no una expiración inventada. Con firma, depósito, excepciones y recursos válidos se completa la confirmación.

## Ejemplo y criterios de aceptación (no ejecutados)

Ejemplo: evento A termina 16:00, viaje 28 minutos, estándar adicional 45 → inicio calculado 17:13. Si se adopta grilla de 15 minutos, recomendado 17:15 y solicitud 17:00. Esta solicitud deja 32 minutos sobre el viaje, no 45. Solo puede aprobarse si caben las operaciones y el margen residual aceptado. La grilla de 15 minutos sigue siendo propuesta.

| Caso | Resultado exigido |
| --- | --- |
| Solicitud 15 minutos antes del recomendado | Revisión administrativa; no reserva ni aprobación automática |
| Más de 15 minutos antes por manipular payload | Rechazar esa vía de excepción; no eludir el límite |
| Se repite solicitud ya adelantada otros 15 minutos | No aplicar adelantos acumulativos |
| Solicitud próxima al evento y horario adelantado | Una ficha, dos motivos; ambos deben quedar resueltos |
| Dos admins aprueban/rechazan simultáneamente | Una decisión por versión; el segundo recarga el estado vigente |
| Aprobada sin pago mientras otro cliente paga | La aprobación no bloquea; revalidación al pago del primero |
| Ambos depósitos llegan para único speaker disponible | Una asignación; ambos importes se registran y el perdedor entra a incidencia |
| Cambio posterior de dirección o staff | Nueva validación y revisión cuando invalida la aprobación |
| Montaje/desmontaje no caben en margen reducido | No aprobar ese horario; ofrecer alternativa factible |
| Pago externo llega sin aprobaciones obligatorias | Registrar pago, incidencia/revisión; no confirmar automáticamente |
| Evento confirmado entra en siete días por paso del tiempo | No convertirlo retroactivamente en solicitud de última hora |

Responsabilidades: Codex mantiene estados, permisos, transacciones y reglas; Claude diseña la ficha, portal y textos sobre este contrato funcional. No editar estos documentos como si fueran una implementación desplegada.
