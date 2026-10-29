@php
    $r = $this->report();
    $ops = $r['operations']; $bk = $r['bookings']; $wk = $r['upcoming_weekend'];
    $sch = $ops['scheduled'];
    $staff = fn (array $agg) => $agg['staff_cost_cents'];
    $imported = $bk['imported_confirmation_count_excluded'] ?? null;
@endphp
<x-filament-panels::page>
    @include('filament.pages.reports-panorama')
    @if ($draft = $this->birthdayDraft())
        <x-filament::section heading="Cumpleaños del próximo año · solo borrador">
            <p>{{ $draft['candidate_count'] }} cumpleaños completados con fecha confiable. Ninguno habilitado para envío: faltan permisos promocionales verificables y aprobación del texto.</p>
            <p>Revisión propuesta un mes antes; no cambia la política de envío ni genera códigos.</p>
            <ul>
                @foreach ($draft['items'] as $item)
                    <li>Cliente #{{ $item['customer_id'] }} · {{ $item['source_date'] }} · revisión: {{ $item['proposed_review_on'] ?? 'Revisar 29 de febrero' }}@if (in_array('already_rebooked', $item['reasons'])) · Ya tiene otra fiesta @endif</li>
                @endforeach
            </ul>
            <p>ES (sin aprobar): {{ $draft['copy']['es'] }}</p>
            <p>EN (not approved): {{ $draft['copy']['en'] }}</p>
        </x-filament::section>
    @endif
    @if ($analytics = $this->analytics())
        <x-filament::section heading="Reservas · CRM e historial HoneyBook">
            <label>Año del análisis
                <select wire:model.live="analyticsYear">
                    @for ($year = 2000; $year <= 2100; $year++)
                        <option value="{{ $year }}">{{ $year }}</option>
                    @endfor
                </select>
            </label>
            <p>Año {{ $analytics['year'] }} · {{ $analytics['booking_count'] }} fiestas confirmadas o completadas. Importes de origen, no recibos bancarios. Borradores y estados desconocidos quedan fuera.</p>
            <p>Ticket promedio: {{ $this->money($analytics['ticket']['average_cents']) }} · {{ $analytics['ticket']['known_count'] }} con monto conocido; {{ $analytics['ticket']['unknown_count'] }} sin monto.</p>
            <p>Anticipación promedio: {{ $analytics['lead_time']['average_days'] ?? 'Pendiente' }} días · {{ $analytics['lead_time']['unknown_count'] }} sin fecha de creación confiable.</p>
            <button type="button" class="wcal-btn" wire:click="exportAnalytics">Exportar agregados CSV</button>
            @foreach (['months' => 'Mes', 'weekdays' => 'Día (1 lunes–7 domingo)', 'packages' => 'Paquete (ID de catálogo)', 'postal_codes' => 'ZIP', 'lead_sources' => 'Cómo nos conoció'] as $dimension => $label)
                <h3>{{ $label }}</h3>
                <ul>
                    @forelse ($analytics[$dimension] as $key => $count)
                        <li>{{ $key === 'unknown' ? 'Sin información' : ($dimension === 'packages' ? ($analytics['package_labels'][$key] ?? $key) : $key) }}: {{ $count }}</li>
                    @empty
                        <li>Sin datos</li>
                    @endforelse
                </ul>
            @endforeach
            <p>Los paquetes históricos sin vínculo al catálogo se muestran sin información; no se deducen del nombre de la fiesta.</p>
        </x-filament::section>
    @endif
    @if ($r['sample'])
        <p class="wmoney-warn" role="note">Datos de ejemplo (vista previa local). Los números reales aparecen cuando la parte de Codex esté instalada.</p>
    @endif

    <div class="wrep-bar">
        <div class="wcal-modes">
            <button type="button" class="wcal-btn {{ $kind === 'weekly' ? 'on' : '' }}" wire:click="setKind('weekly')">Semana</button>
            <button type="button" class="wcal-btn {{ $kind === 'monthly' ? 'on' : '' }}" wire:click="setKind('monthly')">Mes</button>
        </div>
        <div class="wrep-nav">
            <button type="button" class="wcal-btn" wire:click="shift(-1)" aria-label="Anterior">←</button>
            <b>{{ \App\Filament\Support\ReportsSource::periodLabel($ops['period']) }}</b>
            <button type="button" class="wcal-btn" wire:click="shift(1)" aria-label="Siguiente" @disabled($this->atLatest())>→</button>
            @unless ($this->atLatest())<button type="button" class="wcal-btn" wire:click="latest">La más reciente</button>@endunless
        </div>
    </div>

    <div class="wmoney-grid wrep-grid">
        <div><span>Dinero que entró</span><b class="ok">{{ $this->money($ops['cash']['net_received_cents']) }}</b>
            <small>Depósitos y pagos verificados en el CRM, por la fecha en que llegaron.@if ($ops['cash']['refunded_cents']) Reembolsos: {{ $this->money($ops['cash']['refunded_cents']) }}.@endif</small></div>
        <div><span>Saldos por cobrar</span><b>{{ $this->money($sch['pending_balance_cents']) }}</b>
            <small>De {{ $sch['event_count'] }} {{ $sch['event_count'] === 1 ? 'fiesta' : 'fiestas' }} de este periodo, a hoy.@if ($sch['pending_balance_unknown_events']) {{ $sch['pending_balance_unknown_events'] }} sin montos fijados.@endif</small></div>
        <div><span>Costo del staff</span><b>{{ $this->money($staff($sch['actual'])) }}</b>
            <small>Real registrado.@if ($sch['actual']['pending_events']) Faltan los costos de {{ $sch['actual']['pending_events'] }} {{ $sch['actual']['pending_events'] === 1 ? 'fiesta' : 'fiestas' }}.@endif Estimado: {{ $this->money($staff($sch['estimate'])) }}.</small></div>
        <div><span>Reservas confirmadas</span><b>{{ $bk['booking_count'] }}</b>
            <small>Nuevas en este periodo.@if ($line = \App\Filament\Support\ReportsSource::packagesLine($bk)) {{ $line }}.@endif @if ($bk['package_unknown_booking_count']) {{ $bk['package_unknown_booking_count'] }} sin paquete registrado.@endif @if ($imported) {{ $imported }} importadas de HoneyBook (no cuentan como nuevas).@endif</small></div>
    </div>

    @if ($wk)
        @php($ws = \Carbon\CarbonImmutable::parse($wk['window']['starts_on'], 'America/Chicago')->locale('es'))
        <x-filament::section>
            <x-slot name="heading">Fin de semana siguiente · {{ $ws->translatedFormat('D j M') }} y {{ $ws->addDay()->translatedFormat('D j M') }}</x-slot>
            <x-slot name="description">Solo fiestas con staff apartado. Los borradores se cuentan aparte.</x-slot>
            <dl class="wfile-dl">
                <div><dt>Fiestas confirmadas</dt><dd>{{ $wk['event_count'] }}@if ($wk['draft_event_count']) <small>· {{ $wk['draft_event_count'] }} {{ $wk['draft_event_count'] === 1 ? 'borrador' : 'borradores' }} sin confirmar</small>@endif</dd></div>
                <div><dt>Saldos por cobrar</dt><dd>{{ $this->money($wk['pending_balance_cents']) }}@if ($wk['pending_balance_unknown_events']) <small>· {{ $wk['pending_balance_unknown_events'] }} sin montos</small>@endif</dd></div>
                <div><dt>Staff estimado</dt><dd>{{ $this->money($staff($wk['estimate'])) }}@if ($wk['estimate']['pending_events']) <small>· faltan {{ $wk['estimate']['pending_events'] }}</small>@endif</dd></div>
                <div><dt>Staff real</dt><dd>{{ $this->money($staff($wk['actual'])) }}@if ($wk['actual']['pending_events']) <small>· se registra después de cada fiesta</small>@endif</dd></div>
            </dl>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Reporte para sales@waooparty.com</x-slot>
        <x-slot name="description">Se guarda tal cual este periodo cerrado. Todavía no se envía: los correos se activan aparte.</x-slot>
        <x-slot name="afterHeader"><button type="button" class="wcal-btn on" wire:click="prepare">Preparar y ver el correo</button></x-slot>
        @if ($emailPreview)
            <div class="wrep-mail">
                <p><b>Asunto:</b> {{ $emailPreview['subject'] }} <x-filament::badge color="warning" size="sm">Preparado, sin enviar</x-filament::badge></p>
                <dl class="wfile-dl">
                    @foreach ($emailPreview['sections'] as $s)<div><dt>{{ $s['label'] }}</dt><dd>{{ $s['value'] }}</dd></div>@endforeach
                </dl>
                <small>Para: {{ $emailPreview['recipient'] }} · texto aún no aprobado · envío desactivado</small>
            </div>
        @else
            <p class="wqb-hint">Estado: {{ ($r['delivery']['status'] ?? 'not_sent') === 'not_sent' ? 'no enviado' : $r['delivery']['status'] }}.</p>
        @endif
    </x-filament::section>

    <p class="wqb-hint">Cómo se cuenta: “Dinero que entró” usa la fecha del pago; “Saldos” y “Costo del staff” usan la fecha de cada fiesta. Por eso no se restan entre sí. “Reservas confirmadas” cuenta la primera vez que se apartó el staff de cada fiesta en el CRM; lo importado de HoneyBook no cuenta como venta nueva. Un costo que falta dice “Pendiente”, nunca $0.</p>
</x-filament-panels::page>
