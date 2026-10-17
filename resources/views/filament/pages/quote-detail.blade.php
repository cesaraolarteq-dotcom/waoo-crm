@php
    $d = $this->data();
    $q = $d['quote'];
    $revs = collect($d['revisions']);
    $cur = $revs->firstWhere('revision', $q['current_revision']);
    $status = \App\Filament\Support\QuoteForm::STATUS;
@endphp
<x-filament-panels::page>
    <div class="wmoney">
        <div class="wmoney-states">
            <span>Estado: <b>{{ $status[$q['effective_status']] ?? $q['effective_status'] }}</b></span>
            <span>Versión actual: <b>{{ $q['current_revision'] }}</b></span>
            <span>Válida hasta: <b>{{ \App\Filament\Support\Labels::when(\Carbon\CarbonImmutable::parse($q['valid_until'])) }}</b></span>
            <span>Idioma: <b>{{ $q['locale'] === 'en' ? 'Inglés' : 'Español' }}</b></span>
        </div>
        @if ($cur)
            <x-filament::section>
                <x-slot name="heading">{{ $cur['customer_snapshot']['name'] ?? '' }} · {{ \App\Filament\Support\Labels::when(\Carbon\CarbonImmutable::parse($cur['event_snapshot']['starts_at'])) }}</x-slot>
                <x-slot name="description">{{ $cur['event_snapshot']['address'] ?? 'Sin dirección' }}@if (! empty($cur['event_snapshot']['postal_code'])) · ZIP {{ $cur['event_snapshot']['postal_code'] }}@endif</x-slot>
                @foreach ($cur['lines_snapshot'] as $l)
                    <div class="wmoney-row"><div><b>{{ $this->money($l['amount_cents']) }}</b> · {{ $l['name'] }} · {{ \App\Filament\Support\Labels::duration($l['duration_minutes']) }}</div>
                        @if (! empty($l['characters']))<small>{{ collect($l['characters'])->map(fn ($c) => $c['quantity'].' × '.$c['name'].(filled($c['outfit'] ?? null) ? ' ('.$c['outfit'].')' : ''))->implode(', ') }}</small>@endif</div>
                @endforeach
                <div class="wmoney-row"><div><b>{{ $this->money($cur['travel_fee_cents']) }}</b> · Costo de transporte</div></div>
                @foreach ($cur['discounts_snapshot'] as $x)
                    <div class="wmoney-row"><div><b class="ok">− {{ $this->money($x['amount_cents']) }}</b> · {{ $x['label'] }}</div><small>Motivo (privado): {{ $x['reason'] }}</small></div>
                @endforeach
                <div class="wmoney-grid wcost-top" style="margin-top:10px">
                    <div><span>Subtotal + transporte</span><b>{{ $this->money($cur['subtotal_cents'] + $cur['travel_fee_cents']) }}</b></div>
                    <div><span>Total</span><b>{{ $this->money($cur['total_cents']) }}</b>@if ($cur['discount_cents'])<small>con descuento de {{ $this->money($cur['discount_cents']) }}</small>@endif</div>
                    <div><span>Depósito (50 %)</span><b>{{ $this->money($cur['deposit_target_cents']) }}</b></div>
                </div>
                @if (! empty($q['owner_note']))<p class="wmoney-p" style="margin-top:8px"><b>Nota privada:</b> {{ $q['owner_note'] }}</p>@endif
            </x-filament::section>
        @endif
        <div class="wmoney-warn" role="note">Enviar la cotización a la clienta, el PDF y convertirla en reserva llegan en la siguiente entrega. Aceptar una cotización no cobra ni aparta staff.</div>
        @if ($revs->count() > 1)
            <x-filament::section collapsible collapsed>
                <x-slot name="heading">Historial de versiones</x-slot>
                @foreach ($revs as $r)
                    <div class="wmoney-row"><div><b>Versión {{ $r['revision'] }}</b> · total {{ $this->money($r['total_cents']) }}@if ($r['discount_cents']) · descuento {{ $this->money($r['discount_cents']) }}@endif</div>
                        <small>{{ \App\Filament\Support\Labels::when(\Carbon\CarbonImmutable::parse($r['created_at'])) }} · válida hasta {{ \App\Filament\Support\Labels::when(\Carbon\CarbonImmutable::parse($r['valid_until'])) }}</small></div>
                @endforeach
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
