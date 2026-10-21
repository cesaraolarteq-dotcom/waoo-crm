<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11pt}table{width:100%}td{padding:7px}</style></head><body>
<h1>Waoo Party · Factura / Invoice — Borrador / Draft</h1>
<p>No emitida. No es recibo de pago. / Not issued. Not a payment receipt.</p>
@if($company)
<p>Facturar a / Bill to: <b>{{ $company['name'] }}</b> · Atención / Attention: {{ $person }}
@if(!empty($company['email']))<br>{{ $company['email'] }}@endif
@if(!empty($company['address']))<br>{{ $company['address'] }}@endif
</p>
@else
<p>{{ $person }}</p>
@endif
<p>Fiesta / Event: {{ $event->starts_at->setTimezone('America/Chicago')->format('Y-m-d g:i a T') }}</p>
<table>
@foreach($finance->lines_snapshot as $line)<tr><td>{{ $line['name'] }}</td><td>{{ \App\Services\Documents\ContractPrintContext::money($line['amount_cents']) }}</td></tr>@endforeach
<tr><td>Costo de transporte / Travel fee</td><td>{{ \App\Services\Documents\ContractPrintContext::money($finance->travel_fee_cents) }}</td></tr>
@foreach($finance->discounts_snapshot??[] as $discount)<tr><td>{{ $discount['label'] }}</td><td>−{{ \App\Services\Documents\ContractPrintContext::money($discount['amount_cents']) }}</td></tr>@endforeach
<tr><td>Total USD</td><td>{{ \App\Services\Documents\ContractPrintContext::money($finance->total_cents) }}</td></tr>
</table><p>Versión financiera / Financial revision {{ $finance->revision }}. Empresa / Billing version {{ $event->billing_company_version }}.</p>
</body></html>
