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

        @if (\App\Filament\Pages\HoneyBookCutover::bridgeEnabled())
            @php($o = $this->openings())
            <x-filament::section>
                <x-slot name="heading">Acuerdos de apertura (preparación)</x-slot>
                <x-slot name="description">Copia lo acordado en HoneyBook (total, lo pagado, saldo y vencimiento) para cuando el CRM empiece a cobrar. Es solo preparación: no autoriza cobros, no cuenta como venta nueva ni como dinero que entró al CRM.</x-slot>
                @if ($this->openingPin)
                    <div class="wrep-actions"><button type="button" class="wcal-btn on" wire:click="prepareOpenings" wire:loading.attr="disabled">Preparar acuerdos de {{ $r['counts']['events'] }} fiestas</button></div>
                @else
                    <p class="wqb-hint">Primero prepara la evidencia del grupo completo (arriba). Los acuerdos se preparan con esa evidencia mientras esté vigente.</p>
                @endif
                @if ($o && isset($o['error']))
                    <p class="wmoney-warn" role="alert">{{ $o['error'] }}</p>
                @elseif ($o)
                    <p style="margin-top:10px">Últimos acuerdos: {{ $o['event_count'] }} fiestas · revisión más antigua {{ \App\Filament\Pages\HoneyBookCutover::when($o['source_observed_at']) }} · vence {{ \App\Filament\Pages\HoneyBookCutover::when($o['expires_at']) }}
                        <x-filament::badge :color="$o['current'] ? 'success' : 'gray'" size="sm">{{ $o['current'] ? 'Vigentes (solo preparación)' : 'Foto de ese momento' }}</x-filament::badge></p>
                    @if (! $o['current'] && $o['missing'])
                        <ul class="wchg-effects">@foreach ($o['missing'] as $c)<li>{{ \App\Filament\Pages\HoneyBookCutover::OPENING_MISSING[$c] ?? $c }}</li>@endforeach</ul>
                    @endif
                    <div class="wimp-table-wrap"><table class="wimp-table">
                        <thead><tr><th>Fiesta</th><th>Total acordado</th><th>Pagado en HoneyBook</th><th>Saldo</th><th>Vence</th><th>Depósito objetivo</th></tr></thead>
                        <tbody>
                            @foreach ($o['items'] as $i)
                                <tr>
                                    <td>{{ $i['title'] }}</td>
                                    <td>{{ \App\Filament\Support\Labels::money($i['original_total_cents']) }}</td>
                                    <td>{{ \App\Filament\Support\Labels::money($i['source_net_received_cents']) }}@if ($i['source_refund_cents']) <small>(devoluciones {{ \App\Filament\Support\Labels::money($i['source_refund_cents']) }})</small>@endif</td>
                                    <td>{{ \App\Filament\Support\Labels::money($i['balance_cents']) }}@if ($i['credit_cents']) <small>· a favor {{ \App\Filament\Support\Labels::money($i['credit_cents']) }}</small>@endif</td>
                                    <td>@if ($i['crm_balance_due_at']){{ \App\Filament\Pages\HoneyBookCutover::when($i['crm_balance_due_at']) }}@elseif ($i['source_balance_due_date']){{ $i['source_balance_due_date'] }} <small>(solo día)</small>@else Pagada @endif</td>
                                    <td>{{ \App\Filament\Support\Labels::money($i['deposit_target_cents']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                    <p class="wqb-hint">Lo pagado en HoneyBook no está verificado en tu banco. Ventas nuevas por estos acuerdos: $0 · dinero nuevo en el CRM: $0.</p>
                @endif
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Pasar al CRM</x-slot>
            <x-slot name="description">{{ \App\Filament\Support\CutoverUi::label('financial_bridge_not_implemented') }} {{ \App\Filament\Support\CutoverUi::label('transfer_not_implemented') }}</x-slot>
            <div class="wrep-actions"><button type="button" class="wcal-btn" disabled>Pasar {{ $r['counts']['events'] }} fiestas al CRM</button></div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
