# Revisión de las entregas nuevas de Claude · 4 de noviembre de 2026

Base revisada: main `64fa0d3`. Rama de correcciones: `codex/fix-november-review`. Esta entrega no despliega, envía, cobra ni habilita funciones.

## Correcciones demostradas

1. **Campaña apagada después de planificar:** se volvía a comprobar el correo general, pero no el interruptor de la campaña. Ahora se comprueban ambos en la reclamación y otra vez antes del transporte.
2. **Contacto de campaña cambiado:** el mensaje anterior podía salir al correo anterior. El contenido/destinatario se contrasta con la fuente vigente; no se redirige un mensaje preparado.
3. **Código vencido durante la planificación:** ya no se envía una oferta vencida; también se verifica que el código siga perteneciendo al mismo cliente y no esté canjeado.
4. **Baja de promociones entre reclamación y transporte:** se detiene antes de llamar al proveedor. Una negativa conocida queda `blocked`; una llamada de resultado incierto conserva `delivery_unknown`/`dispatching`, sin reintento automático.
5. **Enlace revocado tras preparar aviso de cambio:** email y SMS quedan bloqueados; se revisan identidad, alcance, vigencia y titular del enlace, el contrato firmado y la fuente de la fiesta.
6. **Teléfono cambiado mientras sale el email:** no se envía el texto anterior al teléfono nuevo, aunque ambos números tengan consentimiento. El email que ya salió conserva su resultado; cada canal tiene evidencia independiente.
7. **Inicio roto por campaña sin evento:** la advertencia intentaba generar `/eventos/{record}` con `record=null`. Ahora abre la ficha del cliente (o Prospectos/configuración según el origen), sin crear un evento. Los nombres de campaña son solo de presentación; siguen existiendo solo tres recordatorios manualmente programables.

No se modifican textos aprobados del cliente, términos contractuales ni importes. Las pausas generales y por fiesta siguen vigentes.

## Pruebas

- MariaDB 11.8 / PHP 8.3.36, datos sintéticos y HTTP simulado con el adaptador real Resend.
- Antes: las seis regresiones de entrega fallan contra las cuatro clases originales de main, cargadas desde un bootstrap de prueba fuera de Git. Resultado: **6 FAIL /17 assertions**.
- Después del arreglo de entrega: **35 pruebas /249 assertions PASS** (AnniversaryResendIntegrationProof, AnniversaryCampaign, RescheduleContract, TwilioSms).
- Inicio sin evento: **ERROR antes**, por parámetro `record` ausente; pasa después junto con las pruebas existentes de la pantalla.
- Nueva prueba C3 con **dos procesos y dos dueños reales sobre la misma MariaDB**: ambos intentan cambiar el mismo grupo de dos fiestas. Exige exactamente una transferencia, un acuerdo activo y una revisión financiera por miembro, versiones operativas intactas y cero recibos inventados. No se usa SQLite para probar locks.
- Grupo final de avisos/campañas/Twilio/Inicio/C3: **49 pruebas /356 assertions PASS**, incluida la carrera real del mismo grupo. Visuales: **8/8 PASS**.
- Enlaces del run completo: comentario de entrega del PR. No sustituir el run completo por estas pruebas enfocadas.
- Visuales: HTML real de Inicio con cliente ficticio y aviso de resultado desconocido; 390×844 y 1280×844, locales ES/EN, claro/oscuro. Foco mediante teclado, enlaces a la ficha del cliente, área táctil y ausencia de desborde horizontal. Evidencia fuera de Git en `/workspace/setup/waoo-phase1/browser/november-review-owner-*`. Admin sigue en español por decisión del dueño. Se carga el archivo de tokens existente para `--focus`.

## Estado de la revisión por entrega

| Entrega | Resultado y alcance |
| --- | --- |
| #171 outbox de campañas/avisos | Usa el outbox real y conserva resultados desconocidos. Esta rama corrige seis cambios de fuente/contacto que faltaban. |
| #174 recuperación C3 y dinero | Código recuperado en main; guarda principal y cargo de tarjeta por separado. La revisión se debe apoyar en la suite Opening/Refund/Integrity del run completo; no se ensayó dinero real. No fusionar otra vez las pilas antiguas sobre main. |
| #175 recuperación de checkout perdido | Código y pruebas de recuperación ya están en main; la búsqueda no equivale a registrar un pago. La retención sigue cuando la búsqueda no prueba cierre. |
| #176 S1 | Revisa el estado de admisión antes del checkout. **No implementa todavía la retención conjunta durable de staff, trajes y equipo para autoservicio web**. |
| #178 cambio HoneyBook→CRM | Pruebas normales pasaron en la tanda inicial; esta rama añade la carrera real del mismo grupo que Claude pidió. No ejecuta un cambio real ni programa por sí sola los avisos históricos. |
| #183/#184 staff y pago sugerido | Pruebas de respuestas, permisos y sugeridos pasaron en la tanda inicial. No hay transferencias de dinero al staff. |
| #185/#188 extras | Pruebas actuales de saldo/depósito y falta de equipo pasan. Falta completar la prueba de extras→cambio de fecha/nuevo contrato, compatibilidad con la evidencia congelada de Reserva rápida con tarjeta, retención de unidades y copia PDF/correo del anexo. Mantener el flag apagado hasta cerrar esos puntos. |
| #186/#187 precios por paquete/trajes especiales | Mantener snapshots existentes y la excepción explícita de quién usa un traje por encima de la regla cabeza/peluca. No se cambian datos del catálogo aquí. |
| #190 pagos posteriores | Klarna/Afterpay se contrastan con capacidades del proveedor; la prueba actual pasa. La entrega reconoce que activar el cargo de 3% antes de elegir método también lo aplicaría a BNPL: no dar por validada esa política para producción. Zip no verificado. |
| #191, punta 6b60b964 | Durante esta revisión añadió importación masiva. Hallazgos y pruebas requeridas en el comentario enlazado abajo. No se da por revisado ni se despliega desde esta rama. |

Tanda inicial anterior a cambios: **32 pruebas /311 assertions PASS**, incluidos extras, pagos posteriores, transferencia, campañas, cambios de contrato, excepciones de trajes y staff.

## Trabajo que sigue abierto

- Cerrar las observaciones del importador automático/preselección de #191: no perder personajes del origen, no convertir tiempos ausentes en cero, respetar bloqueos/vestuario/ventanas al sugerir trajes. Comentario: https://github.com/cesaraolarteq-dotcom/waoo-crm/pull/191#issuecomment-6625374863 .
- S1 conjunto durable antes de autoservicio/cobro web. No confundir una nueva comprobación con una reserva física completa.
- Asociaciones reales y pantalla privada de identidades de calendario: el motor estable ya se integró en #166; no se han creado asociaciones reales por similitud de horario.
- La regla real de ruta +30 extra y la prueba de pins multihabilidad ya están en #165; no deben seguir listadas como si nunca se hubieran implementado. Conservar pruebas actuales de traslado y carreras por la última unidad.
- Adaptadores live, webhooks verificados, exclusión de mensajes/intentos de prueba/históricos y migración completa antes de activar envíos/pagos. El hostname o APP_ENV solos no autorizan la activación.
- La migración de `notice_deliveries` elimina evidencia de campañas sandbox al hacer rollback. No se ejecutó rollback con datos reales; se recomienda migración hacia adelante y revisar esa eliminación antes de operaciones de producción.

#163 se cerró como integrado: su punta `0550d133` es ancestro de main por #167 (`baf4bc8`). Los PRs C3 antiguos requieren cierre administrativo tras cotejar el port de #174, no una segunda fusión. Esto no declara terminado el proyecto.
