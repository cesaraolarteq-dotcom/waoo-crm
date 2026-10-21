@php
    $s = $this->summary();
    $money = fn ($c) => $c === null ? '—' : \App\Filament\Support\Labels::money($c);
    $cats = \App\Filament\Resources\Events\Pages\EventCosts::CATEGORIES;
    $services = $this->serviceOptions();
@endphp
<x-filament-panels::page>
    <div class="wmoney">
        @if (! $s['financial_revision_id'])
            <x-filament::section>
                <x-slot name="heading">Primero fija los montos acordados</x-slot>
                <p class="wmoney-p">El margen se calcula contra el ingreso acordado. Fija los montos en “Dinero” y luego registra los costos aquí.</p>
            </x-filament::section>
        @else
            @if (! $s['agreement_current'])
                <div class="wmoney-warn" role="alert">El evento cambió después de fijar los montos. Fija los montos de nuevo en “Dinero”; mientras tanto no se muestra ingreso ni margen.</div>
            @endif
            <div class="wmoney-grid wcost-top">
                <div><span>Ingreso acordado</span><b>{{ $money($s['revenue_cents']) }}</b><small>Servicios + costo de transporte que paga el cliente</small></div>
                <div><span>Cobrado hasta hoy</span><b class="ok">{{ $money($s['cash_received_cents']) }}</b><small>Solo pagos verificados</small></div>
                <div><span>Saldo del cliente</span><b>{{ $money($s['customer_balance_cents']) }}</b><small>No afecta el margen</small></div>
            </div>

            <div class="wcost-cols">
                @foreach (\App\Filament\Resources\Events\Pages\EventCosts::KINDS as $kind => $kindLabel)
                    @php($c = $s[$kind])
                    <x-filament::section>
                        <x-slot name="heading">{{ $kindLabel }}@if ($c) <small class="wcost-rev">versión {{ $c['revision'] }}</small>@endif</x-slot>
                        @if ($c && $c['contribution_margin_cents'] !== null)
                            <div class="wcost-margin {{ $c['contribution_margin_cents'] < 0 ? 'neg' : '' }}">
                                <span>Margen de esta fiesta</span>
                                <b>{{ $money($c['contribution_margin_cents']) }}</b>
                                <small>{{ $s['revenue_cents'] ? number_format($c['contribution_margin_cents'] * 100 / $s['revenue_cents'], 1) : '0' }} % del ingreso · costos {{ $money($c['total_cost_cents']) }}</small>
                            </div>
                        @else
                            <div class="wcost-margin pending">
                                <span>Margen de esta fiesta</span>
                                <b>Pendiente</b>
                                @foreach (\App\Filament\Resources\Events\Pages\EventCosts::pendingReasons($c) as $why)<small>{{ $why }}</small>@endforeach
                                @if ($c)<small>Costos que ya escribiste: {{ $money($c['known_cost_cents']) }} (no es el total)</small>@endif
                            </div>
                        @endif
                        @if ($c)
                            <div class="wcost-lines">
                                @forelse ($c['lines_snapshot'] as $l)
                                    <div class="wmoney-row">
                                        <div><b>{{ $l['amount_cents'] === null ? 'Falta' : $money($l['amount_cents']) }}</b> · {{ $l['label'] }}</div>
                                        <small>{{ $cats[$l['category']] ?? $l['category'] }}@if (! empty($l['event_service_id']) && isset($services[$l['event_service_id']])) · {{ $services[$l['event_service_id']] }}@endif</small>
                                    </div>
                                @empty
                                    <p class="wmoney-p">{{ $c['coverage_confirmed'] ? 'Sin costos (confirmaste que no hubo ninguno).' : 'Todavía no has agregado costos.' }}</p>
                                @endforelse
                            </div>
                            <p class="wmoney-p"><b>Nota:</b> {{ $c['note'] }}</p>
                        @endif
                    </x-filament::section>
                @endforeach
            </div>
        @endif

        @php($history = $this->history())
        @if ($history->isNotEmpty())
            <x-filament::section collapsible collapsed>
                <x-slot name="heading">Historial de costos</x-slot>
                @foreach ($history as $h)
                    <div class="wmoney-row">
                        <div><b>{{ $h->kind }} · versión {{ $h->revision }}</b> · {{ $h->known }} @if ($h->complete) · completo @elseif ($h->unknown) · faltan {{ $h->unknown }} @else · sin confirmar @endif</div>
                        <small>{{ $h->when }} · {{ $h->by }} · {{ $h->note }}</small>
                    </div>
                @endforeach
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
