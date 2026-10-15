# Firma electrónica — comparación preliminar D4

2026-10-15. Estado: **no seleccionar todavía un proveedor**. El dueño pidió comparación, no aprobó firma propia.

## Alcance y evidencia

Se intentó consultar las páginas oficiales siguientes por HTTPS. Todas respondieron con bloqueo del proxy `403 Forbidden` antes de obtener contenido; no se verificaron tarifas actuales. Se guardaron dominios de documentación en el borrador de red del entorno para revisión. Guardar el borrador no habilita la red de esta máquina.

- https://www.docusign.com/products/electronic-signature/plans-and-pricing/api
- https://developers.docusign.com/docs/esign-rest-api/esign101/api-plans/
- https://sign.dropbox.com/products/dropbox-sign-api/pricing
- https://boldsign.com/electronic-signature-api-pricing/

## Comparación para completar con evidencia oficial

| Opción | Ventaja a evaluar | Riesgo/costo a verificar | Estado |
| --- | --- | --- | --- |
| DocuSign API | Integración de firma y expediente de evidencia de un proveedor dedicado | Plan API real, envelopes incluidos, excedentes, modalidad integrada, exportación y sandbox | Precio/capacidades de plan no verificados |
| Dropbox Sign API | API y experiencia integrada a evaluar | Plan API frente a plan web, embedded, cuota de solicitudes, auditoría/exportación | No verificado |
| BoldSign API | Alternativa por consumo a evaluar | Mínimo mensual, consumo, features, expediente y retención/exportación | No verificado |
| Firma propia | Control del recorrido y ausencia de tarifa de proveedor por sobre si no se contrata uno | Implementación, identidad, consentimiento, evidencia, PDFs, almacenamiento, respaldo, soporte y revisión contractual | Costo total no calculado; no existe implementación |

Los USD 50–75, 75 y 0.75/sobre mencionados en HANDOFF son referencias anteriores sin verificar, **no cotizaciones para decidir ni presupuesto vigente**. La firma propia no se presenta como gratis. La afirmación sobre cómo firma HoneyBook no fue validada y no demuestra equivalencia de evidencia.

Comparar en la misma unidad: contrato enviado/completado, firmantes, reenvíos, anulaciones y adendas. Confirmar facturación anual/mensual, moneda, impuestos, límites, sandbox y precios de exceso. Verificar si un plan de uso humano permite API; no suponerlo. Exportar PDF final y certificado/evidencia sin depender indefinidamente de la cuenta del proveedor.

## Escenarios de costo (volúmenes de planificación, no pronóstico aprobado)

| Escenario | Contratos por mes | Costo a obtener |
| --- | ---: | --- |
| Actual | 40 | Fijo API + exceso sobre cuota + almacenamiento/backup + implementación/mantenimiento |
| Crecimiento ilustrativo | 80 | Misma fórmula con volumen y adendas revisados |
| Pico ilustrativo | 120 | Capacidad y excedentes; no contratar plan de límite 40 sin alternativa |

Total operativo CRM = hosting/base/worker + documentos/backups + Maps + email + SMS/A2P + firma + monitoreo/mantenimiento. Comisiones de pagos se modelan aparte con montos y número de transacciones. No hay datos suficientes para afirmar ahorro frente a HoneyBook.

**Recomendación provisional:** mantener interfaz `SignatureProvider`, pero seleccionar solo tras obtener cotizaciones comparables y validar evidencia requerida con quien revise el contrato. El adaptador debe conservar IDs y evidencia original por proveedor; no promete equivalencia ni migración automática de firmas históricas. Un pequeño prototipo futuro de firma con datos ficticios puede comprobar exportación y recuperación; no está implementado ni probado hoy.

Para cerrar D4/ARCH-01: acceso a fuentes/cotizaciones, matriz de requisitos cubierta, costo por escenario y decisión de César. No dar por cerrada esta decisión con la tabla presente.
