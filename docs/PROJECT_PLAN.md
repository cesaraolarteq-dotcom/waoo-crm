# Waoo Party CRM — Plan maestro de producto y desarrollo

Versión 2 · 15 de octubre de 2026 · Preparado para César y Maybeli, Codex y Claude Code.

## 1. Propósito y resultado esperado

Construir el sistema operativo de Waoo Party: una experiencia integrada con waooparty.com donde el cliente pueda cotizar, consultar horarios realmente viables, reservar, firmar, pagar depósito y abonos, consultar documentos y gestionar su celebración. César y Maybeli deben poder administrar ventas, agenda, personal, personajes, equipos, transporte, cobros y rentabilidad desde un mismo lugar.

El éxito se mide por reservas correctas y eventos realizables, no solo por tener formularios y un calendario atractivo. Una reserva requiere una combinación factible de personas, vestuario, materiales, equipos y traslados.

Este documento amplía el PROJECT_PLAN.md del 14 de octubre y conserva sus principios: reutilizar la web, inspeccionar el repositorio antes de elegir tecnología, desarrollar por fases, probar Stripe en modo test y mantener HoneyBook durante la transición. No se modificó código, hosting ni configuración de producción en esta revisión.

### Estado de la información

- **Observado:** contenido público de Home, Packages, Contact, Travel Fee y Terms de waooparty.com, consultado el 15 de octubre de 2026; las nueve capturas adjuntas; PROJECT_PLAN.md y AGENTS.md anteriores.
- **Confirmado por César en las capturas:** aproximadamente 40 eventos mensuales; usuarios administrativos iniciales César y su esposa; HoneyBook alrededor de $80/mes; unos 250 elementos archivados por inventariar; Stripe existente; Zelle principal; SMS con un número de Twilio; WhatsApp integrado es deseable, pero no imprescindible; uso principal desde computadora con acceso móvil.
- **Información previa del negocio:** catálogo, personajes, dinámica de eventos y ejemplos de precios. Sirve para preparar el catálogo inicial; no activa automáticamente nuevas tarifas.
- **Pendiente de verificación técnica:** repositorio actual, backend, base de datos, funcionamiento real de disponibilidad, acceso/configuración de Maps, prestaciones de la cuenta Hostinger, estado de Twilio y exportación de HoneyBook.
- **Propuestas de este documento:** arquitectura, reglas técnicas, organización del trabajo y valores operativos sugeridos. Se distinguen de políticas comerciales ya aprobadas.

Las capturas muestran fragmentos del contrato y resúmenes de Claude, no el contrato completo ni el contenido completo de su artefacto. No se copian datos personales de la clienta de ejemplo ni se afirma haber auditado código que no estuvo disponible.

## 2. Lo existente y cómo aprovecharlo

| Pieza | Hallazgo | Trabajo requerido |
|---|---|---|
| Web comercial | Navegación, paquetes, galería y selector EN/ES | Mantener identidad, URLs y posicionamiento; conectar el CRM por API |
| Formulario | Tres etapas: fecha/lugar, celebración, contacto | Guardar lead y borrador de evento; añadir configurador sin obligar a registrarse al inicio |
| Transporte | Estimador por ZIP; precio final con dirección exacta | Reutilizar integración tras auditoría; centralizar reglas en backend |
| Disponibilidad | Los términos actuales describen avisos informativos | No presentarla como reserva instantánea hasta implementar recursos y bloqueos |
| Proceso comercial | Depósito del 50% y contrato posterior | Preservar este recorrido y permitir contrato previo cuando se ofrezca al cliente |
| HoneyBook | Clientes, contratos, facturas, pagos y calendario | Migración controlada con conciliación documental y financiera |
| Hosting | En capturas se identifica Business Web Hosting | Comprobar runtime, base de datos, tareas programadas, backups y límites reales |

Revisión pública de contenido, no prueba de envío de formularios ni de pagos. No se enviaron consultas falsas a Waoo Party. Las capacidades referidas por Claude como ya implementadas deben comprobarse en el repositorio.

### Diferencias que no deben convertirse en reglas por accidente

1. La web publica dotaciones distintas por paquete: por ejemplo, Música y Juegos 2.0 anuncia entertainer y dos hosts; Waoo también incluye DJ. Validar si son personas distintas, funciones combinables o texto por corregir. El motor necesita personas y roles concretos.
2. La experiencia histórica de Glitter Bar y su oferta pública actual no son idénticas. Separar versiones/variantes y aprobar cuál se vende.
3. K-Pop tiene precios y composiciones particulares. No aplicar el mismo incremento por personaje a todos los paquetes.
4. Las capturas muestran una excepción por clima con crédito del 50% para ciertos eventos pagados, además de una espera de $150/h. Existen otras reglas históricas de espera/reprogramación. No unirlas en una penalidad universal: recuperar el contrato íntegro y usar políticas por servicio y versión.
5. La preferencia de pagar saldo 24 horas antes admite excepciones históricas antes de iniciar el servicio. Separar fecha de vencimiento, recordatorios y política para autorizar el inicio.
6. El teléfono público de atención y el número de Zelle son distintos. Configurarlos por propósito; no sobrescribir uno con otro.

## 3. Las tres experiencias del sistema

### A. Cliente: cotizar y administrar su fiesta

Recorrido propuesto:

1. Indicar fecha, ZIP y hora deseada. Elegir EN/ES.
2. Elegir paquete, cantidad de personajes y personajes concretos si corresponde.
3. Añadir complementos y número aproximado de niños; mostrar incompatibilidades antes de avanzar.
4. Ver estimación desglosada y horarios orientativos. Aclarar qué falta para volverlos definitivos.
5. Introducir dirección exacta y datos mínimos de contacto para validar viaje y recursos.
6. Ver horarios disponibles, horarios alternativos o solicitud de revisión. Nunca mostrar detalles de otras familias o del staff.
7. Seleccionar horario y generar una retención temporal real, con vencimiento visible.
8. Revisar cotización, alcance, depósito y condiciones. Firmar antes o después del depósito según el flujo aprobado.
9. Pagar con Stripe o seguir instrucciones de Zelle. Un comprobante de Zelle se recibe como pendiente de verificación.
10. Recibir confirmación del estado real: reservado, esperando verificación de pago, o pendiente de completar documentos.
11. Abrir su portal para firmar, abonar, descargar factura/contrato, consultar saldo y completar instrucciones del evento.
12. Solicitar cambios o complementos; el sistema recalcula precio y capacidad antes de aceptarlos.

No exigir crear contraseña para explorar precios. Para documentos y pagos, acceso por enlace seguro de corta duración que crea una sesión autenticada, con opción de solicitar uno nuevo. Vincular correctamente clientes con varios eventos sin exponer todos los eventos al poseedor de un enlace aislado.

### B. Administración: César y Maybeli

Pantalla inicial con próximos eventos, leads sin atender, cotizaciones pendientes, Zelle por verificar, contratos sin firma, saldos vencidos, personal sin confirmar, conflictos, fallos de mensajes y problemas de integración.

Menú: Inicio; Leads; Clientes; Cotizaciones; Eventos; Calendario; Staff; Personajes y vestuario; Equipos y vehículos; Facturas y pagos; Contratos; Mensajes; Reportes; Configuración.

Ficha central de cada evento con pestañas de resumen, servicios, agenda, recursos, documentos, pagos, comunicaciones, gastos e historial. Cada dato debe editarse una sola vez y propagarse mediante versiones controladas.

### C. Personal: operación desde el teléfono

Portal móvil con disponibilidad semanal, invitaciones de trabajo, aceptar/rechazar, agenda asignada, rol, personaje y traje, llegada/salida, ubicación, instrucciones, checklist y reporte de incidencias. Mostrar únicamente los datos necesarios para su trabajo. Los miembros del staff no ven ingresos del negocio ni remuneraciones de otras personas.

No hace falta una app nativa inicial. Diseñar una web móvil usable con mala conexión; no permitir que información guardada offline confirme nuevas reservas o pagos.

## 4. Catálogo maestro de servicios y precios

Todos los precios, descripciones públicas, duración y requisitos deben salir de un catálogo único versionado, administrable por César/Maybeli. No mantener un precio en la web y otro en el CRM.

### Base conocida para cargar como borrador

| Paquete | Duración | Precio base conocido | Personaje |
|---|---:|---:|---|
| Star / Estrella | 30 min | $275 + transporte | 1 |
| Magic / Mágico | 1 hora | $375 + transporte | 1 |
| Música y Juegos | 2 horas | $525 + transporte | No |
| Música y Juegos 2.0 | 2 horas | $600 + transporte | 1 |
| Waoo | 3 horas | $800 + transporte | 1 |

Variantes conocidas a validar: Magic con tres personajes $675; Música y Juegos 2.0 con tres $900; Waoo con tres $1,100. Los paquetes Huntrix/K-Pop tienen variantes propias, incluida una oferta Elite distinta. No deducir precios faltantes interpolando los existentes.

Familias adicionales: K-Pop; visitas sin show; pool party; foam; face painting como complemento y como estación independiente; spa; glitter; mural; estaciones creativas; photobooth; Hora Loca y efectos; Bubble Magic Show. Cada familia tiene recursos y reglas diferentes.

Bubble Magic Show: dato histórico de una hora, $475 más transporte, con hada. Mantener en borrador hasta habilitarlo. Preservar la instrucción comercial de no anunciar explícitamente burbujas gigantes ni la experiencia del niño dentro de una burbuja.

### Cada servicio/variante necesita

- ID estable, nombre ES/EN, categoría, imágenes, estado activo/borrador/retirado y vigencia.
- Precio fijo, por participante, por duración o personalizado; cantidad incluida, extra por participante y mínimo de compra.
- Duración de servicio y duración de cada recurso; llegada, montaje, cambios de traje, limpieza y desmontaje.
- Qué incluye y qué no: coordinación de piñata no significa proporcionar piñata; entrega de goodie bags no significa incluirlas.
- Cantidad de personajes y lista compatible, vestuario elegible y funciones simultáneas.
- Roles y habilidades requeridos, personal mínimo, equipos y consumibles.
- Requisitos del lugar: electricidad, agua, espacio, suelo, acceso, estacionamiento y restricciones del salón.
- Complementos permitidos; si agregan tiempo, operan en paralelo o requieren otro equipo.
- Capacidad por hora, límites operativos y regla para excedentes.
- Textos comerciales y checklist interno separados.
- Plantilla de agenda y cláusulas aplicables.
- Costo interno previsto y tarifa de remuneración, visibles solo para administración.

Ejemplos: un add-on de face painting puede extender la fiesta o requerir una artista paralela; esa decisión debe quedar explícita. La capacidad histórica de aproximadamente diez niños por hora para face painting debe generar una recomendación de tiempo/personas. El spa debe reservar facilitadoras, mobiliario y transporte. Un photobooth necesita preparación de diseño y verificación de espacio/energía. Un servicio personalizado no debe ofrecer compra instantánea sin receta operativa completa.

### Personajes y vestuario

Distinguir tres entidades: personaje comercial (por ejemplo Ariel), traje físico (sirena o vestido, unidad identificada) y persona capacitada para interpretarlo. Ninguna sustituye a las otras.

Importar el listado real de Waoo Party con nombres normalizados, imágenes y variantes; no inventar existencias o disponibilidad de Batman/Superman. Para cada traje: ID, talla/ajuste operativo, ubicación, estado, limpieza, mantenimiento y combinaciones autorizadas de intérprete/traje. Dos copias del mismo personaje son dos unidades; una única copia no puede estar simultáneamente en dos fiestas.

## 5. Motor de cotizaciones y transporte

### Cotización

Guardar líneas para paquete, variantes, extras, niños adicionales, tiempo extra, transporte, descuentos, impuestos aplicables y cargos aprobados. Todas las operaciones monetarias se hacen en centavos enteros USD y con política de redondeo explícita. El navegador muestra resultados calculados por el servidor; no decide el total.

Cada cotización tiene número, revisión, vigencia, estado, autor, fuente, precio/versiones usados y aceptación del cliente. Cambiar el catálogo no cambia cotizaciones aceptadas ni eventos históricos. Al modificar servicios se emite una revisión con diferencia de precio y se solicita aceptación según su impacto.

Fórmula de negocio propuesta:

`base_evento = servicios + extras + transporte - descuentos`

`total_evento = base_evento + impuestos_aplicables`

`depósito_objetivo = redondear(total_evento × porcentaje_aprobado)`

El depósito conocido es 50% incluyendo transporte. Antes de activar cobros, definir tratamiento de impuestos y cargos por método: si se cobran por transacción, no mezclarlos automáticamente con el principal del evento ni volver a cobrarlos sobre todo el contrato en cada abono.

### Transporte cobrado al cliente

Separarlo del costo logístico interno. El precio comercial debe ser estable después de la aceptación, aunque más tarde se reordene la ruta del equipo.

Regla histórica registrada, pendiente de cotejar contra el código activo:

| Distancia de referencia | Regla histórica |
|---|---|
| Hasta 10 mi | $20 |
| Más de 10 hasta 20 mi | $0.50/mi + combustible + peajes |
| Más de 20 hasta 38 mi | $0.65/mi + combustible + peajes |
| Más de 38 hasta 65 mi | $1.00/mi + combustible + peajes |
| Más de 65 mi | $1.50/mi + combustible + peajes |

No implementar esta tabla sin resolver: dirección base; distancia de ida o ida/vuelta; tarifa sobre todas las millas o por tramos; mínimo; combustible; peajes; redondeo; cantidad de vehículos; ruta de regreso y excepciones. Verificar discontinuidades en los límites para evitar que viajar más produzca un cargo menor por accidente.

La estimación por ZIP tiene carácter preliminar. Con dirección exacta, obtener ruta, distancia, tiempo y peajes cuando estén disponibles. Si falla Maps o no hay dato de peajes, pasar a revisión; no asumir costo cero. Registrar origen del cálculo, fecha, versión de tarifa y motivo de cualquier ajuste manual. Respetar restricciones de almacenamiento de datos del proveedor y guardar la decisión comercial necesaria sin retener indiscriminadamente respuestas de Maps.

Descuentos de transporte —como 50% o transporte gratis por reservar ese día— deben ser líneas explícitas con aprobación y vencimiento, no cambios escondidos en la fórmula base. Cotizar por servicio/grupo logístico para evitar cobrar dos veces el mismo viaje compartido.

### Costo interno y margen

`costo_directo = personal + traslados + combustible + peajes + estacionamiento + consumibles + alquileres/subcontratos + comisiones_de_pago`

`margen_contribución = ingreso_neto_del_evento_sin_impuestos - costo_directo`

No llamar utilidad neta al margen de contribución: todavía faltan gastos generales. Mostrar estimado frente a real, con gastos compartidos distribuidos por una regla definida. No duplicar combustible/desgaste si se utiliza una tarifa interna integral por milla.

El costo de personal puede ser por evento, hora o rol, con tiempo de traslado y espera según acuerdo. Las remuneraciones históricas individuales no deben convertirse en tarifas universales.

## 6. Calendario inteligente y disponibilidad en vivo

### Decisión de arquitectura recomendada

El CRM debe ser la fuente oficial de reservas y recursos. Google Calendar será una vista sincronizada para comodidad. Una API genérica de citas no reemplaza la combinación de personajes, artistas, equipos y viajes que necesita Waoo Party. No contratar otro scheduler por defecto; evaluar uno solo si cubre explícitamente estas condiciones y reduce trabajo total.

La decisión debe registrarse tras revisar el calendario actual. Durante migración, ningún evento confirmado de HoneyBook puede quedar fuera del bloqueo del nuevo motor.

### Entradas necesarias

Fecha/hora local y zona America/Chicago; dirección exacta; paquete y versión; duración; personajes/trajes; extras; número de niños; roles; habilidades; equipos; vehículos/capacidad; origen y destino de cada recurso; disponibilidad declarada; bloqueos; eventos anteriores y posteriores; tiempos de preparación y traslado.

La disponibilidad de una persona requiere que realmente pueda trabajar ese día y hacer ese rol. Una persona que sabe representar tres personajes no equivale a tres intérpretes disponibles simultáneamente.

### Regla de encadenamiento

Para cada recurso que se mueve entre eventos A y B:

`fin_servicio_A + desmontaje_A + traslado_A_B + margen_tráfico + preparación_B <= inicio_servicio_B`

Si el montaje necesita varios recursos juntos, todos deben llegar a tiempo. También validar salida desde base/origen y regreso o siguiente compromiso. Incluir cambios de traje, descansos acordados, limpieza, carga/descarga, relevo de conductor y capacidad física del vehículo.

Ejemplo ilustrativo: un evento termina a las 6:30 pm y el siguiente está a 45 minutos. Las 7:15 pm representan solo fin del viaje; falta desmontaje, margen y preparación. Las 7:30 pm solo se pueden ofrecer si esos tiempos adicionales caben. No convertir ese horario en una regla fija para todos los viajes.

### Algoritmo inicial, determinista

1. Resolver requisitos por servicio y por intervalo; rechazar configuraciones incompletas.
2. Filtrar personal y unidades de equipo/vestuario elegibles y disponibles.
3. Buscar una combinación conjunta sin conflictos: cada persona y unidad puede usarse solo una vez en un intervalo incompatible.
4. Comprobar rutas de llegada y salida de todos los recursos y restricciones de vehículo/equipo.
5. Revisar la combinación contra reservas firmes, retenciones activas y bloqueos externos importados.
6. Guardar una asignación interna provisional concreta para respaldar la capacidad ofrecida, sin necesidad de exponer nombres al cliente.
7. Revalidar y bloquear esos recursos dentro de una transacción al crear el hold y al confirmar.
8. Si no hay combinación válida, proponer otros horarios, personajes o paquetes factibles; nunca sustituciones silenciosas.

No alcanza con contar cuántas personas hay libres. Si una única artista puede hacer tanto Ariel como face painting, un paquete que requiere ambos simultáneamente necesita otra persona o un cronograma compatible.

### Estados visibles al cliente

- **Disponible para reservar:** recursos y logística verificados; requiere retención transaccional al seleccionar.
- **Necesita revisión:** falta dirección, personal por confirmar, tarifa o configuración especial.
- **Sin disponibilidad:** no existe combinación viable bajo las reglas actuales; mostrar alternativas.

Una fecha con varias fiestas puede seguir teniendo capacidad. Evitar bloquear el día completo por una sola reserva. Evitar mensajes de escasez artificial: se muestran solo si reflejan capacidad real.

### Retenciones y concurrencia

- La consulta no bloquea. Seleccionar y avanzar al checkout crea un hold con expiración calculada por servidor; propuesta inicial de 15 minutos, ajustada a las restricciones del método de pago.
- Usar bloqueos transaccionales sobre recursos y la ventana de agenda afectada. Todas las escrituras de agenda, también las administrativas, deben usar el mismo protocolo.
- Una restricción por intervalo ayuda a prevenir superposición física, pero no basta para tiempos de viaje. Serializar cambios de agenda relevantes y volver a evaluar vecinos al confirmar.
- Al expirar, cerrar o invalidar la sesión de cobro cuando el proveedor lo permita y liberar recursos. Si no se puede alinear la sesión con el hold, adaptar su duración o usar un flujo de pago compatible.
- Un pago que llega después de expirar se registra; se revalida capacidad. Si existe, se confirma de manera atómica. Si no existe, pasa a incidencia para alternativa o reembolso autorizado, sin doble reserva.
- Los métodos de confirmación demorada necesitan una política explícita de retención prolongada o revisión manual. No habilitarlos en reserva instantánea hasta resolverla.
- Un Zelle pendiente tiene vencimiento separado y visible. No prometer que subir captura reserva automáticamente.
- Caché solo para búsqueda preliminar; la confirmación consulta estado vigente. El contador del navegador no es autoridad de expiración.
- Las reasignaciones provisionales deben mantener factibilidad de todos los eventos afectados; si no, no se guardan.

### Google Calendar

Primero sincronización unidireccional CRM → calendario: ID remoto, actualizaciones idempotentes, reintentos y cola de errores. Los cambios externos no cancelan automáticamente eventos del CRM. Si se importan compromisos personales, traducirlos a bloqueos ocupados y definir dueño, latencia y vigencia; ante sincronización vencida, impedir confirmación automática para recursos afectados.

La disponibilidad «en vivo» se refiere al inventario del CRM, no a garantía de tráfico futuro. Usar estimaciones de viaje y márgenes, revalidar cerca del evento y alertar cambios críticos.

## 7. Reservas y estados separados

No usar un único campo «estado» para mezclar venta, pago, contrato y operación.

| Dominio | Estados propuestos |
|---|---|
| Lead | Nuevo, Contactado, En revisión, Cotizado, Ganado, Perdido |
| Cotización | Borrador, Enviada, Aceptada, Vencida, Sustituida, Rechazada |
| Reserva | Solicitud, Hold, Reservada, Cancelada, Completada |
| Contrato | Borrador, Enviado, Visto, Firmado, Reemplazado, Anulado |
| Pago | Pendiente, Procesando, Recibido, Fallido, Parcialmente reembolsado, Reembolsado, En disputa |
| Operación | Sin asignar, Asignada, Personal confirmado, Lista, En ruta, En servicio, Completada, Incidencia |

**Reserva válida:** capacidad bloqueada + cotización aceptada + depósito verificado o excepción administrativa registrada. Si se escoge contrato previo obligatorio, añadir firma como condición. En el flujo histórico de depósito primero, conservar la reserva y mostrar «contrato pendiente»; no liberar por error una fiesta cuyo depósito se recibió.

Una excepción debe especificar quién, por qué, qué condición exceptúa y hasta cuándo. Un administrador puede aprobar condiciones comerciales especiales; no debe poder crear accidentalmente una doble asignación física sin resolver el conflicto.

Reprogramar exige reservar la nueva combinación, calcular diferencias y cargo aplicable, obtener aceptación y después liberar la anterior mediante una operación consistente. Si la nueva opción falla, conservar la original. Retener historial y contador de reprogramaciones.

Cancelar libera recursos según la política aprobada, pero no borra contrato, movimientos de dinero ni evidencia. La devolución, crédito y saldo exigible se deciden por la versión contractual aplicable, sin inferirlos solo de un estado de calendario.

## 8. Pagos, depósitos, abonos y conciliación

### Stripe

Recomendación inicial: Checkout para pagos vinculados a una cotización/reserva y a una intención de cobro concreta. Confirmar si hosted o embedded encaja mejor con el stack y los métodos habilitados; embedded puede mantener la experiencia dentro de la web, aunque autenticaciones de terceros pueden abrir otro flujo. No construir campos propios para capturar tarjetas.

Pago del depósito, saldo y abono voluntario deben compartir el mismo libro de movimientos. Si se utiliza Stripe Invoicing, definir cuál sistema emite la factura y mantener un solo identificador financiero; no duplicar facturas en Stripe y CRM.

Comprobar en la cuenta cuáles métodos admite realmente el negocio, país, moneda, importe y modalidad. Klarna, Afterpay y Zip no se activan simplemente por dibujar sus logos. No asumir que Zip ya está disponible mediante Stripe.

### Reglas monetarias

- Guardar importe exacto recibido, fecha, método, referencia, estado y asignación al evento. No «corregir» un pago al 50% teórico.
- Ejemplo de las capturas, anonimizado: total $455; recibido $227.05; saldo $227.95. El depósito objetivo es $227.50; la diferencia de $0.45 requiere tolerancia aprobada o completar el depósito. No cambiar el historial para que cuadre a mitad exacta.
- Si recibe $227.60, el saldo es $227.40. Si paga por encima del total, registrar crédito o devolución pendiente, no saldo negativo oculto.
- El cliente puede abonar más después del depósito; el servidor limita la intención de cobro al saldo vigente salvo pago expresamente adicional.
- Impedir dos cobros activos sobre el mismo saldo o gestionar su reserva de importe. Cancelar sesiones obsoletas al registrar un pago por otro canal; si el segundo pago ya se completó, reconocer sobrepago y resolverlo.
- Separar principal aplicado, impuesto, cargo al cliente, propina opcional y comisión del procesador. Una comisión descontada por Stripe no significa que el cliente deba ese dinero.
- Reembolsos, créditos comerciales y anulaciones son movimientos auditados; no editar destructivamente el importe del pago original.
- El saldo puede expresarse como «Remaining 50% balance due» cuando coincide con esa proporción. Después de abonos o diferencias, usar «Remaining balance due» con el importe real para evitar información falsa. Esta adaptación desarrolla la preferencia de texto de las capturas.

### Zelle y métodos externos

Zelle permanece como principal y sin cargo según la política conocida. Mostrar destinatario empresarial y número configurado, referencia del evento y monto. El cliente marca que pagó y opcionalmente carga comprobante; César o Maybeli verifican la entrada real antes de marcar recibido. No tratar una captura ni la lectura de un correo como prueba bancaria definitiva.

Permitir efectivo u otros métodos solo si Waoo los habilita. Guardar quién recibió, referencia y recibo. Evitar registrar dos veces el mismo Zelle o mezclar pagos entre clientes de nombres parecidos.

### El 3% requiere una regla por método

La web y el historial mencionan 3% para tarjeta/pago aplazado. No implementarlo como cargo universal: Visa distingue crédito de débito/prepago y prohíbe recargo en estos últimos. Antes de habilitar recargos, validar con procesador y asesor correspondiente los métodos, divulgación y reglas aplicables. Es una decisión de configuración pendiente, no una tarifa técnicamente validada por este documento. No reetiquetar un recargo para eludir las restricciones.

### Integridad técnica del pago

Verificar firma de Stripe sobre el cuerpo original. Persistir el evento antes de confirmar recepción; procesar con reintentos. Deduplicar por event ID y también por objeto/transacción para no registrar dos veces un mismo pago notificado de formas distintas. Consultar estado confiable, importe, moneda, cuenta y vínculo con la reserva. No suponer orden de llegada de eventos.

El regreso a una página «success» no confirma pago por sí solo. Manejar pendientes, fallos, autenticación, reembolso y disputa. Una disputa no cancela automáticamente un evento futuro sin revisión. Mantener una cola de excepciones y conciliación diaria entre proveedor y libro local. Si el servidor falla tras el cobro, el reintento debe recuperar la reserva sin volver a cobrar.

## 9. Contratos, firma y documentos

Recuperar el contrato completo aprobado como plantilla; conservar sus diez secciones sin reconstruir partes faltantes a partir de capturas. Mantener por separado datos del evento, alcance del paquete, política aplicable y evidencia de aceptación.

Campos: nombre adulto contratante, teléfono, email, dirección de evento, fecha, inicio/fin, paquete, personajes y vestuario si aplica, extras, transporte, total, depósito recibido, abonos, saldo real, vencimientos e instrucciones acordadas.

No exigir personaje en Música y Juegos u otros servicios sin personaje. Cuando sí corresponde, impedir enviar una versión definitiva sin cantidad, selección y variante necesarias.

Firma desde el portal con vista previa completa y opción de descargar. Evaluar proveedor especializado antes de decidir una implementación propia. Guardar identidad/verificación del firmante, consentimiento, versión exacta, fecha/hora, hash del documento y evidencia del proceso; información técnica adicional solo cuando proceda. Una imagen dibujada de firma sola no define el sistema completo de evidencia.

El PDF firmado es inmutable. Cambios de fecha, precio, servicio o condiciones generan adenda o nueva versión y, cuando corresponda, nueva aceptación; conservar el anterior. Una traducción al español debe usar una plantilla aprobada, no traducción automática improvisada al firmar. La suficiencia jurídica del texto y proceso queda para revisión del contrato, no se garantiza aquí.

Documentos adicionales: cotización PDF, invoice, recibo, hoja de evento, cronograma, checklist de preparación, solicitud y entrega de COI, autorizaciones del salón y diseño del photobooth/mural cuando aplique. COI se solicita y adjunta; el CRM no inventa ni emite pólizas.

## 10. Staff, recursos y operación diaria

### Ficha de personal

Nombre/contacto, rol, habilidades, personajes aprobados, disponibilidad recurrente y excepciones, base operativa, movilidad, conductor/vehículo si aplica, tarifa acordada y vigencia, máximo de carga y descansos definidos, estado activo y observaciones operativas pertinentes.

Disponibilidad declarada no es lo mismo que aceptación de una asignación. Propuesta: solo capacidad comprometida puede respaldar compra automática; las invitaciones pendientes quedan en revisión. Si un miembro rechaza, retirar su capacidad, buscar reemplazo compatible y alertar sobre eventos afectados.

### Asignación

Crear roles por evento y sugerir personas compatibles según habilidades, traslado, horarios, carga y costo; administración elige y confirma. Dejar reemplazo y respaldo, historial de aceptación y plazo para responder. No reasignar silenciosamente a alguien que ya recibió instrucciones sin notificarle.

Separar conductor, intérprete, recreador, artista, DJ y operador aunque una persona pueda cubrir más de uno en intervalos distintos. Describir si tres personajes participan todo el servicio o solo una fase; no asumir que el intérprete se libera al terminar su aparición.

### Equipos y vehículos

Inventario unitario para speakers, micrófonos, photobooth, impresora, foam cannon, robots, mobiliario, vanities, efectos y trajes. Existencias por cantidad para consumibles. Estado disponible, en uso, reservado, limpieza, mantenimiento o retirado. Registrar préstamos, pérdidas y salida/regreso. Los consumibles insuficientes generan reposición antes de aceptar servicios que dependan de ellos.

Vehículos: conductor, capacidad de personas/carga, origen, horarios, equipo transportado y posibilidad de múltiples paradas. La presencia de un vehículo libre no garantiza que un mismo conductor pueda recoger a dos equipos a la misma hora.

### Hoja operativa y cronograma

Contiene contacto autorizado, dirección, estacionamiento/acceso, llegada, montaje, inicio, actividades, fin y retorno, roles, equipo, vestuario, responsable, saldo/permiso de inicio cuando sea necesario y notas acordadas.

Plantilla Waoo conocida: face painting inicial de 45–60 minutos; juegos y actividades guiadas; momentos principales de piñata, show/aparición, baile, globos, fotos, cumpleaños y goodie bags. Mantener secuencia ajustable por evento. Si cambia la agenda, comprobar conflictos por fases antes de liberar recursos.

Checklists por servicio, confirmación de preparación, salida/en ruta/llegada, incidentes y cierre. Notificación de retraso con hora estimada revisada; una ETA no debe prometer puntualidad automática. No hace falta seguimiento GPS continuo del staff para la primera versión.

### Remuneraciones y gastos

Registrar trabajo asignado/realizado, remuneración prevista/final, extras aprobados, propinas y reembolsos de gastos. Exportar liquidación por persona y período. Es una herramienta de cálculo y aprobación, no un sistema que sustituya automáticamente nómina, impuestos o clasificación laboral.

## 11. Comunicación y automatizaciones

Correo transaccional primero; SMS mediante Twilio después de verificar cuenta, número, consentimiento y registro aplicable. WhatsApp integrado después; al inicio puede permanecer el contacto existente y registro manual de conversaciones relevantes.

Plantillas bilingües breves y cálidas, con variables revisadas: consulta recibida, solicitud de datos, cotización, pendiente de depósito, pago recibido, contrato, firma pendiente, recordatorio de saldo, datos finales, cambio de hora, agradecimiento y reseña.

| Disparador | Acción propuesta | Condición |
|---|---|---|
| Lead guardado | Acuse y tarea interna | Sin prometer horario |
| Cotización aprobada | Enlace al portal | Precio y versión válidos |
| Depósito verificado | Recibo y estado de reserva | Capacidad bloqueada |
| Firma pendiente | Recordatorio | Contrato vigente y no firmado |
| 7 días antes | Revisar datos/logística | Evento activo |
| Antes del vencimiento | Aviso de saldo | Saldo real positivo |
| 24 h antes | Resumen y tareas | Canal permitido; no duplicar cobro |
| Staff sin aceptar | Alerta interna | Plazo configurado vencido |
| Pago Zelle declarado | Tarea de verificación | No confirmar pago todavía |
| Evento completado | Gracias/solicitud de reseña | Sin incidencia abierta y canal permitido |

Programar cada tarea con ID único; al ejecutarla, consultar el estado actual. Cancelación, reprogramación, pago o firma invalidan avisos obsoletos. Registrar envío, entrega, fallo, reintentos y quién lo inició. Mostrar bandeja de respuestas vinculadas al cliente/evento sin duplicar contactos.

Guardar opt-in por canal, texto aceptado, fecha y origen; controles separados para comunicaciones comerciales cuando corresponda. El opt-out suspende mensajes de ese canal. Horarios de envío basados en America/Chicago. No activar campañas automáticas de marketing por importar contactos viejos.

Twilio exige condiciones específicas de registro/consentimiento según el tipo de número; comprobar A2P 10DLC para número local estadounidense y no asumir aprobación por tener una cuenta. Definir remitente de correo; sales@waooparty.com aparece públicamente, pero hay que verificar control y configuración de envío. Autenticar dominio y registrar rebotes. Usar datos ficticios y destinatarios controlados en pruebas.

## 12. Portal del cliente y cambios posteriores

El cliente debe poder:

- Ver sus eventos, dirección, fecha, hora, paquete, extras y personajes/trajes.
- Comparar y aceptar la cotización vigente; consultar las anteriores como historial.
- Firmar y descargar documentos; ver instrucciones y plazo pendiente.
- Pagar depósito, abonar o liquidar; consultar movimientos y recibos.
- Confirmar datos finales: contacto del día, acceso, espacio, electricidad/agua, horario de piñata/cumpleaños, cantidad de niños y peticiones.
- Subir archivos necesarios para diseño o requisitos del salón mediante enlaces privados.
- Solicitar COI, cambio de dirección, ampliación, cambio de personaje, reprogramación o cancelación.
- Contactar al equipo y ver el estado de una solicitud.

El portal no debe permitir que una edición de dirección o duración altere automáticamente una reserva confirmada. Crear solicitud, recalcular transporte y disponibilidad, presentar diferencia y obtener aceptación. Un complemento se vende automáticamente solo si tiene precio definido y todos sus recursos caben; si no, solicitud de cotización.

Accesibilidad: buen uso con teclado, contraste, etiquetas de formularios, mensajes de error claros, importes y fechas inequívocos, progreso guardado y firma utilizable en móvil. Mantener contenido EN/ES equivalente. No usar capturas de datos reales como ejemplos.

## 13. Reportes y métricas

Ventas: leads por canal, tiempo de respuesta, conversión a cotización/reserva, motivos de pérdida, ticket medio, paquetes y extras más vendidos.

Cobros: valor contratado, facturado, cobrado, saldo pendiente, reembolsos, disputas, créditos y comisiones. Separar caja cobrada por fecha de pago de servicios realizados por fecha de evento; no llamar todo «ingresos» sin indicar definición.

Operación: utilización de staff/trajes/equipos, horarios solicitados sin capacidad, rechazos por traslado, retrasos, cancelaciones, reprogramaciones y fallos de preparación.

Rentabilidad: margen por evento/paquete/zona; costo logístico; presupuesto frente a costo real; descuentos concedidos y costo de adquisición cuando exista información fiable.

Calidad del sistema: conflictos detectados, reservas duplicadas (objetivo cero), pagos duplicados (cero), tiempo de webhook, recordatorios erróneos (cero), integraciones caídas y porcentaje de reservas con recursos completamente asignados.

Exportar CSV para contabilidad y operación. Definir períodos y zona horaria; el total exportado debe conciliar con el panel.

## 14. Arquitectura técnica propuesta

### Regla inicial

No rehacer la web ni imponer framework antes de leer el repositorio. Proponer un monolito modular con base de datos relacional, API, trabajos en segundo plano y almacenamiento privado de documentos. El volumen actual no justifica microservicios ni un sistema de IA tomando decisiones de dinero/disponibilidad.

| Capa | Propuesta | Motivo |
|---|---|---|
| Web comercial | Reutilizar sitio actual | Preserva trabajo, marca y SEO |
| Cliente | Rutas integradas /reservar y /mi-evento, o portal enlazado del mismo dominio | Experiencia consistente |
| Administración | crm.waooparty.com, si encaja con despliegue | Acceso privado y operación separada |
| Backend | Ampliar stack existente si es mantenible | Evitar duplicación de lógica |
| Base de datos | Relacional con transacciones | Dinero, inventario y concurrencia |
| Archivos | Almacenamiento privado y enlaces temporales | Contratos y datos protegidos |
| Trabajos | Cola durable o jobs en base de datos con ejecutor confiable | Reintentos, PDFs y recordatorios |
| Pagos | Adaptador Stripe; libro local | Confirmación y conciliación |
| Rutas | Adaptador Google Maps Routes existente | Viajes y costo |
| Calendario | Adaptador Google Calendar | Vista externa, no segundo maestro |
| Comunicaciones | Adaptadores de correo y Twilio | Sustituibles y auditables |

Si el sitio es HTML/PHP y Hostinger lo favorece, evaluar backend PHP estructurado con base relacional. Si existe una app TypeScript/Node mantenible, extenderla. Registrar una decisión comparando compatibilidad, despliegue, colas, transacciones, backups, soporte y costo antes de elegir. No afirmar que Business Hosting basta para todos esos componentes solo porque permite una app.

Hostinger documenta soporte Node.js en Business/Cloud; eso no verifica los límites de esta cuenta ni workers permanentes. Probar recepción de webhooks, ejecución de jobs, acceso a base, almacenamiento, restauración y logs antes de comprometer arquitectura. [S5]

### Integración incremental

Feature flags: formulario conectado, cotización automática, disponibilidad avanzada, checkout, firma y reserva automática. Activar por servicio/variante, no todo a la vez. Mantener revisión humana para paquetes especiales.

Configurar desarrollo, staging y producción separados. Credenciales de test nunca se mezclan con live. No poner secretos en frontend o Git. Las claves públicas de SDK, si se necesitan, deben limitarse por dominio/API; las claves secretas permanecen en servidor.

## 15. Modelo de datos mínimo

| Grupo | Entidades principales | Invariantes |
|---|---|---|
| Acceso | User, Role, Permission, Session | Permiso por objeto y acción |
| Clientes | Customer, Contact, Lead, Consent | No fusionar personas solo por nombre |
| Catálogo | Service, Variant, AddOn, PriceVersion, Requirement | Precios/requisitos con vigencia |
| Eventos | Event, EventLocation, EventService, TimelineSegment | Zona horaria y dirección versionadas |
| Cotizaciones | Quote, QuoteRevision, QuoteLine, Acceptance | Versión aceptada inmutable |
| Personal | Staff, Skill, StaffSkill, Availability, Assignment | Rol y aceptación explícitos |
| Personajes | Character, CostumeUnit, CostumeCompatibility | Traje físico distinto de personaje |
| Equipos | EquipmentUnit, ConsumableStock, Vehicle, Maintenance | Capacidad nunca negativa |
| Calendario | ResourceReservation, Hold, ScheduleLock, ExternalBlock | No sobreasignación concurrente |
| Logística | RouteEstimate, Trip, TripStop, ResourceTransfer | Llegadas/salidas factibles |
| Documentos | TemplateVersion, Contract, SignatureEvidence, File | Documento firmado inmutable |
| Dinero | Invoice, InvoiceLine, PaymentAttempt, Payment, Allocation, Refund, Credit, Dispute | Centavos; referencias únicas |
| Operación | Checklist, Incident, StaffCompensation, Expense | Cambios trazables |
| Integraciones | ProviderEvent, OutboxJob, Message, CalendarMapping | Idempotencia y reintentos |
| Auditoría | AuditLog, ImportBatch, LegacyMapping | Autor, antes/después y origen |

Un cliente tiene muchos eventos; cada evento puede tener varios servicios y equipos. Un personaje tiene varias unidades de vestuario; el staff tiene múltiples habilidades. Un pago puede aplicarse por asignación contable, pero el MVP puede limitarlo a un solo evento para reducir errores. Nunca deduplicar un pago solo por importe y fecha.

Fechas técnicas en UTC y zona America/Chicago conservada para visualización/reglas locales; guardar también intención local del evento para detectar conversiones ambiguas. Validar cambios de horario de verano. Importes en USD enteros; migraciones explícitas y exportación completa de IDs.

### Contrato API inicial a documentar, no a asumir implementado

| Operación | Entrada mínima | Resultado |
|---|---|---|
| Consultar catálogo | idioma y fecha | variantes vigentes |
| Crear lead | contacto, evento, consentimiento | lead_id y seguimiento |
| Estimar cotización | selección + dirección/ZIP | líneas, revisión y pendiente/definitiva |
| Consultar disponibilidad | servicio, recursos, dirección, ventana | opciones y motivo de revisión |
| Crear hold | cotización vigente + slot | hold_id, vencimiento, versión |
| Iniciar pago | hold/reserva + propósito + idempotency key | sesión de cobro |
| Consultar portal | sesión autenticada | datos autorizados del evento |
| Registrar firma | contrato vigente + evidencia | aceptación y documento |
| Verificar Zelle | pago pendiente + referencia | movimiento auditado |
| Solicitar cambio | evento y modificación | solicitud/diferencias |
| Asignar recursos | evento, recursos y versión | asignación o conflicto |
| Recibir webhook | cuerpo original + firma | recepción durable |

Usar control de versiones para detectar ediciones simultáneas. Un conflicto devuelve error claro y obliga a refrescar; no sobrescribe cambios de otro administrador. Especificar autenticación, límites, permisos, códigos de error y esquema antes de que Codex y Claude construyan clientes separados.

## 16. Seguridad, confiabilidad y operación del software

Roles iniciales: propietarios/administradores César y Maybeli. Añadir coordinador, finanzas y staff solo al necesitarlos; clientes limitados a sus propios eventos. Autorización en servidor para cada documento, pago y archivo, no solo ocultar botones.

MFA para administradores; sesiones seguras; enlaces de cliente aleatorios, revocables y con vencimiento; rate limiting para formularios/login; protección contra abuso; validación de archivos; tamaño/tipo limitado; documentos fuera de carpetas públicas. Los datos de niños deben limitarse a lo necesario para el evento, por ejemplo nombre de pila/edad aproximada, sin perfiles infantiles públicos.

Auditar cambios de precio, dirección, fechas, recursos, pagos, descuentos, permisos y documentos. No registrar tokens ni datos de tarjeta. Minimizar datos personales en logs. Separar consentimientos de fotos y marketing del contrato de servicio cuando corresponda.

Respaldos cifrados, retención definida y restauración ensayada; objetivo inicial propuesto de pérdida máxima de datos de una hora y recuperación en cuatro horas, sujeto a capacidades/costo. Si el hosting no lo soporta, documentar la brecha y ajustar arquitectura u objetivo antes del lanzamiento.

Alertas por webhook fallido, jobs atrasados, rutas no disponibles, documentos no generados, sincronización vencida y cobro sin reserva confirmada. Manual operativo para recuperar pagos, suspender checkout sin cerrar la web, reconciliar una incidencia y restaurar backups.

La IA puede ayudar a redactar, resumir o sugerir paquetes en una fase futura. Precio, disponibilidad, saldo, reembolsos y firma deben depender de reglas verificables y estados persistidos, no de respuestas generadas.

## 17. Migración desde HoneyBook

1. Inventariar formatos/exportaciones realmente disponibles: clientes, proyectos/eventos, archivos, contratos firmados, facturas, pagos, saldos, notas y fechas. «250 archivados» no equivale necesariamente a 250 clientes.
2. Definir mapa de campos e IDs de origen. Deduplicar por evidencia y revisión, conservando contactos compartidos cuando existan.
3. Importar primero muestra anonimizada de eventos futuros, eventos pagados, pendientes, reprogramados y cancelados.
4. Mantener PDFs firmados originales y evidencia disponible; no regenerarlos como si se hubieran firmado en el nuevo CRM.
5. Identificar dónde se procesó cada pago; no importar credenciales ni cobrar otra vez al cliente.
6. Conciliar por evento: total, pagos recibidos, reembolsos, crédito y saldo; comparar totales globales y conteos.
7. Importar todos los eventos futuros como compromisos antes de habilitar disponibilidad pública. Los que no tengan recursos mapeados permanecen en revisión y bloquean capacidad conservadoramente.
8. Pilotar con un solo sistema autorizado para enviar cobros, contratos y mensajes por evento. HoneyBook permanece como respaldo/consulta; no dos sistemas enviando recordatorios y aceptando reservas del mismo evento.
9. Definir corte, resolver cambios ocurridos durante la importación y activar responsabilidad del CRM por cohortes identificadas.
10. Dar de baja HoneyBook solo tras conciliación completa, exportación de documentos, aceptación operativa y plan de recuperación. No se da de baja en este trabajo.

Rollback: desactivar nuevas reservas automáticas, conservar confirmaciones y movimientos ya realizados, operar manualmente sobre el estado conciliado y restaurar la interfaz anterior cuando corresponda. Restaurar una base vieja sin recuperar pagos posteriores produciría pérdidas de información; el runbook debe reconciliar proveedor y cambios desde el punto de recuperación.

## 18. Fases de implementación y condiciones de salida

El orden se ajusta respecto al esquema inicial de Claude: recursos y disponibilidad deben existir antes de vender disponibilidad real; el portal mínimo debe acompañar firma/pagos desde el primer piloto.

| Fase | Entregables | Condición para avanzar |
|---|---|---|
| 0. Auditoría y reglas | Inventario técnico, flujo HoneyBook, contrato completo, catálogo borrador, decisiones y entorno de prueba | Se sabe qué reutilizar y qué datos/reglas faltan |
| 1. Base y operación | Autenticación, permisos, clientes, eventos, catálogo, recursos, agenda manual y auditoría | Un evento completo puede administrarse con recursos reales |
| 2. Cotización y logística | Formulario conectado, precios/versiones, transporte, costos y cotizaciones | Totales y diferencias comprobables; no se pierden leads |
| 3. Disponibilidad | Recetas por paquete, habilidades, rutas, holds y prevención concurrente | Dos solicitudes no venden el mismo recurso |
| 4. Portal, firma y pagos | Portal mínimo, contrato versionado, Stripe test, Zelle, abonos, recibos y conciliación | Recorrido completo probado con fallos y recuperación |
| 5. Piloto y comunicaciones | Email, recordatorios esenciales, importación de eventos futuros y piloto controlado | Operación concilia; mensajes/pagos no se duplican |
| 6. Staff y gestión avanzada | Portal staff, SMS, gastos, remuneraciones, inventario y reportes | Asignaciones aceptadas y costos reales trazables |
| 7. Autoservicio ampliado | Más variantes automáticas, cambios, upsells, WhatsApp y optimización | Cada servicio nuevo supera pruebas de recursos y logística |
| 8. Migración final | Archivo histórico, capacitación y transición definitiva | Datos completos, respaldos restaurables y aceptación de César |

Las fases son incrementos verificables, no promesas de fechas. Tras la fase 0, estimar esfuerzo por ticket y dependencias. No fijar un plazo de producción sin conocer código, importación y flujo contractual.

### Primera versión realmente utilizable

Debe incluir leads, catálogo, cotizaciones, agenda con recursos, validación de viaje, portal mínimo, contrato/firma, pagos y abonos, Zelle verificado, correo, auditoría y backups. Puede operar con confirmación administrativa mientras el motor madura. Si no puede validar un paquete de forma completa, muestra «requiere revisión».

Puede esperar: app nativa, optimización automática de todas las rutas, chatbot vendedor, campañas de marketing, seguimiento GPS continuo, programa de fidelidad, WhatsApp completo y payroll fiscal. Los permisos y seguridad de pagos no esperan.

## 19. Backlog inicial para Codex y Claude Code

| ID | Tarea | Dependencia | Evidencia de finalización |
|---|---|---|---|
| DISC-01 | Auditar repositorio/hosting/integraciones | Acceso de lectura | Inventario con archivos y decisiones justificadas |
| BIZ-01 | Consolidar catálogo y dotaciones | DISC-01 + datos Waoo | Variantes y recursos aprobados o en borrador |
| BIZ-02 | Definir política monetaria/transporte | BIZ-01 | Casos de cálculo y excepciones documentadas |
| ARCH-01 | Elegir stack y agenda maestra | DISC-01 | ADR con alternativas y costo operativo |
| DATA-01 | Esquema/migraciones/fixtures | ARCH-01 | Base reproducible con datos ficticios |
| AUTH-01 | Roles, sesiones y permisos | DATA-01 | Cliente/staff no acceden a otros datos |
| OPS-01 | Catálogo, recursos y eventos manuales | AUTH-01 | Evento con personajes, personas y equipos |
| LEAD-01 | Conectar formulario actual | AUTH-01 | Lead persistido y error recuperable |
| QUOTE-01 | Motor de cotización versionada | BIZ-02 + DATA-01 | Totales exactos y cambios trazables |
| ROUTE-01 | Adaptar Maps y transporte | DISC-01 + BIZ-02 | Dirección exacta y fallo controlado |
| AVAIL-01 | Buscar combinaciones y trayectos | OPS-01 + ROUTE-01 | Casos de roles compartidos y buffers |
| HOLD-01 | Bloqueos/expiración/concurrencia | AVAIL-01 | Una sola confirmación para capacidad única |
| PAY-01 | Stripe test y ledger | QUOTE-01 + HOLD-01 | Cobros, webhook repetido y conciliación |
| PAY-02 | Zelle/abonos/sobrepagos | PAY-01 | Saldo real y verificación auditada |
| DOC-01 | Plantilla, PDF y firma | Contrato completo + DATA-01 | Documento y evidencia inmutables |
| PORTAL-01 | Cotización, documentos y pagos | AUTH-01 + PAY-02 + DOC-01 | Recorrido móvil EN/ES completo |
| JOBS-01 | Cola, outbox y alertas | DATA-01 | Reintentos sin efectos duplicados |
| MSG-01 | Correo y avisos esenciales | JOBS-01 + estados | Pago/cancelación anulan avisos obsoletos |
| CAL-01 | Calendario espejo | OPS-01 + JOBS-01 | Reintentos no duplican eventos |
| IMPORT-01 | Importar muestra y eventos futuros | DATA-01 + OPS-01 | Conteos, archivos y saldos conciliados |
| PILOT-01 | Piloto y runbook | Flujo completo + IMPORT-01 | Aprobación por casos reales controlados |
| STAFF-01 | Disponibilidad y aceptación móvil | OPS-01 + AUTH-01 | Rechazo dispara reasignación/alerta |
| COST-01 | Gastos, liquidaciones y margen | PAY-02 + OPS-01 | Reporte coincide con datos de prueba |

Cada ticket debe contener alcance, no-alcance, dueño de implementación, revisor, archivos implicados, criterios, pruebas y rollback. No empezar tickets dependientes con interfaces imaginadas.

## 20. Pruebas de aceptación obligatorias

Estas pruebas corresponden a lógica crítica, no a duplicar detalles internos de implementación.

| Caso | Resultado esperado |
|---|---|
| Paquete $600 + transporte $75, sin otros conceptos | Total $675; depósito $337.50 |
| Total $455 y pago $227.05 | Saldo $227.95; diferencia de depósito visible |
| Abono adicional $100 sobre ese saldo | Saldo $127.95; etiqueta ya no afirma 50% exacto |
| Pago superior al saldo | Sobrepago registrado; no desaparece dinero |
| Dos pestañas pagan el saldo | No duplicar cobro/abono; resolver carrera con Zelle |
| Webhook repetido o fuera de orden | Un movimiento por transacción; estado correcto |
| Cliente abre success sin pago completado | No confirmar reserva por esa navegación |
| Pago tardío con hold vencido | Revalidar; incidencia si no cabe |
| Dos clientes compiten por único traje | Solo uno obtiene capacidad confirmada |
| Una persona apta para dos roles simultáneos | No contarla dos veces |
| Personal libre, sin traje/equipo | No ofrecer reserva automática |
| Dos eventos consecutivos sin margen de viaje | Rechazar combinación y ofrecer alternativa |
| Reprogramar rompe viaje del evento posterior | No guardar hasta resolver todos los afectados |
| Maps falla o devuelve ruta incompleta | Revisión manual; nunca tarifa cero por defecto |
| Staff rechaza o equipo entra a mantenimiento | Alertar y retirar capacidad comprometida afectada |
| Cambio de dirección/extra en evento reservado | Nueva validación y aceptación de diferencias |
| Música y Juegos sin personaje | Contrato válido sin campo obligatorio de personaje |
| Magic con personaje sin seleccionar | Bloquear documento definitivo/checkout según flujo |
| Actualización de precio o política | No altera acuerdos históricos |
| PDF firmado y edición posterior | Original intacto; adenda/nueva versión |
| Cancelación/pago antes de recordatorio | No enviar aviso obsoleto |
| Cliente cambia ID en URL | No obtiene datos de otro cliente |
| Staff intenta ver finanzas generales | Acceso denegado en servidor |
| Hora local durante cambio DST | Conversión explícita; no desplazar evento una hora |
| Importación repetida de HoneyBook | No duplicar eventos, pagos o documentos |
| Restauración de backup | Recuperación demostrada y conciliación posterior |

Probar concurrencia en base de datos real de staging, no solo funciones simuladas. End-to-end: cliente cotiza → hold → contrato según flujo → pago test → reserva → asignación → firma → abono → preparación → cierre, y recorridos alternativos Zelle/cancelación/reprogramación.

## 21. Coordinación entre Codex y Claude Code

Un repositorio y una especificación compartida. Codex y Claude no comparten memoria automáticamente: se coordinan mediante archivos, tickets, commits y revisión.

Propuesta inicial: Codex mantiene integración, datos, dinero, disponibilidad y pruebas críticas; Claude Code desarrolla interfaz, formularios, portal, vistas operativas y comunicaciones con los contratos API acordados. Pueden intercambiar funciones según contexto, sin convertir esto en dos arquitecturas separadas.

Cada ticket tiene un implementador; el otro revisa diff y criterios. No editar mismos archivos o migraciones en paralelo. Separar ramas/worktrees si trabajan simultáneamente y definir quién integra. Ningún agente fusiona contratos de API o migraciones incompatibles silenciosamente.

Documentación a mantener dentro del repositorio cuando se inicie implementación: PROJECT_PLAN.md; AGENTS.md; DECISIONS.md; BUSINESS_RULES.md; DATA_MODEL.md; API_CONTRACT.md; BACKLOG.md; TEST_PLAN.md; RUNBOOK.md; CHANGELOG.md. El AGENTS.md previo sigue aplicando; no fue modificado en esta entrega.

Preservar la regla anterior de asignar tareas simples a recursos económicos y reservar análisis más intenso para arquitectura, dinero, concurrencia y revisión. No cambiar modelos o herramientas sin considerar el entorno y preferencias de César.

### Prompt inicial listo para entregar a Codex

> Estamos continuando el CRM de Waoo Party integrado con waooparty.com. Lee este PROJECT_PLAN.md v2 y el AGENTS.md existente antes de trabajar. El objetivo es sustituir progresivamente HoneyBook con cotizaciones, disponibilidad real por personal/personajes/trajes/equipos/vehículos y traslados, portal de cliente, contratos, firma, Stripe, Zelle verificado, depósitos y abonos, operación y reportes. César y Maybeli son los administradores iniciales. Usa la web actual y su integración de Maps como punto de partida. No rehagas el sitio ni asumas el stack. Empieza solo por la fase 0: inspecciona repositorio y configuración disponible, identifica lo que ya funciona, registra discrepancias y entrega auditoría, arquitectura propuesta y backlog con criterios. Conserva precios y políticas como borradores cuando falte validación. No publiques, no cambies DNS/hosting live, no envíes mensajes a clientes y no hagas cobros reales. Usa datos ficticios y Stripe test. Coordina con Claude Code por tickets con un implementador y un revisor; documenta interfaces y evita editar los mismos archivos. Al terminar la fase, reporta evidencia, decisiones y bloqueos concretos para el siguiente incremento.

## 22. Información faltante, sin volver a preguntar lo resuelto

| Prioridad | Insumo | Para qué se necesita |
|---|---|---|
| Inmediata | Repositorio o ZIP vigente de la web | Auditar stack, formulario, calendario y Routes API |
| Inmediata | Contrato completo y ejemplos anonimizados de cotización/invoice | Plantillas y reglas contractuales exactas |
| Inmediata | Staff activo, habilidades y disponibilidad; unidades reales de vestuario/equipo | Capacidad verificable |
| Inmediata | Cantidad real de personas/roles y tiempos por paquete | Recetas del motor |
| Inmediata | Dirección base y fórmula vigente de transporte | Cotización y rutas |
| Antes de cobros | Política final de vencimientos, tolerancias, impuestos y cargos por método | Cálculos correctos |
| Antes de comunicaciones | Estado de Twilio y remitente autorizado | Envío confiable y consentimientos |
| Antes de piloto | Exportación/inventario HoneyBook y calendario actual | No perder reservas existentes |
| Antes de producción | Validación de hosting, backups y responsables de soporte | Operación sostenible |

No hace falta preguntar de nuevo si ya existe Stripe, si Zelle es principal, quién administra, si hay staff que asignar o si se quiere trabajar por fases: eso ya está definido. No enviar secretos por chat; se configuran en el entorno correspondiente cuando comience la integración.

## 23. Costos y decisión de reemplazo

El rango de $10–$35/mes mencionado en las capturas no es un presupuesto validado. El costo real incluye hosting/base de datos, almacenamiento y respaldos, Maps, correo, Twilio/número/registros, firma si se usa proveedor, monitoreo, comisiones Stripe y mantenimiento. Algunos pueden estar incluidos en contratos existentes; otros dependen de uso y proveedor.

Preparar después de la auditoría tres escenarios de uso —actual de aproximadamente 40 eventos/mes, crecimiento y temporada alta— con volumen de leads, consultas de disponibilidad, mensajes, documentos y pagos. Fórmula: costos fijos + consumo de APIs + procesamiento de pagos + soporte/mantenimiento. Comparar contra HoneyBook considerando funciones, trabajo ahorrado y confiabilidad, no solo mensualidad.

No comprar servicios ni cancelar HoneyBook para completar el plan. No prometer ahorro antes de calcular el costo total.

## 24. Fuentes y alcance de la revisión

Consultadas el 15 de octubre de 2026. Enlaces para que Codex/Claude revaliden documentación durante implementación:

- [S1 — Web Waoo Party](https://waooparty.com/), [Packages](https://waooparty.com/packages), [Contact](https://waooparty.com/contact), [Travel fee](https://waooparty.com/travel-fee), [Terms](https://waooparty.com/terms). Inspección pública de contenido; no auditoría del backend.
- [S2 — Stripe webhooks](https://docs.stripe.com/webhooks). Firmas, procesamiento y eventos; verificar versión API del proyecto.
- [S3 — Stripe payment methods](https://docs.stripe.com/payments/payment-methods/overview) y [pagos de clientes existentes](https://docs.stripe.com/payments/existing-customers?platform=web&ui=stripe-hosted). Métodos y confirmación por backend; validar disponibilidad en la cuenta.
- [S4 — Google Routes API](https://developers.google.com/maps/documentation/routes/) y [Compute Route Matrix](https://developers.google.com/maps/documentation/routes/reference/rest/v2/TopLevel/computeRouteMatrix). Rutas, matrices y estimaciones de tráfico; no son un scheduler de recursos.
- [S5 — Hostinger Node.js](https://www.hostinger.com/support/node-js-hosting-options-at-hostinger/) y [cron jobs](https://www.hostinger.com/support/1583765-how-many-cron-jobs-can-you-set-up-in-hostinger/). Capacidad pública general, pendiente de cuenta/plan efectivo.
- [S6 — Twilio A2P 10DLC](https://www.twilio.com/docs/messaging/api/usapptoperson-resource) y [consentimiento activo](https://www.twilio.com/docs/api/errors/30925). Registro y opt-in antes de automatizar SMS.
- [S7 — Google Calendar sync](https://developers.google.com/workspace/calendar/api/guides/sync). Sincronización incremental; la autoridad de reservas es una decisión del diseño.
- [S8 — Visa merchant surcharge Q&A](https://usa.visa.com/content/dam/VCOM/global/support-legal/documents/merchant-surcharging-qa-for-web.pdf). Distingue crédito de débito/prepago; no valida por sí solo toda la política del comercio.
- [S9 — Fuentes aportadas por César] Nueve capturas de conversación con Claude Code, entre 8:21:48 y 8:23:37 AM del 15 de octubre. Incluyen decisiones, fragmentos contractuales y tareas pendientes.
- [S10 — Continuidad] PROJECT_PLAN.md y AGENTS.md creados el 14 de octubre; catálogo/contexto de Waoo Party compartido previamente por César. Datos históricos requieren cotejo antes de convertirse en oferta activa.

Las secciones de requisitos, arquitectura, pruebas y backlog son propuestas específicas para Waoo Party. No representan funciones ya construidas ni certificación legal/contable de las políticas. La siguiente entrega del equipo de desarrollo debe ser la auditoría de fase 0, con evidencia del código y decisiones técnicas concretas.

## Registro de cambios

- 2026-10-14: plan inicial de CRM y guía AGENTS.md; inspección de repositorio pendiente.
- 2026-10-15: ampliación v2 tras revisar nueve capturas, web pública y documentación oficial. Se detallan portal, recursos, calendario, concurrencia, ledger, cambios, contratos, migración, pruebas, backlog y coordinación. Se preservan Stripe, Zelle principal, importes exactos, campos de personaje condicionales y desarrollo por fases. Se distinguen propuestas de decisiones aprobadas y se documentan discrepancias sin modificar producción.
