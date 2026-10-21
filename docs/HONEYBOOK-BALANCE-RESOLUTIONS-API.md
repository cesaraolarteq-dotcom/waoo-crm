# Resoluciones del dueño sobre saldos históricos — v1

2026-10-21. Contrato antes del código; decisiones en OWNER-2026-10-21-HONEYBOOK-DECISIONS.md (main98df55c): pagos fuera de HoneyBook, archivo duplicado, cancelación y cancelación pendiente de reprogramar. No se leyó el archivo privado de resoluciones ni se ejecutó la importación real.

`App\Services\History\ResolveHistoricalBalance::execute(User $actor,int $historyId,array $input,string $key):array`

POST `/admin/api/history/events/{history}/balance-resolutions`, dueño activo+2FA, bandera existente `WAOO_SANDBOX_HISTORY=false`, solo local/testing/staging y HTTPS/CSRF/no-store actuales. UUID Idempotency-Key. Entrada cerrada:

```
expected_history_version: int >=1
expected_revision_id: int >=1
expected_resolution_version: int >=0
expected_source_sha256: SHA256 mostrado
kind: paid_outside_honeybook | duplicate_source_file | cancelled_no_balance | pending_reschedule
owner_note: string 10..2000
```

La lectura `ReadCustomerHistory` incluye source_sha256 y balance_resolution `{id,version,kind,owner_note,source_revision_id,stale,bank_verified:false}` o null. Mostrar saldo original al lado de la declaración. La versión de resolución es independiente de la fiesta histórica/operativa. Solo saldo de origen USD positivo; comprobar revisión/hash/versiones bajo lock de importación→historial→resolución. Reintento exacto devuelve la declaración original; mismo UUID con otra entrada409. Resolución nueva exige versión mostrada y agrega evidencia cifrada inmutable; no sobrescribe la anterior. Si después cambia la revisión del historial, la resolución anterior queda marcada obsoleta y el saldo vuelve a requerir revisión.

No modificar importes, pagos de origen, customer, historical_event/revisions, ledger, BookingEvidence, eventos operativos, firma o consentimiento. No generar saldo cero artificial ni recibo/abono/reembolso. Una declaración del dueño de pago fuera de HoneyBook **no** equivale a verificación bancaria. Una duplicación de archivo **no** borra pagos ni archivos de origen. pending_reschedule permanece visible como acción pendiente: no crea una nueva fecha, reserva, crédito o monto transferido.

El reporte conserva todas las filas e importes positivos de origen y añade resolución, review_required y conteos resolved/pending_reschedule/unresolved. Su total sigue siendo total de origen, nunca deuda neta exigible. La ficha de la clienta y la pantalla de revisión muestran el origen y la resolución separados. El dueño puede registrar/corregir cada decisión mediante formulario con versiones fijadas al abrirlo y UUID estable.

Pruebas sintéticas: retry/cuerpo cambiado, versiones y fuente obsoletas, revisión posterior invalida declaración activa, fallo de auditoría atómico, cifrado/no PII en auditoría, cero cambios de dinero/capacidad y caso pending_reschedule conservado. Claude aplica las decisiones reales desde su copia privada después de revisar el código; HANDOFF solo agregados.
