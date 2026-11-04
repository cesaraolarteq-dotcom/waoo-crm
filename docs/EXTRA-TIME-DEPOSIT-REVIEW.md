# Tiempo extra y depósito: revisión de #200

Fecha: 2026-11-04. Integración `codex/fix-extra-time-audit`, conserva Claude hasta `3b37fde` y main `ae12637`. Sin despliegue ni activación.

## Duración operativa

- `extends_event` identifica tiempo extra en el catálogo. Es un servicio de duración positiva sin requisitos/personajes propios; necesita un servicio base. No se inventan variantes ni precios.
- Al compilar el borrador se congelan `event_services.extends_event_snapshot` y `covers_event_snapshot`. El segundo identifica recreadoras que cubren toda la fiesta: su duración no vuelve a sumar la hora extra. ConfirmBooking usa las copias de la fiesta, no el catálogo actual.
- Duración: máximo de los servicios base + suma de extensiones. Recreadora extra debe durar paquete principal + extensiones; se valida antes de cliente/evento/dinero en Reserva rápida. La sugerencia elige solo una variante activa inequívoca de esa duración y conserva precios/versiones.
- La fiesta debe cubrir esa duración al guardar una extensión y al confirmar. Las reservas y el motor siguen usando el intervalo completo de la fiesta, con montaje/desmontaje y las reglas de ruta existentes.
- Cotización, finanzas y recetas de retención conservan la marca de extensión. La receta conserva también la marca de cobertura completa. Son campos opcionales en snapshots nuevos: los sobres históricos ordinarios mantienen su forma exacta.
- El portal EXTRAS-01 solo ofrece extras simultáneos, por lo que no ofrece extensiones aunque alguien marque `offered_as_extra`: vender tiempo desde ese flujo requiere extender/revalidar el acuerdo y la retención.
- Las migraciones no infieren la duración de eventos antiguos desde el catálogo mutable. Borradores de crm-test que hubieran usado la propuesta sin estos snapshots necesitan revisión explícita; no hay backfill automático ni modificación de reservas firmadas.

## Dinero y proximidad

- Decisión de César registrada por Claude: reserva nueva para hoy/mañana en Houston = 100 %, con al menos 24 horas de anticipación. Otros casos = 50 %, redondeado hacia arriba al centavo. No cambia la política de reembolsos ni el texto del contrato.
- La primera revisión nativa guarda `deposit_percent_snapshot`. Las siguientes heredan la primera política; se excluyen los cargos por cambio pagaderos con el saldo. La marca explícita evita confundir 50 % y 100 % cuando ambos redondean al mismo centavo. Acuerdos históricos/cotizaciones/importados conservan su evidencia y objetivo; no se reescriben.
- La confirmación administrativa usa el objetivo financiero vigente (o la primera política si el borrador requiere recálculo), sin decidir de nuevo según la fecha de hoy. HoneyBook conserva su camino histórico y sus excepciones.
- Preparar un apartado congela como mínimo el objetivo financiero. El cliente solo puede elegir porcentajes que lo cubran; el servidor rechaza 50 % si corresponde 100 %. El formulario selecciona la primera opción permitida. Zelle muestra el mismo importe.
- Apartado nuevo sin 24 horas: rechazo antes del cobro. Checkout nuevo/reintentado sin la anticipación necesaria: rechazo. La sesión nueva de Stripe vence como máximo en inicio−24 horas y respeta el plazo original del apartado. Se exige ventana mínima del proveedor; no se renuevan las 48 horas.
- Sobres de lease/terms V1 conservados. Checkout cliente nuevo usa schema3; schema2 conserva su reconstrucción histórica. Solicitud interna usa schema2 solo si debe acortar el vencimiento; schema1 histórico se verifica igual. No se modifica la evidencia ya emitida.
- Un recibo real parcial/tardío se registra por su importe real y queda para revisión si no alcanza el objetivo o la anticipación. Nunca se fabrica un pago completo ni se pierde el dinero por aplicar la regla nueva.

## Evidencia y límites

Regresiones en `ExtraTimeAuditRegressionTest`, política en `DepositPolicyTest`, y los grupos de duración, confirmación, finanzas y pagos existentes. Siete fallos adicionales reproducidos sobre la propuesta de Claude; además se reprodujo la doble suma de tiempo al combinar recreadora de duración completa y extensión. Resultados y enlace de CI completo se dejan en el PR.

Navegador: `tests/Browser/extra-time-payment.cjs` usa Blade/JS reales con API interceptada, 50/100 %, ES/EN, claro/oscuro y 390/1280. Comprueba selección, importe Zelle, payload, foco, área táctil y ausencia de desborde. Capturas fuera de Git. No constituye E2E con Stripe real.

Siguen abiertos S1 durable conjunto (personas, trajes, equipo y vehículos) antes del cobro, rutas reales/DST del plan semanal, asociaciones reales de calendario, cadena de anexos y adaptadores/cambio de producción. La ruta ordinaria de cobro nativo necesita la revisión integral S1: esta entrega no declara que todos los cobros de la web estén listos. Web en preview, pagos y envíos pausados. Ningún despliegue, proveedor real, importación o bandera modificados.
