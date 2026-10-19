# TIP-01 — revisión del núcleo A en sandbox

2026-10-19. Implementación en `codex/tip-01-backend`, encima de #87; no cambia la rama de Claude. Flags apagados por defecto. Solo datos ficticios y Stripe test. La aprobación de código no autoriza despliegue, cuenta real, cobros ni mensajes.

## Qué revisar

El dueño emite/revoca un enlace para una fiesta terminada, confirmada y bajo responsabilidad del CRM. Su revisión financiera y capacidad deben seguir vigentes. HoneyBook continúa bloqueado, incluso si alguien altera solo la etiqueta de responsabilidad. El enlace vence exactamente 14 días después del final de la fiesta.

La clienta intercambia el fragmento por una sesión dedicada. Puede elegir 5/10/15 % redondeado al dólar o un importe USD1–2000. El servidor fija importe, moneda, cuenta, idioma y origen por intento. Misma clave y payload repiten la solicitud original. Una respuesta incierta mantiene ocupado el evento completo, aunque se emita otro enlace. No volver a cobrar con otra clave para resolver un resultado desconocido.

La vuelta desde Stripe no prueba el pago. Solo evidencia firmada, recuperada y vinculada a cuenta/sesión/payment intent crea un recibo inmutable en las tablas de propina. No toca el saldo, depósito, ledger, capacidad ni avisos de pago de la reserva. Dinero real de un intento ya emitido se conserva aunque después cambie la fuente o se revoque el acceso.

El dueño lee bruto, devoluciones registradas, pendientes e incidentes. Si hay cobro incierto o conciliación pendiente, `net_staff_cents=null`: no mostrarlo como cero ni como dinero disponible para repartir. Las comisiones desconocidas siguen null. No hay reparto automático ni transferencias al staff.

## Conflictos

- 409 por revisión cambiada: recargar; no reutilizar datos antiguos para otro cobro.
- 503 tras respuesta incierta: conservar UUID y payload; reintentar exactamente ese intento.
- Cuenta/origen cambiados: conciliación, sin llamada adicional al proveedor.
- Checkout vencido localmente: no mostrar URL. Se mantiene el bloqueo hasta verificar cierre remoto; una hora local no demuestra que el proveedor dejó de cobrar.
- Acceso revocado: se intenta cerrar el checkout pendiente; si ya está pagado no se borra ni se convierte en expirado.
- Refund/disputa sin conciliación: incidente visible; no inventar devolución ni neto definitivo.

## Alcance que sigue pendiente

Conector del correo de gracias e IssueTipGrantForMail; representación agregada de propinas en REPORT-A; acción de refund en el proveedor; regla/aprobación de reparto STAFFPAY y pantalla del dueño completa. Este núcleo no entrega esas piezas ni cambia las plantillas aprobadas. La proyección pública usa la página de Claude, que requiere su revisión visual conectada al backend.

Staging Hostinger: queda pendiente verificar HTTPS real/isSecure(), cookie Secure/HttpOnly/Lax, CSRF y retorno desde Stripe. Las pruebas automatizadas simulan staging; no constituyen un smoke del host. No habilitar transporte ni hacer cobros para completar esta revisión.
