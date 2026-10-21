<!doctype html>
<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10pt;color:#141a33}h1{color:#122f94}table{width:100%;border-collapse:collapse}td,th{padding:8px;border-bottom:1px solid #ddd;text-align:left}.money{text-align:right}small{color:#555}</style></head><body>
@php
    $es=$p['locale']==='es';
    $money=fn($c)=>'$'.number_format($c/100,2,'.',',');
    $when=fn($s)=>\Carbon\CarbonImmutable::parse($s)->setTimezone('America/Chicago')->format('Y-m-d g:i a T');
@endphp
<h1>Waoo Party — {{ $es?'Cotización':'Quote' }} {{ $p['number'] }}</h1>
<p>{{ $es?'Revisión':'Revision' }} {{ $p['revision'] }} · {{ $es?'Válida hasta':'Valid until' }} {{ $when($p['valid_until']) }}</p>
<p>{{ $p['customer_name'] }}<br>{{ $when($p['event']['starts_at']) }} – {{ $when($p['event']['ends_at']) }}<br>{{ $p['event']['address'] ?? '' }} {{ $p['event']['postal_code'] ?? '' }}</p>
<table><thead><tr><th>{{ $es?'Servicio':'Service' }}</th><th class="money">USD</th></tr></thead><tbody>
@foreach($p['lines'] as $line)
<tr><td><b>{{ $line['name'] }}</b> · {{ $line['duration_minutes'] }} min<br>{{ $line['long_description'] }}
@foreach($line['characters'] as $c)<br>{{ $c['quantity'] }} × {{ $c['name'] }}@endforeach
@if(($line['extra_characters'] ?? 0) > 0)<br>{{ $es ? 'Incluye '.$line['extra_characters'].' '.($line['extra_characters'] === 1 ? 'personaje adicional' : 'personajes adicionales') : 'Includes '.$line['extra_characters'].' extra '.($line['extra_characters'] === 1 ? 'character' : 'characters') }} ({{ $money($line['extra_character_cents']) }} {{ $es ? 'c/u' : 'each' }})@endif
</td><td class="money">{{ $money($line['amount_cents']) }}</td></tr>
@endforeach
<tr><td>{{ $es?'Costo de transporte':'Travel fee' }}</td><td class="money">{{ $money($p['travel_fee_cents']) }}</td></tr>
@foreach($p['discounts'] as $discount)<tr><td>{{ $es?'Descuento':'Discount' }}: {{ $discount['label'] }}</td><td class="money">−{{ $money($discount['amount_cents']) }}</td></tr>@endforeach
<tr><td><b>Total</b></td><td class="money"><b>{{ $money($p['total_cents']) }}</b></td></tr>
<tr><td>{{ $es?'Depósito del 50 %':'50% deposit' }}</td><td class="money">{{ $money($p['deposit_target_cents']) }}</td></tr>
</tbody></table>
<p>{{ $es?'Aceptar esta cotización no confirma recursos ni registra un pago. El contrato se firma por separado.':'Accepting this quote does not confirm resources or record a payment. The contract is signed separately.' }}</p>
<small>{{ $es?'La disponibilidad se confirma por separado.':'Availability is confirmed separately.' }}</small>
</body></html>
