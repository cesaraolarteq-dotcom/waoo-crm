# TIP-01 — propina por tarjeta, API v1 propuesta para revisión cruzada

2026-10-19 · Decisión del dueño registrada en `docs/tickets/TIP-01.md` (Claude e1f10b0/c5cc8c3). Contrato antes de implementar, **sin rutas/backend de propinas todavía**. Pantallas Claude, backend Codex. Todo exclusivamente Stripe test; flags apagados, sin cobros/envíos/deploys. No Zelle para propinas. Sustituye el borrador anterior de correo que pedía propina por Zelle.

## Reglas y aislamiento del dinero

5/10/15 % del total de la revisión financiera fijada, o monto personalizado USD1–2000. Waoo absorbe la comisión, sin recargo ni línea de comisión para la clienta. El importe bruto corresponde al staff de la fiesta. No asumir reparto igual ni hacer transferencias al personal: la regla de reparto queda pendiente de César y STAFFPAY.

Guardar las propinas en tablas separadas, nunca como receipt del ledger de la reserva: no reducen saldo, no cubren depósito, no confirman capacidad, no disparan el aviso de pago de la reserva y no se incluyen en ingresos comerciales de REPORT-A. Costos de procesamiento conocidos irán como gasto de Waoo con su evidencia; si aún no se conocen quedan null, no cero inventado. REPORT-A tendrá una línea independiente de propinas con su período y refunds, sin modificar snapshots históricos.

No alterar `CreateCheckoutIntent`/`StripeReceiptProcessor` para tratar una propina como pago de fiesta. El receptor Stripe verificado comparte el ingreso de eventos, pero enruta únicamente intentos de propina persistidos y vinculados por metadata y sesión verificadas. Metadata por sí sola nunca acredita dinero.

## Acceso y revisión fijada

Grant dedicado evento+clienta, scope único `tip.create`, token de alta entropía, hash y bóveda cifrada; no añadir ese scope a grants existentes del portal/contrato. Tanto emisión manual como desde agradecimiento exigen `responsible_system=crm`; eventos `honeybook` se rechazan, sin emitir ni recuperar un enlace. Revalidar también en lectura/checkout antes de cualquier llamada a Stripe. Emitir solo si terminó la fiesta, tiene capacidad reservada vigente y no está cancelada. Vence a `ends_at UTC +14 días`, no 14 días después de abrir o enviar el correo; revocable. Revalidar condiciones, identidad y revisión financiera antes del checkout. La emisión fija `financial_revision_id`, total_cents y hash del snapshot; si cambia la revisión, exigir un enlace nuevo, nunca recalcular silenciosamente lo que la página mostró.

El enlace server-built `/portal/tip#token` usa intercambio de fragmento por sesión aislada; no token en query/path/logs. Sin ampliar permisos de otros portales, sin acceso entre eventos. HTTPS, cookies seguras/HttpOnly/SameSite, protección CSRF/origen, rate limit, no-store, no-referrer y noindex. Enlace bruto solo al emisor autorizado y al payload cifrado del correo aprobado; ningún job/audit/owner list lo expone.

Cada cobro confirmado permanece como evidencia aunque luego venza/se revoque el acceso o se cancele el evento: registrarlo separadamente y marcar conciliación, no descartar dinero realmente recibido ni reabrir la reserva. Una navegación a success nunca demuestra pago.

## Modelo propuesto

- `tip_grants`: event/customer/financial_revision, snapshot_sha256, total_cents, locale, token_hash, encrypted_token, expires_at, revoked_at, version, created_by.
- `tip_checkout_intents`: UUID, grant/event/financial_revision, environment=test, option, amount_cents, currency=USD, customer_fee_cents=0, status creating/open/paid/expired/failed/reconciliation_required, provider account/session/payment IDs únicos cuando conocidos, encrypted checkout URL, expires_at, idempotency scope/payload hash. No reutilizar collection_slot de pagos de la fiesta. Nueva clave permite una nueva propina; misma clave/payload recupera el mismo intento.
- `tip_receipts`: identidad externa única `stripe:test:account:payment_intent`, tip intent/event/grant, gross_cents, currency, provider_received_at, evidence/webhook reference. Inmutable.
- `tip_refunds`: asiento separado, positivo, único por refund del proveedor y ligado a receipt; nunca editar ni borrar receipt. Refund real requiere autorización/implementación posterior, no se publica un botón que simule devolver dinero.
- Comisión/procesamiento: evidencia separada por transacción; importe conocido o null. No deducirlo del bruto para staff.

Todo dinero en centavos enteros, sin floats. Para un porcentaje p del total T en centavos, dólares enteros half-up: `intdiv(T*p +5000,10000)*100`. Ejemplo T=61500 →3100/6200/9200. Opciones que redondean a0 no se pueden cobrar y se omiten de la proyección; sigue disponible monto personalizado. Personalizado acepta string decimal canónico con hasta2 decimales (p.ej. `25`, `25.50`), sin signo/exponente/comas/símbolos; validar USD1–2000. Rechazar importes/payloads extra; los importes de porcentajes solo salen del servidor.

## Servicios propuestos

```php
IssueTipGrant::execute(User $actor, int $eventId, array $input, string $key): array;
IssueTipGrantForMail::execute(int $eventId, int $occurrenceId): ?int;
RevokeTipGrant::execute(User $actor, int $grantId, array $input, string $key): array;
ReadTipViewModel::read(TipContext $context): array;
CreateTipCheckout::execute(TipContext $context, array $input, string $key): array;
ProcessTipStripeReceipt::execute(string $receiptId): void;
ReadEventTips::execute(User $actor, int $eventId): array;
```

Actor owner activo+2FA en emisión/revocación/lectura owner. Contexto de clienta obtenido exclusivamente del grant verificado, nunca event/customer/grant ID del navegador. Candados evento → revisión/grant → intento; UUID/payload exacto idempotente y versión/hash fijados,409 si cambió. No llamadas al proveedor dentro de transacciones. Idempotency-Key estable hacia Stripe y protocolo de resultado incierto: nunca abrir un segundo checkout a ciegas por timeout. Reintentar misma operación; incidente si vence ventana segura de retry.

## Rutas planificadas para Claude (no implementadas en esta entrega)

- POST `/admin/api/events/{event}/tip-grants`, Idempotency-Key, `{expected_event_version,financial_revision_id,locale}` →201 `{id,version,expires_at,link}` privado. La automatización usa la misma validación, fuente inmutable y dedupe del correo, no un owner ficticio.
- POST `/admin/api/tip-grants/{grant}/revoke`, Idempotency-Key, `{expected_version}` → `{id,version,revoked:true}`. Replays de emisión no deben revelar un grant después de revocarlo.
- GET `/admin/api/events/{event}/tips` → `{receipts:[{id,gross_cents,currency,received_at,refunded_cents}],gross_cents,refunded_cents,net_staff_cents}`. Solo totales conocidos, datos del proveedor/fees restringidos a vista de conciliación futura.
- POST `/portal/tip/session` `{token}` →cookie de contexto, sin token en respuesta. DELETE para salir.
- GET `/portal/tip`, GET `/portal/api/tip`: proyección cerrada siguiente.
- POST `/portal/api/tip/checkout`, Idempotency-Key, `{financial_revision_id,snapshot_sha256,option,custom_amount?}` → `{intent_id,status,checkout_url}`. custom_amount solo con option=custom. 409 revisión obsoleta o intento incierto;422 monto inválido; acceso inválido404/401 sin revelar evento ajeno.
- GET `/portal/api/tip/checkouts/{intent}` → `{status}` solo del grant actual; usado al volver de Stripe. No permite consultar UUID de otro grant.

VM compatible con preview de Claude, más revisión requerida para submit:

```text
lang: es|en, test_mode:true, preview:false, first_name
lang_url:{es,en}, review_url: URL pública permitida o null
tip:{state:open|paid|closed,when,team,total,
     financial_revision_id,snapshot_sha256,
     options:[{key:pct_5|pct_10|pct_15,percent,amount,amount_cents}]}
actions:{checkout:'/portal/api/tip/checkout'}
```

`team` es una etiqueta con primeros nombres únicamente del equipo vigente de esa fiesta, no directorio/teléfonos; `total` y `amount` son strings formateados, nunca autoridad para cobrar. Sin fees/pay/ledger/contactos ni notas privadas. `when` en Houston EN/ES. `paid` solo al consultar un intento del mismo grant con receipt verificado; volver a abrir el enlace mientras vigente permite otra propina, no cierra el evento al primer pago. El preview actual usa POST de formulario; la conexión real debe agregar CSRF, UUID estable, revisión/hash, manejo de respuestas y navegación a checkout_url verificada.

## Stripe, correo y pruebas

Exigir flags independientes sandbox_tips + sandbox_payments, entorno local/testing/staging, claves sk_test y cuenta verificada. Checkout card-only, USD, línea «Propina para el equipo / Tip for the team», principal=payable y fee cliente=0. Metadata kind=tip + local_tip_intent_id,event_id,grant_id; no datos personales. Validar HTTPS host exacto checkout.stripe.com antes de devolver URL. Verificar sesión, cuenta, modo test, moneda, monto, payment_intent succeeded/amount_received y charge captured/paid/fecha; webhook firmado y deduplicado. No cobros por navegación o metadata sin reconciliar. Refunds/out-of-order/chargebacks requieren evidencia y nunca modifican ledger de la reserva.

MAIL aprobado podrá aceptar `button2`/`tip_link` **solo en thank_you_review**: resolver/emitir grant antes de congelar payload; comparar fuente/grant al enviar. Si no puede emitir enlace, mantener correo de reseña sin botón2 y registrar razón segura; no fabricar URL, reintentar cobro ni insertar enlaces en una plantilla ya congelada. Horario aprobado:10:00 Houston al día siguiente. No activar borradores de textos ni enviar nada por publicar esta API.

Criterios: cliente/otro grant sin acceso, evento futuro/cancelado/revisión obsoleta, límites14d/DST, manipulación de porcentajes/custom, redondeo half-up, timeout con misma clave y concurrencia, webhook duplicado/fuera de orden, cuenta/importe/moneda equivocada, propina sin cambiar saldo/depósito/reserva/ingresos comerciales, grants revocados con cobro tardío conciliado, privacidad EN/ES, reporte separado y correo sin botón roto. Datos sintéticos; pruebas MariaDB para carreras. Contrato aprobado por Claude, incluido el requisito de responsabilidad CRM en emisión manual y por correo; backend todavía pendiente. Este documento suma0 implementación.

## Implementation amendment — core A, 2026-10-19 (before code)

Services live under `App\Services\Tips`. Existing payment flag is `waoo.sandbox_finance`, not a new `sandbox_payments` alias: require it **and** independent `waoo.sandbox_tips=false` by default. No chosen/live provider/account change. Core A covers manual issuance/revocation, isolated session, existing Claude page/JSON projection, checkout, authenticated Stripe routing + immutable tip receipts, explicit reconciliation and owner tip reads. MAIL issuance/button2/materialization and aggregate report presentation are subsequent connectors, not claimed by core A; existing REPORT-A sales/receipt tables must remain unchanged. No refund initiation endpoint/provider call is enabled; a tip refund/dispute webhook is retained as reconciliation evidence until a verified refund adapter exists.

Dedicated `waoo_tip_session` encrypted opaque HttpOnly/SameSite cookie path `/portal`, hash-only `tip_portal_sessions`, expires at min(grant expiry, UTC now+24h). Existing client/staff cookies never supply TIP authority or grant IDs; owner login cannot be converted into a tip customer. Exchange is CSRF-protected and validates Origin when present; staging requires recognized HTTPS + secure session configuration. Fragment is cleared before exchange; explicitly opening a different fragment replaces only tip access. Raw link remains issuance-only (exact-key retries may recover the same still-current grant), never listing/audit/jobs. View/model reads pin event/customer/finance and grant; expired/revoked/current-source conflicts never recover another link.

One outstanding collection slot per grant (nullable unique `active_grant_id` on intents); another amount/key cannot bypass an unknown/open provider result. Paid/verified expired/failed release it; several *completed* tips are allowed. Retry same key and exact payload within23h; an expired local clock alone does not certify an external session expired. Provider calls outside DB locks, verified receipt is authoritative over late create responses. Return URL `/portal/tip?checkout={uuid}` is a scoped read/poll hint, never proof of payment; read without checkout remains open so another tip can be intentional. Reject unknown checkout keys and noncanonical/nonstring custom values; browser never supplies a charge amount for percentage choices.

New tables: tip_grants, tip_portal_sessions, tip_checkout_intents, tip_receipts, tip_refunds (immutable evidence schema only), tip_reconciliations (stable dedupe key + safe reason, IDs only, not provider payload/customer text). USD integer cents; nullable processing_fee_cents on immutable receipt remains unknown until independently verified evidence, never inferred from a rate. Grant financial fingerprint binds event/customer/operational version/start/end + immutable financial revision content. Current paid-capacity financial binding/reservation/evidence must exist and agree, not a guessed total for an old imported agreement. C1 and ImportedAgreement blocks are retained on issuance/read/checkout; late real evidence survives responsibility/grant/operational changes and creates reconciliation.

Verified Stripe ingress persists only bounded metadata `kind=tip,local_tip_intent_id,event_id,grant_id` in the existing encrypted minimal receipt, then dispatches to ProcessTipStripeReceipt. Any conflicting tip/booking namespace is refused to reconciliation, never credited to the booking ledger; persisted tip intents and fetched test session/account/amount/payment/charge must all agree. A kind field without a matching local intent cannot create money. Booking processor remains semantically separate. Unknown refunds/disputes are never treated as successful tips.

Core A safety clarification before final implementation: outstanding collections also hold a nullable unique `active_event_id` (in addition to grant slot), so issuing a second grant for the same party cannot bypass an uncertain/open result. Both slots release only on verified paid/expired/failed outcomes; no cap on sequential completed tips. TIP cookie uses SameSite=Lax for Stripe's cross-site top-level GET return; all state changes remain CSRF/Origin protected. Only a scoped verified receipt can show paid. Existing staff cookie/protocol is unchanged.
