@if(\App\Services\Booking\QuickPaymentProtocol::enabled())
@php($quick=\App\Services\Booking\QuickPaymentProtocol::booking($event->id,false))
@if($quick)
<x-filament::section>
<x-slot name="heading">Pago de Reserva rápida</x-slot>
<p>{{ match($quick->state){'paid'=>'Depósito verificado; fecha y trajes apartados. Falta confirmar el personal y los demás recursos.', 'zelle_pending'=>'Zelle pendiente de aprobación. El aviso del cliente no es un recibo.', 'review_required'=>'Zelle pendiente al vencer las 48 horas: se conservan la fecha y el traje. Revisa el aviso en Dinero.', 'paid_needs_review'=>'Dinero recibido: requiere revisión del dueño. No se confirmó automáticamente la disponibilidad.', 'expired'=>'El apartado sin pago venció.', default=>'SIN PAGAR. Apartado por 48 horas.'} }}</p>
<p>Vencimiento original: {{ \App\Filament\Support\Labels::when($quick->expires_at) }}</p>
</x-filament::section>
@endif
@endif
