@php($m = fn ($v) => ($v < 0 ? '−' : '').'$'.number_format(abs($v), abs($v) == floor(abs($v)) ? 0 : 2))
<x-filament-panels::page>
    <p class="wmoney-warn" role="note">Vista previa de diseño (CHANGE-A). Datos de ejemplo; nada se guarda ni se envía al cliente.</p>

    <div class="wcal-modes">
        <button type="button" class="wcal-btn {{ $mode === 'reschedule' ? 'on' : '' }}" wire:click="setMode('reschedule')">Reprogramar</button>
        <button type="button" class="wcal-btn {{ $mode === 'cancel' ? 'on' : '' }}" wire:click="setMode('cancel')">Cancelar</button>
    </div>

    @if ($mode === 'reschedule')
        @php($r = $this->reschedule())
        <x-filament::section>
            <x-slot name="heading">Hoy → Propuesta</x-slot>
            <x-slot name="description">La fecha actual no se libera hasta que el cliente acepta el cambio.</x-slot>
            <div class="wimp-table-wrap"><table class="wimp-table">
                <thead><tr><th></th><th>Hoy</th><th>Propuesta</th></tr></thead>
                <tbody>
                    <tr><td>Fecha y hora</td><td>{{ $r['now']['when'] }}</td><td><b>{{ $r['new']['when'] }}</b></td></tr>
                    <tr><td>Lugar</td><td>{{ $r['now']['place'] }}</td><td>{{ $r['new']['place'] }}</td></tr>
                    <tr><td>Equipo</td><td>{{ $r['now']['team'] }}</td><td>{{ $r['new']['team'] }}</td></tr>
                </tbody>
            </table></div>
            <ul class="wchg-checks">
                @foreach ($r['checks'] as [$state, $text])
                    <li><x-filament::badge :color="$state === 'ok' ? 'success' : 'warning'" size="sm">{{ $state === 'ok' ? 'Listo' : 'Revisar' }}</x-filament::badge> {{ $text }}</li>
                @endforeach
            </ul>
        </x-filament::section>

        @php($before = array_sum(array_column($r['money'], 1)))
        @php($after = array_sum(array_column($r['money'], 2)))
        <x-filament::section>
            <x-slot name="heading">Dinero: antes → después</x-slot>
            <x-slot name="description">Se crea una versión nueva; lo pagado y el contrato anterior se conservan.</x-slot>
            <div class="wimp-table-wrap"><table class="wimp-table">
                <thead><tr><th></th><th>Antes</th><th>Después</th><th>Diferencia</th></tr></thead>
                <tbody>
                    @foreach ($r['money'] as [$label, $a, $b])
                        <tr><td>{{ $label }}</td><td>{{ $m($a) }}</td><td>{{ $m($b) }}</td><td>{{ $b - $a ? $m($b - $a) : '—' }}</td></tr>
                    @endforeach
                    <tr><td><b>Total</b></td><td><b>{{ $m($before) }}</b></td><td><b>{{ $m($after) }}</b></td><td><b>{{ $after - $before ? $m($after - $before) : '—' }}</b></td></tr>
                    <tr><td>Pagado</td><td colspan="3">{{ $m($r['paid']) }} · saldo nuevo {{ $m($after - $r['paid']) }}</td></tr>
                </tbody>
            </table></div>
            <label class="wimp-check"><input type="checkbox" wire:model.live="waive"> No cobrar el cargo por cambio (escribe el motivo)</label>
            @if ($waive)<input type="text" class="wchg-reason" placeholder="Motivo (obligatorio)" disabled>@endif
            <p class="wqb-hint">{{ $r['count'] }} Precios: se mantienen los originales hasta que César decida otra regla.</p>
            <div class="wrep-actions"><button type="button" class="wcal-btn on" disabled>Preparar adenda para el cliente</button></div>
        </x-filament::section>
    @else
        @php($c = $this->cancel())
        <x-filament::section>
            <x-slot name="heading">Cancelar la fiesta del {{ $c['when'] }}</x-slot>
            <x-slot name="description">Según el contrato que firmó. Puedes hacer una excepción escribiendo el motivo.</x-slot>
            <ul class="wchg-checks">
                @foreach ($c['rules'] as [$text, $applies])
                    <li><x-filament::badge :color="$applies ? 'info' : 'gray'" size="sm">{{ $applies ? 'Aplica' : 'No aplica' }}</x-filament::badge> {{ $text }}</li>
                @endforeach
            </ul>
            <p><b>Resultado:</b> {{ $c['outcome'] }}</p>
            <ul class="wchg-effects">@foreach ($c['effects'] as $e)<li>{{ $e }}</li>@endforeach</ul>
            <input type="text" class="wchg-reason" placeholder="Motivo de la cancelación (obligatorio)" disabled>
            <div class="wrep-actions"><button type="button" class="wcal-btn" disabled>Hacer una excepción</button><button type="button" class="wcal-btn on" disabled>Cancelar la fiesta</button></div>
            <p class="wqb-hint">Un reembolso, si lo decides, se hace aparte desde Dinero. El aviso al cliente se envía cuando los correos estén activos.</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
