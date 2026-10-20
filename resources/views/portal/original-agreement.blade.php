{{-- C3 B3b client page for an agreement that started in HoneyBook (docs/OPENING-CLIENT-PORTAL-API.md).
     $vm is the allowlisted ReadOpeningPortalViewModel projection only. Read-only: no signature, payment, details or Zelle form.
     Unknown money stays "being reviewed", never $0; original amounts are shown exactly as agreed. --}}
@php
  $es = $vm['lang'] === 'es'; $a = $vm['agreement']; $m = $vm['money'];
  $money = fn ($c) => $c === null ? ($es ? 'Por confirmar' : 'Being reviewed') : '$'.number_format($c / 100, 2, '.', ',');
  $tz = $vm['event']['timezone'] ?: 'America/Chicago';
  $start = \Carbon\CarbonImmutable::parse($vm['event']['starts_at'])->setTimezone($tz)->locale($vm['lang']);
  $end = $vm['event']['ends_at'] ? \Carbon\CarbonImmutable::parse($vm['event']['ends_at'])->setTimezone($tz) : null;
  $day = $start->isoFormat($es ? 'dddd D [de] MMMM [de] YYYY' : 'dddd, MMMM D, YYYY');
  $hours = $start->format('g:i a').($end ? ' – '.$end->format('g:i a') : '');
  $due = $m['reviewed_due_at'] ? \Carbon\CarbonImmutable::parse($m['reviewed_due_at'])->setTimezone($tz)->locale($vm['lang'])->isoFormat($es ? 'D [de] MMMM [de] YYYY, h:mm a' : 'MMMM D, YYYY, h:mm a')
       : ($m['source_due_date'] ? \Carbon\CarbonImmutable::parse($m['source_due_date'])->locale($vm['lang'])->isoFormat($es ? 'D [de] MMMM [de] YYYY' : 'MMMM D, YYYY') : null);
  $paidInFull = $m['state'] === 'known' && $m['balance_cents'] === 0;
@endphp
@extends('portal.layout')
@section('content')
<h1 class="p-h1">{{ $es ? 'Tu acuerdo original' : 'Your original agreement' }}</h1>
<p class="p-sub">{{ $es ? "Hola, {$vm['first_name']}. Aquí puedes consultar tu fiesta, lo acordado y los pagos registrados." : "Hi {$vm['first_name']}. Here you can see your party, what we agreed and the payments on record." }}</p>

<section class="p-card p-party" aria-label="{{ $es ? 'Tu fiesta' : 'Your party' }}">
  <h2>{{ $vm['event']['title'] }}</h2>
  <dl>
    <div><dt>{{ $es ? 'Fecha' : 'Date' }}</dt><dd>{{ \Illuminate\Support\Str::ucfirst($day) }}</dd></div>
    <div><dt>{{ $es ? 'Hora' : 'Time' }}</dt><dd>{{ $hours }}</dd></div>
    @if ($vm['event']['address'])<div><dt>{{ $es ? 'Dirección' : 'Address' }}</dt><dd>{{ $vm['event']['address'] }}</dd></div>@endif
  </dl>
</section>

<section class="p-card" aria-label="{{ $es ? 'Lo acordado' : 'What we agreed' }}">
  <h2>{{ $es ? 'Lo acordado' : 'What we agreed' }}</h2>
  <p>{{ $a['package_description'] }}@if ($a['characters_description'] !== '')<br><small>{{ $a['characters_description'] }}</small>@endif</p>
  <ul class="q-lines">
    <li><span>{{ $es ? 'Paquete' : 'Package' }}</span><b>{{ $money($a['package_cents']) }}</b></li>
    <li><span>{{ $es ? 'Costo de transporte' : 'Travel fee' }}</span><b>{{ $money($a['travel_fee_cents']) }}</b></li>
    @if ($a['discount_cents'] > 0)<li class="q-disc"><span>{{ $es ? 'Descuento' : 'Discount' }}</span><b>−{{ $money($a['discount_cents']) }}</b></li>@endif
    @if ($a['original_difference_cents'] !== 0)<li><span>{{ $es ? 'Diferencia del total original' : 'Original total difference' }}</span><b>{{ $money($a['original_difference_cents']) }}</b></li>@endif
    <li class="q-total"><span>{{ $es ? 'Total acordado' : 'Agreed total' }}</span><b>{{ $money($a['original_total_cents']) }}</b></li>
  </ul>
  <a class="p-btn p-btn-quiet" href="{{ $a['document']['pdf_url'] }}">{{ $es ? 'Descargar documento original' : 'Download original agreement' }}</a>
</section>

<section class="p-card" aria-label="{{ $es ? 'Pagos' : 'Payments' }}">
  <h2>{{ $es ? 'Pagos' : 'Payments' }}</h2>
  <ul class="q-lines">
    <li><span>{{ $es ? 'Pagos registrados' : 'Recorded payments' }}</span><b>{{ $money($m['recorded_paid_cents']) }}</b></li>
    <li class="q-total"><span>{{ $es ? 'Saldo' : 'Balance' }}</span><b>{{ $money($m['balance_cents']) }}</b></li>
    @if (($m['credit_cents'] ?? 0) > 0)<li><span>{{ $es ? 'Saldo a tu favor' : 'Credit in your favor' }}</span><b>{{ $money($m['credit_cents']) }}</b></li>@endif
  </ul>
  @if ($m['state'] === 'review_required')
    <p class="p-hint">{{ $es ? 'Estamos revisando los pagos de tu fiesta. Si tienes dudas, escríbenos.' : "We're reviewing the payments for your party. If you have questions, message us." }}</p>
  @elseif ($paidInFull)
    <p class="p-hint">{{ $es ? '¡Tu fiesta está pagada por completo! Gracias.' : 'Your party is paid in full. Thank you!' }}</p>
  @elseif ($due)
    <p class="p-hint">{{ $es ? 'El saldo vence:' : 'Balance due:' }} <b>{{ $due }}</b></p>
  @endif
</section>

<section class="p-card">
  <p class="p-hint">{{ $es ? 'Este portal es solo de consulta: no necesitas volver a firmar ni pagar otro depósito aquí.' : 'This page is for viewing only: you do not need to sign again or pay another deposit here.' }}</p>
</section>
@endsection
