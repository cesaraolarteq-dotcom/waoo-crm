@php
    $f = $this->summary();
    $rev = $f['financial_revision'];
    $money = fn ($c) => \App\Filament\Support\Labels::money($c);
    $paid = $f['net_received_cents'];
@endphp
<x-filament-panels::page>
    <div class="wmoney">
        <div class="wmoney-states" aria-label="Estados por separado">
            <span>Pago: <b>{{ $paid > 0 ? ($rev && $f['balance_cents'] === 0 ? 'Pagado completo' : 'Pago recibido') : 'Sin pagos' }}</b></span>
            <span>Recursos: <b>{{ \App\Filament\Support\Labels::label(\App\Filament\Support\Labels::EVENT_STATUS, $f['capacity_status']) }}</b></span>
            <span>Contrato: <b>{{ $f['contract_status'] === 'not_issued' ? 'Sin emitir' : $f['contract_status'] }}</b></span>
        </div>

        @if (! $rev)
            <x-filament::section>
                <x-slot name="heading">Primero fija los montos acordados</x-slot>
                <p class="wmoney-p">Los montos salen del precio guardado de cada servicio y del costo de transporte. Si el transporte está pendiente, complétalo en el evento: nunca se toma como $0.</p>
                <p class="wmoney-p">Precio de servicios hoy: {{ $money($this->record->services()->join('price_versions', 'price_versions.id', '=', 'event_services.price_version_id')->sum('price_versions.amount_cents')) }} · Transporte: {{ $money($this->record->travel_fee_cents) }}</p>
            </x-filament::section>
        @else
            <div class="wmoney-grid">
                <div><span>Total acordado</span><b>{{ $money($rev->total_cents) }}</b><small>Servicios {{ $money($rev->subtotal_cents) }} + transporte {{ $money($rev->travel_fee_cents) }}</small></div>
                <div><span>Depósito (50 %)</span><b>{{ $money($rev->deposit_target_cents) }}</b>
                    <small>@if ($f['deposit_shortfall_cents'] > 0) Faltan {{ $money($f['deposit_shortfall_cents']) }} para el depósito @else Depósito cubierto ✓ @endif</small></div>
                <div><span>Recibido</span><b class="ok">{{ $money($paid) }}</b><small>{{ $f['refunded_cents'] ? 'Devuelto '.$money($f['refunded_cents']) : 'Solo pagos verificados' }}</small></div>
                <div><span>Saldo pendiente</span><b>{{ $money($f['balance_cents']) }}</b>
                    <small>Vence {{ \App\Filament\Support\Labels::when($rev->balance_due_at) }} · en el contrato: “{{ $f['balance_label'] }}”</small></div>
            </div>
            @if ($f['credit_cents'] > 0)
                <div class="wmoney-warn" role="alert">Pagó {{ $money($f['credit_cents']) }} de más. Decide con la clienta si se aplica a otro servicio o se le devuelve; el sistema no devuelve nada solo.</div>
            @endif
        @endif

        <x-filament::section>
            <x-slot name="heading">Pagos registrados</x-slot>
            @forelse ($this->payments() as $p)
                <div class="wmoney-row">
                    <div><b>{{ $p->amount }}</b> · {{ $p->method }} · {{ $p->kind }}</div>
                    <small>{{ $p->when }} · ref. {{ $p->reference }} · verificó {{ $p->by }}@if ($p->note) · {{ $p->note }}@endif</small>
                </div>
            @empty
                <p class="wmoney-p">Todavía no hay pagos. Usa “Registrar Zelle verificado” cuando lo veas en el banco.</p>
            @endforelse
        </x-filament::section>

        @if ($rev && $paid > 0 && $f['capacity_status'] === 'draft' && \App\Filament\Resources\Events\Pages\ConfirmCapacity::canAccessFor($this->record))
            <a class="wmoney-next" href="{{ \App\Filament\Resources\Events\EventResource::getUrl('confirm', ['record' => $this->record]) }}">Siguiente paso: apartar staff y trajes con este pago →</a>
        @endif
    </div>
</x-filament-panels::page>
