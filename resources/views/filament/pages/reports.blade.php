@php
    $r = $this->report();
    $ops = $r['operations']; $bk = $r['bookings']; $wk = $r['weekend'];
    $sch = $ops['scheduled'];
    $weekendLabel = now('America/Chicago')->isWeekend() && $period === 'week' && $anchor === now('America/Chicago')->toDateString() ? 'Este fin de semana' : 'Fin de semana';
    $wkStart = \Carbon\CarbonImmutable::parse($wk['window']['starts_on'], 'America/Chicago')->locale('es');
    $staff = fn (array $agg) => $agg['staff_cost_cents'];
@endphp
<x-filament-panels::page>
    @if ($r['sample'])
        <p class="wmoney-warn" role="note">Datos de ejemplo (vista previa local). Los números reales aparecen cuando la parte de Codex esté instalada.</p>
    @endif

    <div class="wrep-bar">
        <div class="wcal-modes">
            <button type="button" class="wcal-btn {{ $period === 'week' ? 'on' : '' }}" wire:click="setPeriod('week')">Semana</button>
            <button type="button" class="wcal-btn {{ $period === 'month' ? 'on' : '' }}" wire:click="setPeriod('month')">Mes</button>
        </div>
        <div class="wrep-nav">
            <button type="button" class="wcal-btn" wire:click="move(-1)" aria-label="Anterior">←</button>
            <b>{{ \App\Filament\Support\ReportsSource::periodLabel($ops['period']) }}</b>
            <button type="button" class="wcal-btn" wire:click="move(1)" aria-label="Siguiente">→</button>
            <button type="button" class="wcal-btn" wire:click="today">Hoy</button>
        </div>
    </div>

    <div class="wmoney-grid wrep-grid">
        <div><span>Dinero que entró</span><b class="ok">{{ $this->money($ops['cash']['net_received_cents']) }}</b>
            <small>Depósitos y pagos verificados, por la fecha en que llegaron.@if ($ops['cash']['refunded_cents']) Reembolsos: {{ $this->money($ops['cash']['refunded_cents']) }}.@endif</small></div>
        <div><span>Saldos por cobrar</span><b>{{ $this->money($sch['pending_balance_cents']) }}</b>
            <small>De las {{ $sch['event_count'] }} {{ $sch['event_count'] === 1 ? 'fiesta' : 'fiestas' }} de este periodo.@if ($sch['pending_balance_unknown_events']) {{ $sch['pending_balance_unknown_events'] }} sin montos fijados.@endif</small></div>
        <div><span>Costo del staff</span><b>{{ $this->money($staff($sch['actual'])) }}</b>
            <small>Real.@if ($sch['actual']['pending_events']) Faltan los costos de {{ $sch['actual']['pending_events'] }} {{ $sch['actual']['pending_events'] === 1 ? 'fiesta' : 'fiestas' }}.@endif Estimado: {{ $this->money($staff($sch['estimate'])) }}.</small></div>
        <div><span>Reservas confirmadas</span><b>{{ $bk['booking_count'] }}</b>
            <small>Por primera vez en este periodo.@if ($line = \App\Filament\Support\ReportsSource::packagesLine($bk)) {{ $line }}.@endif @if ($bk['package_unknown_booking_count']) {{ $bk['package_unknown_booking_count'] }} sin paquete registrado.@endif</small></div>
    </div>

    <x-filament::section>
        <x-slot name="heading">{{ $weekendLabel }} · {{ $wkStart->translatedFormat('D j M') }} y {{ $wkStart->addDay()->translatedFormat('D j M') }}</x-slot>
        <x-slot name="description">Solo fiestas con staff apartado. Los borradores se cuentan aparte.</x-slot>
        <dl class="wfile-dl">
            <div><dt>Fiestas confirmadas</dt><dd>{{ $wk['event_count'] }}@if ($wk['draft_event_count']) <small>· {{ $wk['draft_event_count'] }} {{ $wk['draft_event_count'] === 1 ? 'borrador' : 'borradores' }} sin confirmar</small>@endif</dd></div>
            <div><dt>Saldos por cobrar</dt><dd>{{ $this->money($wk['pending_balance_cents']) }}@if ($wk['pending_balance_unknown_events']) <small>· {{ $wk['pending_balance_unknown_events'] }} sin montos</small>@endif</dd></div>
            <div><dt>Staff estimado</dt><dd>{{ $this->money($staff($wk['estimate'])) }}@if ($wk['estimate']['pending_events']) <small>· faltan {{ $wk['estimate']['pending_events'] }}</small>@endif</dd></div>
            <div><dt>Staff real</dt><dd>{{ $this->money($staff($wk['actual'])) }}@if ($wk['actual']['pending_events']) <small>· se registra después de cada fiesta</small>@endif</dd></div>
        </dl>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Reporte del lunes y del mes</x-slot>
        <x-slot name="description">Se guarda tal cual para enviarlo a sales@waooparty.com. Todavía no se envía: los correos se activan aparte.</x-slot>
        <div class="wrep-actions">
            <button type="button" class="wcal-btn on" wire:click="prepare('weekly')">Preparar el de la semana pasada</button>
            <button type="button" class="wcal-btn" wire:click="prepare('monthly')">Preparar el del mes pasado</button>
        </div>
        @if ($prepared)
            <dl class="wfile-dl" style="margin-top:12px">
                <div><dt>Periodo</dt><dd>{{ $prepared['starts'] }} → {{ $prepared['ends'] }}</dd></div>
                <div><dt>Dinero que entró</dt><dd>{{ $this->money($prepared['net']) }}</dd></div>
                <div><dt>Reservas confirmadas</dt><dd>{{ $prepared['bookings'] ?? '—' }}@if ($prepared['packages']) <small>· {{ $prepared['packages'] }}</small>@endif</dd></div>
                <div><dt>Costo del staff</dt><dd>{{ $this->money($prepared['staff']) }}</dd></div>
                <div><dt>Para</dt><dd>{{ $prepared['recipient'] }} <small>· no enviado</small></dd></div>
            </dl>
        @endif
    </x-filament::section>

    <p class="wqb-hint">Cómo se cuenta: “Dinero que entró” usa la fecha del pago; “Saldos” y “Costo del staff” usan la fecha de cada fiesta. Por eso no se restan entre sí. “Reservas confirmadas” cuenta la primera vez que se apartó el staff de cada fiesta. Un costo que falta dice “Pendiente”, nunca $0.</p>
</x-filament-panels::page>
