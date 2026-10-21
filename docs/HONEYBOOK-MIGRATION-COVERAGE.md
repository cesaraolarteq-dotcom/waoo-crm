# Migración de información HoneyBook — cobertura pendiente

César autoriza copiar información útil el 21 de octubre de 2026. HoneyBook permanece intacto. Destino previamente acordado: crm-test; verificar con Claude el estado actual antes de ejecutar. Esta tabla no acredita extracción, importación o acceso que no se haya realizado.

| Categoría | Destino | Estado de esta entrega |
| --- | --- | --- |
| Reservas futuras y pagos de origen | IMPORT-01 y resincronización | Herramientas existentes; verificar cobertura real privadamente |
| Clientas y fiestas pasadas | IMPORT-HISTORY | Reutilizar intake A y revisar borrador B1; commit/cierre siguen pendientes |
| Contactos sin proyecto o sin fecha | Sin cliente ficticio; revisión privada | Inventariar, no forzar el CSV de fiestas |
| Formularios, campos personalizados y preferencias | Información por organizar | Adaptador supplement-v1 implementado; sin archivo real cargado |
| Notas y mensajes históricos | Información por organizar | Texto de origen, nunca consentimiento o mensaje nuevo |
| Referencias de documentos | Información por organizar | Solo referencia textual; no significa que se copió el archivo |
| Contratos firmados y anexos originales | Archivo privado original | Adaptador de binarios pendiente; no recrear firma ni reemplazar original |
| Facturas, desglose y evidencias de pagos/devoluciones | Evidencia de origen / puente financiero | No crear recibo bancario verificado a partir del export |
| Preferencias de contacto, bajas y consentimientos | Evidencia de origen pendiente de revisión | Nunca inferir opt-in ni revertir supresión |

## Para Claude

1. Mantener solo lectura en HoneyBook. Confirmar qué herramienta realmente tiene acceso; Codex no tiene un conector disponible en esta sesión.
2. Preparar export privado fuera de git, con fecha de extracción y alcance. Mantener formatos existentes para eventos e historial. Para textos adicionales usar docs/HONEYBOOK-SUPPLEMENT-API.md. No truncar silenciosamente valores: reportar excedentes como pendientes; archivos originales requieren el adaptador posterior.
3. Compartir únicamente conteos por categoría: disponible, copiado, duplicado, pendiente y no disponible. No adjuntar valores de clientas en HANDOFF, consola, git o logs.
4. Verificar en crm-test los proyectos ya copiados y cambios desde el último export. No volver a crear eventos/clientas por nombre; usar las identidades y revisiones de los importadores existentes.
5. Revisar esta rama antes de integrar. Activar la nueva bandera solo en el entorno autorizado. El comando adicional devuelve únicamente conteos; copia atómica sin efectos de agenda, dinero, correo, SMS ni enlaces.
6. Ustedes deciden después el destino funcional de lo que aparezca en «Información por organizar». Conservar evidencia original y su procedencia al elaborar cualquier adaptador nuevo.

No ejecutar scripts desde celdas/textos. No abrir referencias remotas automáticamente. No guardar credenciales ni datos de tarjetas. No confundir copiar información con pasar cobros/avisos al CRM; el traspaso exige su conciliación y acción específica.

## Doble verificación exigida por César — 2026-10-21

La autorización de copiar no equivale a aceptar la migración. No declarar completa hasta cerrar las dos revisiones independientes:

- **Revisión 1, importación:** validar cada registro contra el archivo de origen congelado. Registrar privadamente versión/perfil, fecha de extracción, hash del archivo y cobertura por identidad fuente; importados, repetidos, excluidos con motivo y pendientes deben explicar el total. No usar solo sumas globales para certificar datos.
- **Revisión 2, independiente:** Claude y Codex comparan origen congelado y lectura del destino, mediante caminos independientes del mapeo que escribió los datos. Cada uno registra por separado SHA de código, alcance, conteos, fecha y discrepancias. Si alguno no tiene acceso al origen o al destino, su revisión sigue pendiente; no se sustituye por aprobar el código ni por copiar el dictamen del otro.
- Comparar por registro todos los campos trasladados y su procedencia: identidad HoneyBook, vínculo con clienta, contacto, fecha/horario/zona y precisión, paquete/personajes/vestuario, lugar, totales/descuentos/transporte, pagos y saldo. Distinguir vacío de cero, fuente de banco verificado y evidencia de firma de firma recreada. Totales por moneda y conteos por categoría como control adicional.
- Verificar adjuntos copiados por hash de bytes, tamaño y accesibilidad privada. Una referencia o URL no cuenta como documento copiado. Notas/formularios/mensajes adicionales se comparan con el texto completo; ninguna truncación silenciosa. Información no disponible permanece declarada como tal.
- Diferencias requieren corregir o resolver explícitamente con evidencia; conservar versiones originales y la decisión. Revisar duplicados y ausencias en ambos sentidos, no solo las filas presentes en destino. Si HoneyBook cambió después de la extracción, obtener un delta de solo lectura y volver a revisar el alcance afectado.
- Al cerrar: cero diferencias sin resolver en el alcance copiado, cobertura completa explicada y dos firmas de revisión técnicas. Si hay categorías inaccesibles o pendientes, informar esas limitaciones y no afirmar «100 % de todo HoneyBook». No usar información real en git/logs: HANDOFF solo contiene evidencia agregada.

El cambio de responsable de cobros/avisos continúa siendo una acción aparte. Esta doble revisión no lo activa.
