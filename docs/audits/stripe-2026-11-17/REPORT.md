# Auditoría Stripe — 2026-11-17 — Codex

Base revisada: `b0219d00906d8bc06df54fc7ccdf3bb79d9953f5` (origin/main; integración #207). Esta auditoría revisa el código disponible, no certifica la configuración ni el despliegue de crm.waooparty.com. No se contactó Stripe, no se activaron flags ni se efectuaron cobros/reembolsos/envíos reales.

## Resultado

No declarar completado el ciclo de reembolsos hasta corregir A1 y A2. Los casos A3–A5 son endurecimiento/trazabilidad; no se presentan como una vía demostrada para falsificar cobros en Stripe.

### A1 — P1 — Reembolso enviado, pero el adaptador siempre rechaza la respuesta válida

`app/Services/Finance/StripeTestRefunds.php:19–20` crea el reembolso y después exige `refund.livemode`. El objeto Refund del SDK instalado no tiene ese campo (sí lo tienen Charge y PaymentIntent). Con la respuesta válida sin ese campo se lanza `stripe_mode_mismatch` después del POST. `RefundPayment.php:61–63` lo transforma en resultado desconocido/503 y no registra el reembolso, aunque Stripe ya puede haber devuelto el dinero. Afecta test y live.

Repro: `StripeRefundAdapterTest::test_refund_without_nonexistent_livemode_field_uses_verified_payment_mode` produce ERROR `stripe_mode_mismatch` contra main. Transporte SDK simulado, respuesta con id/status/amount/currency/payment_intent, sin inventar un atributo inexistente. El segundo caso demuestra que no se consulta el PaymentIntent antes del POST; no demuestra que Stripe real permita reembolsar un objeto del otro modo.

Corrección sugerida: verificar cuenta y PaymentIntent (modo, identidad, estado, moneda y monto) antes del POST. Validar identidad, PaymentIntent, moneda, monto y estado de la respuesta Refund sin exigir ese atributo. Recuperar resultados inciertos por identidad remota antes de repetir operaciones antiguas; conservar la idempotencia.

### A2 — P1 — Reembolso pendiente contabilizado como completado

`app/Services/Changes/RefundPayment.php:65–74` admite tanto `pending` como `succeeded` y guarda ambos como `succeeded`, creando una salida definitiva en ledger. Un pending que después falla deja el saldo incorrecto. Además, los callbacks nativos de refund van al procesador que los marca `unsupported_financial_event`; eso abre reconciliación, pero no actualiza automáticamente este RefundRequest/ledger. La captura de reembolsos importados sí distingue estados; reutilizar su criterio, no asumir que también cubre reservas nativas.

Repro: `CancelAndRefundTest::test_audit_pending_refund_is_not_booked_as_completed` FALLA: la petición queda `succeeded` con respuesta simulada `pending`. El adapter se sustituye por el contrato StripeRefunds para aislar este defecto de A1.

Corrección sugerida: persistir provider_refund_id y estado pendiente, reservar el importe contra nuevos reembolsos, registrar dinero devuelto solo al confirmar `succeeded`; reconciliar `failed`, `canceled` y `requires_action`, con callbacks duplicados/fuera de orden y recuperación periódica. Prueba adicional necesaria: pending → succeeded, pending → failed y dos solicitudes concurrentes.

### A3 — P2 — No revalidar modo/cuenta del webhook almacenado al reprocesarlo

`StripeReceiptProcessor.php:30–31`, `ProcessQuickPaymentReceipt` y `ProcessTipStripeReceipt` leen el payload cifrado sin repetir su límite de modo/cuenta. El controlador HTTP sí verifica firma, modo y cuenta al ingreso. Si cambia configuración/cuenta o se restaura una cola de otro entorno, el worker no rechaza explícitamente esa procedencia antes de consultar al proveedor.

Repro nativo: se guarda un webhook válido, se cambia SOLO livemode del payload sintético cifrado y se ejecuta el worker; crea dos filas de ledger cuando la regresión espera cero. Esto NO significa que un atacante pueda editar el payload cifrado ni saltarse la firma HTTP. La consulta al proveedor y la vinculación de la sesión siguen existiendo.

Sugerencia: revalidar procedencia duradera en router y entradas directas/cron, fallando a reconciliación antes del proveedor, sin descartar evidencia.

### A4 — P2 — Cargo expandido sin comprobar su modo en pagos normales y propinas

`StripeReceiptProcessor.php:67–71` y `ProcessTipStripeReceipt.php:32–33` validan modo de Session y PaymentIntent, pero omiten el de latest_charge. Reserva rápida sí lo exige (`ProcessQuickPaymentReceipt.php:46`).

Repro nativo: sesión/PaymentIntent test, latest_charge live simulado; se acreditan dos filas de ledger. Es una defensa incompleta frente a respuesta inconsistente; no prueba que la API genuina de Stripe genere esa combinación. Homogeneizar chequeos de modo, PaymentIntent del cargo, moneda e importe y cubrir también propinas.

### A5 — P2 — Identidad externa de cobros live etiquetada como test

`StripeReceiptProcessor.php:86`, `ProcessQuickPaymentReceipt.php:56`, `ProcessTipStripeReceipt.php:41` y rutas de apertura construyen `stripe:test:` también en live. La regresión live confirma pago e idempotencia, pero falla al exigir identidad `stripe:live:`. La columna histórica environment=test también permanece por decisión documentada de Claude.

No se demostró mezcla de bases ni duplicación: cuentas/bases separadas y PaymentIntent IDs siguen aportando aislamiento. Recomendación: documentar explícitamente el campo legado y diseñar transición de identidades con lectura compatible/alias para cobros live YA registrados. No cambiar prefijos o huellas históricas a ciegas: podría duplicar registros en reintentos. La rama antigua 07ef944 contiene ideas de fixes pero NO debe fusionarse completa: diverge de main y presupone no haber empezado a cobrar live.

## Evidencia reproducible

- PHP 8.3.36, PHPUnit 12.5.39, MariaDB local de pruebas; datos ficticios y transporte Stripe simulado.
- Adaptador: 2 casos, 1 error y 1 fallo (1 aserción). Ninguna llamada de red Stripe.
- Casos de negocio: 4 casos / 11 aserciones, 4 fallos esperados que reproducen A2–A5.
- `regressions.patch` y `StripeRefundAdapterTest.php` contienen los casos; están fuera de la suite automática porque documentan defectos ABIERTOS, no arreglos. No se alteró ningún test existente ni código de producción en la entrega.
- Reproducir sobre la base indicada en un checkout de pruebas: aplicar `regressions.patch`, copiar el test del adaptador a tests/Feature y ejecutar ese archivo; luego `vendor/bin/phpunit --filter 'test_(audit_pending|live_receipt_uses|stored_webhook_mode|nested_charge)'`. Usar exclusivamente una base desechable de pruebas. Para adoptar los arreglos, incorporar estas regresiones a tests/Feature y exigir verde.
- Controles existentes StripeModeTest, StripeSandboxTest y CancelAndRefundTest sin modificaciones: **27 pruebas / 172 aserciones PASS**, 1m39s. Incluyen firma/modo/cuenta inválidos, activación, idempotencia, recuperación y carrera Zelle/Stripe. No es la suite completa.

## Alcance pendiente y mejoras

No verificados: secretos/cuenta/capacidades del servidor, URL real y versión de webhook, scheduler/worker de Hostinger, pago real de $1 y devolución, navegación cliente, suite completa/CI final y estado del despliegue. No afirmar que Stripe real está listo solo porque CI pase.

Antes de aceptación operacional: confirmar cuenta/mode desde el servidor sin revelar secretos; probar firma incorrecta, callbacks duplicados/fuera de orden, saldo y fee de 3% exactos, pago tardío, Klarna/Afterpay habilitados realmente, refund pendiente/fracaso y reconciliación. Mantener observabilidad de queued/unknown/reconciliation_required. Tratar el plazo de idempotencia del proveedor y las respuestas perdidas sin prometer reintentos eternamente seguros.

No cambiar textos, precios, condiciones, datos reales o flags como parte de esta auditoría. Revisar los fixes y luego desplegar solo según autorización del dueño.
