# HoneyBook: información por organizar — v1

2026-10-21. César autoriza copiar información útil sin modificar HoneyBook. Contrato antes del código. Esta entrega conserva datos adicionales; no reemplaza IMPORT-01 ni IMPORT-HISTORY, no activa el cambio de sistema y no acredita pagos, firmas o consentimiento.

## Entrada privada

Comando `waoo:honeybook:supplement /ruta/privada/archivo.json --owner=ID`. Solo local/testing/staging, bandera `WAOO_SANDBOX_HONEYBOOK_SUPPLEMENT` apagada por defecto, dueño activo con 2FA. Archivo regular UTF-8 JSON, máximo 2 MiB/500 registros, esquema cerrado:

```json
{"profile":"honeybook-supplement-v1","extracted_at":"2026-10-21T12:00:00Z","records":[{"source_type":"project","source_id":"synthetic-project-1","category":"form","field":"party_preferences","value":"María González prefiere actividades al aire libre."}]}
```

`source_type`: project/contact. `category`: note/form/message/document_reference/custom_field/other. Identificador hasta 200 caracteres, field hasta 120, value hasta 16000. Solo texto; no HTML ejecutable, adjuntos binarios, credenciales, tarjetas ni secretos. document_reference conserva una referencia textual, no descarga automáticamente archivos o URLs. Los PDF originales requieren un adaptador privado posterior; no presentarlos como ya copiados. Los mensajes históricos no son una autorización para contactar.

Se conserva cada valor con identidad de origen, categoría, campo y fecha de extracción cifrados juntos. Identidad y contenido tienen huellas versionadas; mismo registro y contenido es no-op incluso en otro archivo. Valor diferente crea otra evidencia, nunca sobrescribe la anterior. Extracción futura se rechaza. Validación completa antes de escribir; transacción atómica y auditoría solo con IDs/conteos. No nombres de archivo, valores, IDs de HoneyBook o errores internos en consola/logs. La tabla no permite actualizaciones/borrado mediante el modelo; rollback poblado se niega.

## Lectura

Pestaña de administración `/admin/honeybook-informacion`, «Información por organizar», solo dueño activo con 2FA y bandera. Lectura paginada 25 registros, texto escapado, sin enlaces externos activos ni descargas. Revalida acceso en Livewire. Datos privados no-store. No acceso de staff/clientas. Cada entrada sigue pendiente de acordar dónde utilizarla; no se infiere una relación con clientes/eventos por nombre.

## Cobertura real

Mantener separados reservas futuras, historial, contactos sin fiesta, formularios/notas, mensajes, originales firmados y otros adjuntos. Contar disponible/copiado/pendiente/no disponible por categoría; no declarar «todo migrado» con solo un CSV. El archivo real permanece fuera de git y logs. La extracción debe ser de solo lectura. Destino previsto crm-test, sin despliegue ni acceso a servidor implícitos en esta entrega. HoneyBook sigue siendo responsable de sus cobros/avisos hasta el traspaso autorizado y conciliado (240 minutos).

## Owner policy amendment — 2026-10-21, main PR110

The generic supplemental-text entry point also rejects known excluded field names `contact_address`, `private_notes`, `sales_tax` (case-insensitive, whitespace normalized to underscores), before any write. This matches the history CSV policy. It does not delete previously stored evidence or attempt to infer semantics from arbitrary note text; Claude must apply the owner's category exclusions when preparing the private extraction. Party special_requests and explicit owner balance-resolution notes remain allowed and distinct from excluded HoneyBook private contact notes.
