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

Estaciones, foam, spa, photobooth y demás variantes permanecen fuera de automatización hasta documentar cantidades, duración por recurso, montaje/desmontaje, consumibles y logística. No afirmar que un personaje o traje existe por aparecer en un catálogo. No se ha recibido inventario real de personas/trajes/equipos/vehículos.

## Transporte: lo que implementa el checkout de la web

Fuente `travel-fee-config.php:15–45`, `travel-fee-lib.php:31–43`, `travel-fee-zips.php`. No se ha cotejado con Hostinger live.

- Origen ZIP 77493. Distancia de ida, con costo por tramos multiplicado por 2 para ida/vuelta.
- Base USD 20 hasta 10 millas de ida; tramo 10–40 a USD 0.35/milla; 40–55 a USD 2/milla; superior a 55 a USD 1.40/milla. Cada tarifa aplica solo a su tramo.
- Sumar dos veces el peaje de ida; redondear resultado al múltiplo de USD 5 más cercano. Más de 160 millas ida/vuelta: fuera de área, salvo ZIP con excepción que se resuelve antes.
- Excepciones: 77520/77521 = 180; 77433 = 40; 77355 = 80; 77380/77013 = 100; 77573 = 125.
- Rutas: mejor ruta de Google para menos de 30 millas ida/vuelta; en distancias mayores, la ruta con peajes más rápida entre las devueltas, o la más rápida si no hay ruta con peajes. TRAFFIC_UNAWARE. Peaje sin importe: supuesto de USD 5 por ida. Caché de ruta 30 días por ZIP; revisar condiciones de almacenamiento del proveedor antes de reutilizar diseño.

Resultados locales del código, sin peajes: 10 mi → 20; 40 → 40 (bruto 41); 50 → 80 (bruto 81); 55 → 100 (bruto 101); 80 → 170 (bruto 171). Con 50 mi y USD 5 de peaje por ida → 90. Son pruebas de la implementación, no precios nuevos aprobados para eventos.

**Diferencia con v2 §5:** la tabla histórica 0.50/0.65/1.00/1.50 no es esta fórmula. El registro de la web atribuye la fórmula por tramos a aprobación del dueño del 14 de octubre. Conservar evidencia y confirmar versión aplicada en producción; no reemplazarla por la tabla histórica. Para el CRM faltan dirección exacta, regla para múltiples vehículos, límites/errores y autorización de ajustes. Un ZIP informativo no valida capacidad ni tiempo de llegada.
