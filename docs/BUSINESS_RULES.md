# Reglas de negocio — borrador de auditoría

2026-10-15. Las decisiones D1–D6 del dueño prevalecen sobre ejemplos históricos. Esta lista no activa precios ni altera contratos.

## Decisiones vigentes

D1: A manual antes de B automático. D2: firma y depósito en el mismo recorrido; confirmación con ambos y capacidad validada; Zelle pendiente hasta verificación. D3: Stripe cobra el importe exacto; Zelle registra lo realmente recibido y cualquier corrección requiere motivo/auditoría. Sin tolerancia automática. D4: comparar firma antes de elegir. D5: web en repositorio privado. D6: staff, trajes, equipos y vehículos desde el inicio con asignación manual.

El depósito conocido es 50% de paquete más costo de transporte; cuotas, extras, impuestos y descuentos deben quedar expresamente definidos en la versión de cotización. Importes del CRM en centavos USD. Diferencias, abonos y sobrepagos no se borran. El saldo real sustituye etiquetas porcentuales incorrectas. Recargo del 3% pendiente de validación por método/procesador; no activarlo por extrapolación de la web. No editar términos 1–10 sin aprobación/versionado.

## Catálogo inicial observado (no receta operativa aprobada)

Fuente: `waoo-website` en commit `5afc001`, `contact.html:1074`, `packages.html` y CLAUDE.md.

| Variante | Base USD + costo de transporte | Duración | Personajes | Dotación publicada / pendiente |
| --- | ---: | --- | --- | --- |
| Star | 275 | 30 min | 1 | Intérprete, tiempos de preparación y apoyo por confirmar |
| Magic | 375 | 1 h | 1 | Intérprete/apoyo; versión 3 personajes 675 en contexto, no inferir dotación |
| Música y Juegos | 525 | 2 h | No | Web: 1 entertainer + 2 hosts + 1 DJ; validar personas distintas/funciones |
| Música y Juegos 2.0 | 600 | 2 h | 1 | Web: 1 entertainer + 2 hosts; versión 3 personajes 900 en contexto |
| Waoo | 800 | 3 h | 1 | Web: 1 entertainer + 2 hosts + 1 DJ, face painting; validar artista y solapamientos |
| K-Pop | Desde 375 | Depende de variante | 1–3 | Contexto: 375/525/675; Huntrix Live 900, Elite 1200; recetas pendientes |
| Solo personajes | Desde 250 | Aproximadamente 45 min | 1–2 | Contexto: 250/400; sin show; dotación/traslado pendiente |

Estaciones, foam, spa, photobooth y demás variantes permanecen fuera de automatización hasta documentar cantidades, duración por recurso, montaje/desmontaje, consumibles y logística. No afirmar que un personaje o traje existe por aparecer en un catálogo. El inventario recibido es parcial: ver datos confirmados al final; faltan fichas completas de personas/trajes/equipos/vehículos.

## Transporte: lo que implementa el checkout de la web

Fuente `travel-fee-config.php:15–45`, `travel-fee-lib.php:31–43`, `travel-fee-zips.php`. No se ha cotejado con Hostinger live.

- Origen ZIP 77493. Distancia de ida, con costo por tramos multiplicado por 2 para ida/vuelta.
- Base USD 20 hasta 10 millas de ida; tramo 10–40 a USD 0.35/milla; 40–55 a USD 2/milla; superior a 55 a USD 1.40/milla. Cada tarifa aplica solo a su tramo.
- Sumar dos veces el peaje de ida; redondear resultado al múltiplo de USD 5 más cercano. Más de 160 millas ida/vuelta: fuera de área, salvo ZIP con excepción que se resuelve antes.
- Excepciones: 77520/77521 = 180; 77433 = 40; 77355 = 80; 77380/77013 = 100; 77573 = 125.
- Rutas: mejor ruta de Google para menos de 30 millas ida/vuelta; en distancias mayores, la ruta con peajes más rápida entre las devueltas, o la más rápida si no hay ruta con peajes. TRAFFIC_UNAWARE. Peaje sin importe: supuesto de USD 5 por ida. Caché de ruta 30 días por ZIP; revisar condiciones de almacenamiento del proveedor antes de reutilizar diseño.

Resultados locales del código, sin peajes: 10 mi → 20; 40 → 40 (bruto 41); 50 → 80 (bruto 81); 55 → 100 (bruto 101); 80 → 170 (bruto 171). Con 50 mi y USD 5 de peaje por ida → 90. Son pruebas de la implementación, no precios nuevos aprobados para eventos.

**Diferencia con v2 §5:** la tabla histórica 0.50/0.65/1.00/1.50 no es esta fórmula. El registro de la web atribuye la fórmula por tramos a aprobación del dueño del 14 de octubre. Conservar evidencia y confirmar versión aplicada en producción; no reemplazarla por la tabla histórica. Para el CRM faltan dirección exacta, regla para múltiples vehículos, límites/errores y autorización de ajustes. Un ZIP informativo no valida capacidad ni tiempo de llegada.

## Validación adicional de límites (2026-10-15)

Se ejecutaron 12 casos de la función PHP existente sin red ni cambios de código: distancias 9.99/10/10.01, 39.99/40/40.01, 54.99/55/55.01 y 79.99/80/80.01 millas de ida. Los brutos coincidieron con 20/20/20.007, 40.993/41/41.04, 100.96/101/101.028 y 170.972/171/171.028 USD; redondeados a 20, 40, 100 y 170 respectivamente. Los 12 pasaron. La función de precio no aplica el límite de servicio: `waoo_tf_estimate` rechaza más de 160 millas ida/vuelta después de obtener ruta, salvo excepción ZIP resuelta antes. Esa ruta real sigue sin probarse por ausencia de binding Google.

Dato nuevo del dueño, registrado por Claude en BACKLOG: Mickey y Rumi tienen dos unidades de traje. No significa que haya dos intérpretes disponibles. Inventario completo pendiente. Para el depósito de totales con centavos impares debe definirse redondeo antes de implementar cobros.

## Requisito confirmado por el dueño: inventario operativo

César confirmó que debe poder controlar trajes y staff con una experiencia de inventario que alimente la smart availability del CRM. D6 incluye administración de unidades físicas y personas, con estado, habilidades/compatibilidades, horarios y asignaciones. La disponibilidad se calcula por fecha/intervalo; no equivale a un interruptor global “libre”. Propuesta técnica y aceptación en RESERVATION_PROTOCOL. Primero administración/asignación manual en A; búsqueda automática en B. No se cambia la aprobación por fases.

## Datos confirmados: habilidades, conductores, grupos y speakers

Fuente: César, conversación del 2026-10-15.

- El staff tiene habilidades diferentes, como interpretar personajes, pintar caritas y animar. Una persona puede tener varias habilidades; no se presupone que todos pueden cubrir todos los roles.
- Maybeli puede conducir y llevar un grupo; César puede conducir y llevar otro. Cuando hay más grupos, algunos integrantes del staff pueden realizar el transporte y también participar en la fiesta. No se ha confirmado cuántos vehículos hay, sus capacidades ni qué miembros concretos conducen.
- Actualmente hay **4 speakers**. El dueño los identifica como capacidad para hasta cuatro fiestas simultáneas con la dotación habitual. Deben registrarse como cuatro unidades físicas, sin inventar modelos, ubicaciones ni estado de cada una. No es un límite de cuatro fiestas por día.
- Se pueden cubrir más eventos antes o después si da tiempo para mover personal, trajes y equipos. El cálculo incluye fin de actividad de cada recurso, desmontaje/carga, viaje, margen y montaje/preparación del siguiente evento. No fijar tiempos sin datos aprobados.

Interpretación técnica propuesta: cuatro unidades permiten hasta cuatro eventos simultáneos **si cada uno requiere una unidad y las cuatro están disponibles**. Cada servicio debe declarar su cantidad y compatibilidad; una fiesta que necesite dos consume dos unidades. Personal, vestuario o transporte pueden reducir la capacidad conjunta. Los speakers se reutilizan cuando su itinerario lo permite, no apenas termina la hora anunciada de fiesta.

Conducir es una función de una persona por intervalo. Si participa como artista/animador durante la fiesta, no puede transportar otro grupo durante ese mismo intervalo. Si únicamente deja y recoge al grupo, puede tener otro recorrido compatible; modelar ambos viajes y verificar cómo vuelven las personas y equipos. No asumir que el conductor permanece o queda libre sin una asignación explícita. Separar vehículo, conductor, pasajeros y carga.

Pendientes para BIZ-01/ROUTE-01: conductores elegibles y disponibilidad, vehículos y capacidad de asientos/carga, pasajeros/equipos por grupo, puntos de recogida/retorno, cantidad/tipo de speaker por servicio y tiempos operativos. Estas ausencias no invalidan los datos confirmados ni autorizan inventar capacidad.

## Reglas confirmadas: última hora y tráfico previsto

Fuente: César, conversación del 2026-10-15.

- Las solicitudes para eventos a **menos de siete días** necesitan aprobación explícita de César o Maybeli, aunque la consulta de recursos indique capacidad. Motivo: preparación y disponibilidad efectiva del staff. La aprobación puede ser una acción sencilla, con revisión de la información necesaria. No confirmar automáticamente por recibir firma o pago.
- El CRM debe calcular distancias y duración entre las direcciones concretas de los eventos según la fecha y hora previstas del traslado, incluyendo diferencias entre días laborables y fines de semana. No usar tráfico actual del día de consulta para representar otro día.
- Añadir **al menos 30 minutos sobre el tiempo estimado de viaje**, contemplando breakdown, setup e imprevistos. Ejemplo del dueño: terminar a las 16:00, viaje de 28 minutos, ofrecer las 17:00. La suma mínima da 16:58; 17:00 es ejemplo de horario ofrecible, no aprobación de una grilla universal ni garantía para cualquier montaje.

Precisiones de diseño propuestas (no nuevas decisiones comerciales): separar tiempos de desmontaje/carga, montaje y margen residual de imprevistos por servicio. No interpretar los 30 minutos como margen exclusivamente vial además de setup/breakdown sin aprobación del dueño: su ejemplo los agrupa. El total extra nunca debe ser menor de 30 minutos y debe crecer si las operaciones necesarias y el margen previsto requieren más. Faltan duraciones reales por servicio; no asumir que todos caben en media hora.

Definición temporal candidata para la regla de última hora: comparar la hora actual del servidor con el inicio del evento, mostrando America/Chicago; umbral estricto de menos de 168 horas. Acordar antes de implementar si el dueño prefiere siete fechas de calendario en lugar de siete períodos de 24 horas (pueden diferir con cambio de horario). Un evento pasado se rechaza. Estar a siete días o más no elimina el resto de requisitos.

Una aprobación de última hora deja autor, fecha/hora y revisión aprobada del evento. No salta conflictos de recursos ni sustituye firma/depósito. Un cambio material posterior de horario, dirección, servicio o recursos exige revalidación y, si cambia lo aprobado, nueva revisión. Plazo de respuesta y duración de protección siguen en D7; no inventarlos.
