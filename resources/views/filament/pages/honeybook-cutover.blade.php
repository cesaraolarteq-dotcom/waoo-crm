@php($r = $this->readiness())
<x-filament-panels::page>
    @if (! $r)
        <p class="wmoney-warn" role="alert">No se pudo leer el estado del grupo. Recarga la página.</p>
    @else
        <x-filament::section>
            <x-slot name="heading">Fiestas que hoy cobra HoneyBook</x-slot>
            <x-slot name="description">Montos según HoneyBook: no están verificados en tu banco.</x-slot>
            <div class="wmoney-grid wrep-grid">
                <div><span>Fiestas</span><b>{{ $r['counts']['events'] }}</b><small>{{ $r['counts']['reviewed'] }} revisadas · {{ $r['counts']['documents'] }} con contrato original</small></div>
                <div><span>Total acordado</span><b>{{ \App\Filament\Support\Labels::money($r['totals']['total_cents']) }}</b><small>@if ($r['unknowns']){{ $r['unknowns'] }} sin monto claro @else Montos originales @endif</small></div>
                <div><span>Pagado en HoneyBook</span><b>{{ \App\Filament\Support\Labels::money($r['totals']['net_paid_cents']) }}</b><small>@if ($r['totals']['refund_cents']) Devoluciones {{ \App\Filament\Support\Labels::money($r['totals']['refund_cents']) }} @else Sin devoluciones @endif</small></div>
                <div><span>Saldo por cobrar</span><b>{{ \App\Filament\Support\Labels::money($r['totals']['balance_cents']) }}</b><small>@if ($r['counts']['pending_source_rows']){{ $r['counts']['pending_source_rows'] }} filas del archivo sin resolver @else Archivo completo @endif</small></div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Cada fiesta</x-slot>
            <x-slot name="description">En cada una: botón “Evidencia para el cambio” (contrato firmado original, tu revisión de cuentas y que ya apagaste los avisos y cobros de HoneyBook).</x-slot>
            @foreach ($this->members() as $m)
                <div class="wmoney-row">
                    <div><b>{{ $m['title'] }}</b> · {{ $m['when'] }}
                        @if ($m['missing'] === [])<x-filament::badge color="success" size="sm">Evidencia completa</x-filament::badge>@else<x-filament::badge color="warning" size="sm">Falta {{ count($m['missing']) }}</x-filament::badge>@endif
                    </div>
                    @if ($m['missing'])<small>{{ collect($m['missing'])->map(fn ($c) => \App\Filament\Support\CutoverUi::label($c))->implode(' · ') }}</small>@endif
                    @if ($m['review'])<small><br>Revisaste las cuentas: {{ \App\Filament\Pages\HoneyBookCutover::when($m['review']['source_observed_at']) }} · vale hasta {{ \App\Filament\Pages\HoneyBookCutover::when($m['review']['expires_at']) }}</small>@endif
                    <div class="wrep-actions" style="margin-top:6px"><a class="wcal-btn" href="{{ \App\Filament\Resources\Events\EventResource::getUrl('view', ['record' => $m['event_id']]) }}">Abrir la fiesta</a></div>
                </div>
            @endforeach
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Preparar la evidencia del grupo completo</x-slot>
            <x-slot name="description">Congela el estado de todas las fiestas a la vez. Vale 4 horas contadas desde tu revisión de cuentas más antigua.</x-slot>
            @if ($r['reconciliation'])
                <p>Última preparación: revisión más antigua {{ \App\Filament\Pages\HoneyBookCutover::when($r['reconciliation']['source_observed_at']) }} · vence {{ \App\Filament\Pages\HoneyBookCutover::when($r['reconciliation']['expires_at']) }}
                    <x-filament::badge :color="$r['reconciliation']['current'] ? 'success' : 'gray'" size="sm">{{ $r['reconciliation']['current'] ? 'Vigente' : 'Ya no vale' }}</x-filament::badge></p>
            @endif
            @php($blocking = array_values(array_diff($r['readiness']['missing'], ['financial_bridge_not_implemented', 'transfer_not_implemented', 'reconciliation_required'])))
            @if ($blocking)
                <p class="wmoney-warn" role="alert"><b>Todavía no se puede preparar:</b></p>
                <ul class="wchg-effects">@foreach ($blocking as $c)<li>{{ \App\Filament\Support\CutoverUi::label($c) }}</li>@endforeach</ul>
            @else
                <label class="wimp-check"><input type="checkbox" wire:model="scopeReviewed"> Confirmo que el archivo de HoneyBook tiene <b>todas</b> las fiestas que siguen activas</label>
                <div class="wrep-actions"><button type="button" class="wcal-btn on" wire:click="prepare">Preparar evidencia</button></div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Pasar al CRM</x-slot>
            <x-slot name="description">{{ \App\Filament\Support\CutoverUi::label('financial_bridge_not_implemented') }} {{ \App\Filament\Support\CutoverUi::label('transfer_not_implemented') }}</x-slot>
            <div class="wrep-actions"><button type="button" class="wcal-btn" disabled>Pasar {{ $r['counts']['events'] }} fiestas al CRM</button></div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
