@php
    $r = $this->report();
    $ops = $r['operations']; $bk = $r['bookings']; $wk = $r['upcoming_weekend'];
    $sch = $ops['scheduled'];
    $staff = fn (array $agg) => $agg['staff_cost_cents'];
    $imported = $bk['imported_confirmation_count_excluded'] ?? null;
@endphp
<x-filament-panels::page>
    @include('filament.pages.reports-panorama')
    @php
        $bd = $this->birthdays();
        $analytics = $this->analytics();
        $day = fn (string $d, string $f = 'D j M') => \Carbon\CarbonImmutable::parse($d, 'America/Chicago')->locale('es')->translatedFormat($f);
    @endphp
    @if ($bd || $analytics)
        <div class="wp">
            <div class="wp-duo">
                @if ($bd)
                    <div class="wp-card">
                        <span class="wp-pill">Cumpleaños · próximos 60 días</span>
                        <div class="wp-big">{{ count($bd['soon']) }}</div>
                        <p>De {{ $bd['count'] }} clientes con fecha confiable. Es solo una lista: no se envía nada.</p>
                        <ul class="wp-list">
                            @forelse (array_slice($bd['soon'], 0, 8) as $i)
                                <li>{{ $i['name'] }} <span>{{ $day($i['next']) }}{{ $i['rebooked'] ? ' · ya reservó otra' : '' }}</span></li>
                            @empty
                                <li>Nadie cumple en los próximos 60 días.</li>
                            @endforelse
                        </ul>
                        @if (count($bd['items']) > 8)
                            <details>
                                <summary class="wp-note" style="cursor:pointer">Ver los {{ count($bd['items']) }}</summary>
                                <ul class="wp-list" style="margin-top:8px">
                                    @foreach ($bd['items'] as $i)
                                        <li>{{ $i['name'] }} <span>{{ $day($i['next'], 'j M Y') }}{{ $i['rebooked'] ? ' · ya reservó otra' : '' }}</span></li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </div>
                @endif
                @if ($analytics)
                    <div class="wp-card">
                        <span class="wp-pill">Exportar a Excel</span>
                        <h2>Análisis del año</h2>
                        <p>{{ $analytics['booking_count'] }} fiestas · ticket promedio {{ $this->money($analytics['ticket']['average_cents']) }} · reservan {{ $analytics['lead_time']['average_days'] ?? '—' }} días antes en promedio.</p>
                        <div class="wp-row">
                            <div class="wp-seg">
                                <select wire:model.live="analyticsYear" aria-label="Año" style="background:transparent;border:0;color:var(--ink);font:600 .85rem inherit;padding:6px 8px;color-scheme:dark">
                                    @for ($year = (int) now('America/Chicago')->year + 1; $year >= 2020; $year--)
                                        <option value="{{ $year }}">{{ $year }}</option>
                                    @endfor
                                </select>
                            </div>
                            <div class="wp-seg"><button type="button" wire:click="exportAnalytics">Descargar CSV</button></div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
    @if ($r['sample'])
        <p class="wmoney-warn" role="note">Datos de ejemplo (vista previa local). Los números reales aparecen cuando la parte de Codex esté instalada.</p>
    @endif

    <div class="wp">
        <div class="wp-head">
            <h2>Cierre {{ $kind === 'weekly' ? 'de la semana' : 'del mes' }}</h2>
            <div class="wp-row">
                <div class="wp-seg">
                    <button type="button" wire:click="setKind('weekly')" aria-pressed="{{ $kind === 'weekly' ? 'true' : 'false' }}">Semana</button>
                    <button type="button" wire:click="setKind('monthly')" aria-pressed="{{ $kind === 'monthly' ? 'true' : 'false' }}">Mes</button>
                </div>
                <div class="wp-nav">
                    <button type="button" wire:click="shift(-1)" aria-label="Anterior">←</button>
                    <b>{{ \App\Filament\Support\ReportsSource::periodLabel($ops['period']) }}</b>
                    <button type="button" wire:click="shift(1)" aria-label="Siguiente" @disabled($this->atLatest())>→</button>
                    @unless ($this->atLatest())<button type="button" wire:click="latest">La más reciente</button>@endunless
                </div>
            </div>
        </div>
        <div class="wp-kpis" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
            <div class="wp-kpi" style="--glow:var(--teal)"><div class="l">Dinero que entró</div><div class="v up">{{ $this->money($ops['cash']['net_received_cents']) }}</div>
                <div class="d">Por la fecha del pago.@if ($ops['cash']['refunded_cents']) Reembolsos: {{ $this->money($ops['cash']['refunded_cents']) }}.@endif</div></div>
            <div class="wp-kpi" style="--glow:var(--gold)"><div class="l">Saldos por cobrar</div><div class="v">{{ $this->money($sch['pending_balance_cents']) }}</div>
                <div class="d">De {{ $sch['event_count'] }} {{ $sch['event_count'] === 1 ? 'fiesta' : 'fiestas' }} de este periodo.@if ($sch['pending_balance_unknown_events']) {{ $sch['pending_balance_unknown_events'] }} sin montos.@endif</div></div>
            <div class="wp-kpi" style="--glow:var(--pink)"><div class="l">Costo del staff</div><div class="v">{{ $this->money($staff($sch['actual'])) }}</div>
                <div class="d">@if ($sch['actual']['pending_events'])Faltan los costos de {{ $sch['actual']['pending_events'] }} {{ $sch['actual']['pending_events'] === 1 ? 'fiesta' : 'fiestas' }}. @endif Estimado: {{ $this->money($staff($sch['estimate'])) }}.</div></div>
            <div class="wp-kpi" style="--glow:var(--purple)"><div class="l">Reservas nuevas</div><div class="v">{{ $bk['booking_count'] }}</div>
                <div class="d">@if ($line = \App\Filament\Support\ReportsSource::packagesLine($bk)){{ $line }}.@else Confirmadas en este periodo.@endif</div></div>
        </div>
        @if ($wk)
            @php($ws = \Carbon\CarbonImmutable::parse($wk['window']['starts_on'], 'America/Chicago')->locale('es'))
            <div class="wp-card">
                <span class="wp-pill">Este fin de semana · {{ $ws->translatedFormat('D j M') }} y {{ $ws->addDay()->translatedFormat('D j M') }}</span>
                <div class="wp-sats">
                    <div class="wp-sat"><b>{{ $wk['event_count'] }}</b>fiestas confirmadas @if ($wk['draft_event_count'])<br>+{{ $wk['draft_event_count'] }} sin confirmar @endif</div>
                    <div class="wp-sat"><b>{{ $this->money($wk['pending_balance_cents']) }}</b>saldos por cobrar</div>
                    <div class="wp-sat"><b>{{ $this->money($staff($wk['estimate'])) }}</b>staff estimado</div>
                    <div class="wp-sat"><b>{{ $this->money($staff($wk['actual'])) }}</b>staff real</div>
                </div>
            </div>
        @endif
    </div>

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

</x-filament-panels::page>
