@php
  $es=$vm['lang']==='es';$a=$vm['agreement'];$m=$vm['money'];
  $money=fn($c)=>$c===null?($es?'Por confirmar':'Being reviewed'):'$'.number_format($c/100,2,'.',',');
  $when=\Carbon\CarbonImmutable::parse($vm['event']['starts_at'])->setTimezone('America/Chicago')->locale($vm['lang']);
@endphp
@extends('portal.layout')
@section('content')
<section class="p-card">
  <h1 class="p-h1">{{ $es?'Tu acuerdo original':'Your original agreement' }}</h1>
  <p>{{ $vm['first_name'] }}, {{ $es?'aquí puedes consultar los datos y pagos registrados de tu fiesta.':'here you can see your party details and recorded payments.' }}</p>
  <h2>{{ $vm['event']['title'] }}</h2>
  <p>{{ $when->isoFormat($es?'D [de] MMMM [de] YYYY, h:mm a':'MMMM D, YYYY, h:mm a') }}</p>
  <p>{{ $vm['event']['address'] }}</p>
  <p>{{ $a['package_description'] }}</p>
  @if($a['characters_description']!=='')<p>{{ $a['characters_description'] }}</p>@endif
  <dl>
    <dt>{{ $es?'Paquete':'Package' }}</dt><dd>{{ $money($a['package_cents']) }}</dd>
    <dt>{{ $es?'Costo de transporte':'Travel fee' }}</dt><dd>{{ $money($a['travel_fee_cents']) }}</dd>
    @if($a['discount_cents']>0)<dt>{{ $es?'Descuento':'Discount' }}</dt><dd>−{{ $money($a['discount_cents']) }}</dd>@endif
    @if($a['original_difference_cents']!==0)<dt>{{ $es?'Diferencia del total original':'Original total difference' }}</dt><dd>{{ $money($a['original_difference_cents']) }}</dd>@endif
    <dt>{{ $es?'Total acordado':'Agreed total' }}</dt><dd>{{ $money($a['original_total_cents']) }}</dd>
    <dt>{{ $es?'Pagos registrados':'Recorded payments' }}</dt><dd>{{ $money($m['recorded_paid_cents']) }}</dd>
    <dt>{{ $es?'Saldo':'Balance' }}</dt><dd>{{ $money($m['balance_cents']) }}</dd>
    @if(($m['credit_cents']??0)>0)<dt>{{ $es?'Crédito':'Credit' }}</dt><dd>{{ $money($m['credit_cents']) }}</dd>@endif
  </dl>
  @if($m['state']==='review_required')<p class="p-hint">{{ $es?'Estamos revisando los pagos. Contáctanos si necesitas ayuda.':'We are reviewing the payments. Please contact us if you need help.' }}</p>@endif
  @if($m['reviewed_due_at'])<p>{{ $es?'El saldo vence:':'Balance due:' }} {{ \Carbon\CarbonImmutable::parse($m['reviewed_due_at'])->setTimezone('America/Chicago')->locale($vm['lang'])->isoFormat($es?'D [de] MMMM [de] YYYY, h:mm a':'MMMM D, YYYY, h:mm a') }}</p>@endif
  <p><a class="p-btn" href="{{ $a['document']['pdf_url'] }}">{{ $es?'Descargar documento original':'Download original agreement' }}</a></p>
  <p class="p-hint">{{ $es?'Este portal es de consulta. No necesitas volver a firmar ni pagar otro depósito aquí.':'This portal is for viewing your agreement. You do not need to sign again or pay another deposit here.' }}</p>
</section>
@endsection
