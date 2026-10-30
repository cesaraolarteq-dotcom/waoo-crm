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
        $anniv = $this->anniversary();
        $skipLabels = ['already_rebooked' => 'ya habían reservado otra vez', 'no_email' => 'sin correo'];
        $day = fn (string $d, string $f = 'D j M') => \Carbon\CarbonImmutable::parse($d, 'America/Chicago')->locale('es')->translatedFormat($f);
    @endphp
    @if ($bd || $analytics)
        <div class="wp">
            @if ($anniv)
                <div class="wp-card" style="margin-bottom:16px">
                    <span class="wp-pill">Un año después · efectividad</span>
                    <div class="wp-big">{{ $anniv['rate'] === null ? '—' : rtrim(rtrim(number_format($anniv['rate'], 1), '0'), '.').' %' }}</div>
                    <p>{{ $anniv['issued'] }} {{ $anniv['issued'] === 1 ? 'cliente recibió' : 'clientes recibieron' }} su código y {{ $anniv['redeemed'] }} {{ $anniv['redeemed'] === 1 ? 'reservó' : 'reservaron' }} con él.
                        @if ($anniv['sent'] < $anniv['issued']) {{ $anniv['issued'] - $anniv['sent'] }} códigos están listos pero el correo sigue apagado: todavía no se envían. @endif</p>
                    <ul class="wp-list">
                        <li>Dinero de esas reservas <span>{{ $this->money($anniv['revenue_cents']) }}</span></li>
                        <li>Descuento que diste <span>{{ $this->money($anniv['discount_cents']) }}</span></li>
                        <li>Códigos vencidos sin usar <span>{{ $anniv['expired'] }}</span></li>
                        @if ($anniv['cancelled'])<li>Reservas con código que se cancelaron <span>{{ $anniv['cancelled'] }}</span></li>@endif
                        @foreach ($anniv['skipped'] as $reason => $n)
                            <li>No se les manda: {{ $skipLabels[$reason] ?? $reason }} <span>{{ $n }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="wp-duo">
                @if ($bd)
                    <div class="wp-card">
                        <span class="wp-pill">Cumpleaños · próximos 60 días</span>
                        <div class="wp-big">{{ count($bd['soon']) }}</div>
                        <p>De {{ $bd['count'] }} clientes que celebraron un cumpleaños con Waoo. Es solo una lista: no se envía nada.</p>
                        <ul class="wp-list">
                            @forelse (array_slice($bd['soon'], 0, 12) as $i)
                                <li>{{ $i['name'] }} <span>{{ $day($i['next']) }}{{ $i['rebooked'] ? ' · ya reservó otra' : '' }}</span></li>
                            @empty
                                <li>Nadie cumple en los próximos 60 días.</li>
                            @endforelse
                        </ul>
                        @if (count($bd['items']) > 12)
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
        @php
            $cf = $this->closeFigures($ops['period']); $ck = $cf['kpis'];
            $wf = $wk ? $this->closeFigures(['starts_on' => $wk['window']['starts_on'], 'ends_before' => \Carbon\CarbonImmutable::parse($wk['window']['starts_on'], 'America/Chicago')->addDays(2)->toDateString()]) : null;
        @endphp
        <div class="wp-kpis">
            <div class="wp-kpi" style="--glow:var(--purple)"><div class="l">Fiestas</div><div class="v">{{ $ck['parties']['value'] }}</div>
                <div class="d">Por la fecha de la fiesta.</div></div>
            <div class="wp-kpi" style="--glow:var(--gold)"><div class="l">Ventas</div><div class="v">{{ $this->money($ck['sales']['value']) }}</div>
                <div class="d">@if ($ck['sales']['unknown']){{ $ck['sales']['unknown'] }} sin monto.@else Total de esas fiestas.@endif</div></div>
            <div class="wp-kpi" style="--glow:var(--teal)"><div class="l">Dinero que entró</div><div class="v up">{{ $this->money($ck['received']['value']) }}</div>
                <div class="d">{{ $cf['pulse_all']['count'] }} {{ $cf['pulse_all']['count'] === 1 ? 'pago' : 'pagos' }} por la fecha del pago: {{ $cf['pulse']['count'] }} {{ $cf['pulse']['count'] === 1 ? 'depósito nuevo' : 'depósitos nuevos' }}.</div></div>
            <div class="wp-kpi" style="--glow:var(--pink)"><div class="l">Saldos por cobrar</div><div class="v">{{ $this->money($ck['balance']['value']) }}</div>
                <div class="d">De las fiestas de este periodo.@if ($ck['balance']['unknown']) {{ $ck['balance']['unknown'] }} sin saldo conocido.@endif</div></div>
            <div class="wp-kpi" style="--glow:var(--navy)"><div class="l">Costo del staff</div><div class="v">{{ $this->money($staff($sch['actual'])) }}</div>
                <div class="d">Solo fiestas con costos registrados en el CRM.</div></div>
        </div>
        @if ($wf)
            @php($ws = \Carbon\CarbonImmutable::parse($wk['window']['starts_on'], 'America/Chicago')->locale('es'))
            <div class="wp-card">
                <span class="wp-pill">Próximo fin de semana · {{ $ws->translatedFormat('D j M') }} y {{ $ws->addDay()->translatedFormat('D j M') }}</span>
                <div class="wp-sats">
                    <div class="wp-sat"><b>{{ $wf['kpis']['parties']['value'] }}</b>fiestas</div>
                    <div class="wp-sat"><b>{{ $this->money($wf['kpis']['sales']['value']) }}</b>en ventas</div>
                    <div class="wp-sat"><b>{{ $this->money($wf['kpis']['balance']['value']) }}</b>saldos por cobrar</div>
                    <div class="wp-sat"><b>{{ $this->money($staff($wk['estimate'])) }}</b>staff estimado</div>
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
