@php
    // Panorama (César 2026-10-28): one dark "control room" block, same look in light and dark Filament themes.
    $p = $this->panorama();
    $usd = fn (?int $c) => $c === null ? 'Pendiente' : '$'.number_format(intdiv($c + 50, 100));
    $prevYear = substr($p['previous']['from'], 0, 4) === substr($p['previous']['to'], 0, 4) ? substr($p['previous']['from'], 0, 4) : 'el año anterior';
    $delta = function (array $k) use ($prevYear) {
        if ($k['change_pct'] === null) return ['Sin datos de '.$prevYear.' para comparar', ''];
        return [($k['change_pct'] >= 0 ? '+' : '').$k['change_pct'].' % vs '.$prevYear, $k['change_pct'] >= 0 ? 'up' : 'down'];
    };
    $heat = fn (array $w) => $w['count'] === 0 ? 'h0' : ($w['heat'] < 0.5 ? 'c2' : ($w['heat'] < 0.85 ? 'c1' : ($w['heat'] < 1.15 ? 'm' : ($w['heat'] < 1.6 ? 'w1' : 'w2'))));
    $dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
    $fecha = fn (string $d, string $f = 'D j M') => \Carbon\CarbonImmutable::parse($d, 'America/Chicago')->locale('es')->translatedFormat($f);
    $pulseModes = ['deposit' => ['pulse', 'Reservas', 'depósitos', 'Primer pago de cada fiesta: el día en que reservan.'],
        'balance' => ['pulse_balance', 'Segunda mitad', 'pagos de saldo', 'Pagos después del depósito: el saldo antes de la fiesta.'],
        'all' => ['pulse_all', 'Todo', 'pagos', 'Cada pago que entró, depósito o saldo.']];
    [$pulseKey, $pulseTitle, $pulseNoun, $pulseHelp] = $pulseModes[$pulseMode] ?? $pulseModes['deposit'];
    $pulse = $p[$pulseKey];
    $countLabel = fn (int $n) => $pulseMode === 'deposit' ? $n.' '.($n === 1 ? 'fiesta' : 'fiestas') : $n.' '.($n === 1 ? 'pago' : 'pagos');
    $wdMax = max([1, ...array_values($pulse['weekday'])]);
    $domMax = max([0.01, ...array_values($pulse['day_of_month_per_day'])]);
    $wdSorted = $pulse['weekday']; arsort($wdSorted);
    $tone = ['up' => '--teal', 'down' => '--hot', 'hot' => '--hot', 'cold' => '--cold', 'info' => '--gold'];
    $channels = $p['channels'];
    $chMax = max([1, ...array_column($channels, 'parties')]);
    $bestTicket = collect($channels)->where('known', '>=', 3)->where('key', '!=', 'unknown')->sortByDesc('average_cents')->first();
    $zMax = max([1, ...array_column($p['zones'], 'parties')]);
    $tabs = ['ventas' => 'Ventas', 'pulso' => 'Pulso del dinero', 'canales' => 'De dónde vienen', 'zonas' => 'Zonas', 'viene' => 'Lo que viene'];
@endphp
<div class="wp" wire:loading.class="wp-busy" wire:target="setWindow,moveWindow,rangeFrom,rangeTo">
    <style>
        .wp{--bg:#070a1c;--panel:#0f1430;--line:#1f2750;--ink:#eef0ff;--muted:#8c93c3;--gold:#f9c930;--teal:#15d1c4;--pink:#ff3d97;--purple:#9b6bff;--navy:#3a5bff;--hot:#ff7a59;--cold:#6aa7ff;
            color-scheme:dark;background:radial-gradient(900px 420px at 85% -10%,#1a1f55 0%,transparent 60%),var(--bg);color:var(--ink);border-radius:24px;padding:22px;display:grid;gap:18px;font-variant-numeric:tabular-nums;transition:opacity .15s}
        .wp-busy{opacity:.55}
        .wp *{box-sizing:border-box}
        .wp h2{margin:0;font-size:1.05rem;font-weight:700;color:var(--ink)}
        .wp p{margin:0;color:var(--muted);font-size:.88rem;line-height:1.5}
        .wp-head{display:flex;flex-wrap:wrap;gap:12px 20px;align-items:center;justify-content:space-between}
        .wp-title{font-size:clamp(1.5rem,3.5vw,2.1rem);font-weight:800;letter-spacing:-.02em;line-height:1}
        .wp-title span{background:linear-gradient(90deg,var(--gold),var(--pink));-webkit-background-clip:text;background-clip:text;color:transparent}
        .wp-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
        .wp-seg{display:flex;gap:4px;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:4px}
        .wp-seg button,.wp-tabs button,.wp-nav button{font:600 .85rem inherit;color:var(--muted);background:none;border:0;border-radius:9px;padding:8px 12px;cursor:pointer;min-height:36px}
        .wp-seg button[aria-pressed=true]{background:#1f2860;color:var(--ink)}
        .wp-nav{display:flex;align-items:center;gap:6px;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:4px}
        .wp-nav b{min-width:9ch;text-align:center;font-size:.92rem}
        .wp-range{display:flex;gap:8px;align-items:center}
        .wp-range input{background:var(--panel);border:1px solid var(--line);color:var(--ink);border-radius:10px;padding:7px 9px;color-scheme:dark}
        .wp-note{font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
        .wp-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}
        .wp-kpi{background:linear-gradient(180deg,#121842,#0d1230);border:1px solid var(--line);border-radius:18px;padding:14px 16px;position:relative;overflow:hidden}
        .wp-kpi::after{content:"";position:absolute;right:-40px;bottom:-60px;width:130px;height:130px;border-radius:50%;background:var(--glow);filter:blur(40px);opacity:.35}
        .wp-kpi .v{font-size:clamp(1.3rem,2.6vw,1.9rem);font-weight:800;letter-spacing:-.02em;line-height:1.15}
        .wp-kpi .l{color:var(--muted);font-size:.82rem}
        .wp-kpi .d{font-size:.74rem;margin-top:4px;color:var(--muted)}
        .wp .up{color:var(--teal)}.wp .down{color:var(--hot)}.wp .hot{color:var(--hot)}.wp .cold{color:var(--cold)}
        .wp-tabs{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid var(--line);padding-bottom:8px}
        .wp-tabs button[aria-selected=true]{color:var(--ink);background:var(--panel);box-shadow:inset 0 0 0 1px var(--line)}
        .wp-stage{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:14px}
        .wp-duo{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
        .wp-card{background:var(--panel);border:1px solid var(--line);border-radius:20px;padding:18px;min-width:0;display:grid;gap:10px;align-content:start}
        .wp-pill{justify-self:start;font-size:.66rem;letter-spacing:.06em;text-transform:uppercase;padding:3px 8px;border-radius:99px;background:#1b2250;color:var(--gold)}
        .wp-big{font-size:2.2rem;font-weight:800;line-height:1}
        .wp-cols{display:flex;align-items:flex-end;gap:4px;height:180px;padding-top:8px}
        .wp-cols button{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;gap:4px;height:100%;justify-content:flex-end;background:none;border:0;padding:0;cursor:pointer;border-radius:6px}
        .wp-cols .bar{position:relative;width:100%;max-width:38px;flex:1;display:flex;align-items:flex-end;justify-content:center}
        .wp-cols i{display:block;width:100%;border-radius:6px 6px 2px 2px;background:linear-gradient(180deg,var(--gold),var(--pink));min-height:2px;position:relative;z-index:1}
        .wp-cols s{position:absolute;bottom:0;left:-3px;right:-3px;border:1.5px dashed rgba(238,240,255,.35);border-bottom:0;border-radius:6px 6px 0 0;text-decoration:none}
        .wp-cols button[aria-pressed=true] i{background:linear-gradient(180deg,var(--teal),var(--navy))}
        .wp-ins{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:0;padding:0;list-style:none}
        .wp-ins li{background:linear-gradient(180deg,#121842,#0d1230);border:1px solid var(--line);border-radius:18px;padding:14px 16px;display:grid;gap:4px;align-content:start;position:relative;overflow:hidden}
        .wp-ins li::before{content:"";position:absolute;inset:0 auto 0 0;width:3px;background:var(--tone)}
        .wp-ins .t{font-size:.66rem;letter-spacing:.06em;text-transform:uppercase;color:var(--tone)}
        .wp-ins .b{font-size:1.45rem;font-weight:800;letter-spacing:-.02em;line-height:1.1;color:var(--ink)}
        .wp-ins .x{font-size:.82rem;color:var(--muted);line-height:1.45}
        .wp-bar.two{grid-template-columns:minmax(70px,110px) minmax(0,1fr) minmax(110px,auto)}
        .wp-bar.two b span{color:var(--ink);font-weight:700}
        .wp-cols small{font-size:.62rem;color:var(--muted);white-space:nowrap;overflow:hidden;max-width:100%}
        .wp-bars{display:grid;gap:9px}
        .wp-bar{display:grid;grid-template-columns:minmax(70px,120px) minmax(0,1fr) auto;gap:10px;align-items:center;font-size:.86rem}
        .wp-bar i{display:block;height:10px;border-radius:99px;background:linear-gradient(90deg,var(--c1,var(--teal)),var(--c2,var(--navy)));min-width:2px}
        .wp-bar b{font-size:.78rem;color:var(--muted);font-weight:500;text-align:right}
        .wp-heat{display:grid;grid-template-columns:repeat(auto-fill,minmax(16px,1fr));gap:4px}
        .wp-heat i{aspect-ratio:1;border-radius:4px;display:block}
        .wp .h0{background:#141a3a}.wp .c2{background:#1f4f9e}.wp .c1{background:#4f86d6}.wp .m{background:#6f6aa8}.wp .w1{background:#e0805e}.wp .w2{background:#ff5a36}
        .wp-legend{display:flex;gap:6px;align-items:center;font-size:.72rem;color:var(--muted)}
        .wp-legend i{width:14px;height:14px;border-radius:4px;display:inline-block}
        .wp-list{display:grid;gap:6px;margin:0;padding:0;list-style:none;font-size:.86rem}
        .wp-list li{display:flex;justify-content:space-between;gap:10px;border-bottom:1px solid var(--line);padding-bottom:6px}
        .wp-list span{color:var(--muted)}
        .wp-sats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
        .wp-sat{border:1px solid var(--line);border-radius:12px;padding:8px;text-align:center;font-size:.8rem;color:var(--muted)}
        .wp-sat b{display:block;font-size:1.3rem;color:var(--ink)}
        .wp-sat.light{border-color:var(--teal);color:var(--teal)}
        .wp table{width:100%;border-collapse:collapse;font-size:.86rem}
        .wp td,.wp th{padding:6px 4px;border-bottom:1px solid var(--line);text-align:left}
        .wp th{color:var(--muted);font-weight:500;font-size:.76rem}
        .wp button:focus-visible{outline:2px solid var(--teal);outline-offset:2px}
        @media (max-width:900px){.wp-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.wp-stage,.wp-duo{grid-template-columns:minmax(0,1fr)}}
        @media (prefers-reduced-motion:reduce){.wp{transition:none}}
    </style>

    <div class="wp-head">
        <div class="wp-title">Panorama <span>Waoo</span></div>
        <div class="wp-row" role="group" aria-label="Periodo">
            <div class="wp-seg">
                @foreach (['year' => 'Año', 'month' => 'Mes', 'week' => 'Semana', 'custom' => 'De … a …'] as $k => $label)
                    <button type="button" wire:click="setWindow('{{ $k }}')" aria-pressed="{{ $window === $k ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
            @if ($window === 'custom')
                <div class="wp-range">
                    <input type="date" id="wp-from" wire:model.live="rangeFrom" aria-label="Desde">
                    <span>→</span>
                    <input type="date" id="wp-to" wire:model.live="rangeTo" aria-label="Hasta">
                </div>
            @else
                <div class="wp-nav">
                    <button type="button" wire:click="moveWindow(-1)" aria-label="Periodo anterior">←</button>
                    <b>{{ $this->windowLabel($p) }}</b>
                    <button type="button" wire:click="moveWindow(1)" aria-label="Periodo siguiente">→</button>
                </div>
            @endif
        </div>
    </div>
    <div class="wp-note">{{ $fecha($p['from'], 'j M Y') }} – {{ $fecha($p['to'], 'j M Y') }} · comparado con las mismas fechas de {{ $prevYear }} · historial HoneyBook + CRM</div>

    <div class="wp-kpis">
        @foreach ([
            ['parties', 'Fiestas', fn ($v) => $v, '--purple'],
            ['sales', 'Ventas', $usd, '--gold'],
            ['received', 'Dinero recibido', $usd, '--teal'],
            ['ticket', 'Ticket promedio', $usd, '--pink'],
            ['leads', 'Prospectos de la web', fn ($v) => $v, '--navy'],
        ] as [$key, $label, $fmt, $glow])
            @php [$text, $cls] = $delta($p['kpis'][$key]); @endphp
            <div class="wp-kpi" style="--glow:var({{ $glow }})">
                <div class="l">{{ $label }}</div>
                <div class="v">{{ $fmt($p['kpis'][$key]['value']) }}</div>
                <div class="d {{ $cls }}">{{ $text }}</div>
            </div>
        @endforeach
    </div>

    @if ($p['insights'])
        <ul class="wp-ins" aria-label="Lo más importante">
            @foreach ($p['insights'] as $i)
                <li style="--tone:var({{ $tone[$i['tone']] ?? '--gold' }})"><span class="t">{{ $i['tag'] }}</span><span class="b">{{ $i['big'] }}</span><span class="x">{{ $i['text'] }}</span></li>
            @endforeach
        </ul>
    @endif

    <nav class="wp-tabs" role="tablist" aria-label="Vista">
        @foreach ($tabs as $k => $label)
            <button type="button" role="tab" wire:click="setTab('{{ $k }}')" aria-selected="{{ $tab === $k ? 'true' : 'false' }}">{{ $label }}</button>
        @endforeach
    </nav>

    @if ($tab === 'ventas')
        <div class="wp-stage">
            <div class="wp-card">
                <h2>Fiestas por {{ ['day' => 'día', 'week' => 'semana', 'month' => 'mes'][$p['sales']['unit']] }}</h2>
                <p>Por la fecha de cada fiesta. Toca una barra para ver qué fiestas son.@if ($p['sales']['has_last_year']) La línea punteada es {{ $prevYear }} en las mismas fechas.@endif</p>
                <div class="wp-cols">
                    @foreach ($p['sales']['buckets'] as $b)
                        <button type="button" wire:click="setFocus('{{ $b['start'] }}')" aria-pressed="{{ $focus === $b['start'] ? 'true' : 'false' }}"
                            title="{{ $b['label'] }}: {{ $b['parties'] }} fiestas · {{ $usd($b['sales_cents']) }} · {{ $prevYear }}: {{ $b['last_year'] }}">
                            <span class="bar">
                                @if ($b['last_year'])<s style="height:{{ round($b['last_year'] * 100 / $p['sales']['max']) }}%"></s>@endif
                                <i style="height:{{ round($b['parties'] * 100 / $p['sales']['max']) }}%"></i>
                            </span>
                            @if (count($p['sales']['buckets']) <= 16)<small>{{ $b['label'] }}</small>@endif
                        </button>
                    @endforeach
                </div>
                @php $picked = collect($p['sales']['buckets'])->firstWhere('start', $focus); @endphp
                @if ($picked)
                    <h2>{{ $picked['label'] }} · {{ $picked['parties'] }} fiestas · {{ $usd($picked['sales_cents']) }}</h2>
                    <ul class="wp-list">
                        @forelse ($picked['items'] as $i)
                            <li>{{ $i['name'] }} <span>{{ $fecha($i['date']) }} · {{ $usd($i['total_cents']) }} · {{ $i['source'] }}</span></li>
                        @empty
                            <li>No hubo fiestas.</li>
                        @endforelse
                    </ul>
                @endif
            </div>
            <div class="wp-card">
                <span class="wp-pill">Más movido</span>
                @if ($p['sales']['best'])
                    <div class="wp-big">{{ $p['sales']['best']['label'] }}</div>
                    <p>{{ $p['sales']['best']['parties'] }} fiestas · {{ $usd($p['sales']['best']['sales_cents']) }}</p>
                @else
                    <p>No hay fiestas en este periodo.</p>
                @endif
                <span class="wp-pill">Ganancia real</span>
                @if ($p['profit']['total_cents'] !== null)
                    <div class="wp-big">{{ $usd($p['profit']['total_cents']) }}</div>
                    <p>{{ $p['profit']['ready'] }} fiestas · promedio {{ $usd($p['profit']['average_cents']) }} por fiesta.@if ($p['profit']['pending']) Faltan {{ $p['profit']['pending'] }} sin costos registrados.@endif</p>
                @else
                    <p>Pendiente: ninguna fiesta del periodo tiene sus costos completos ("Costos y margen" en cada fiesta).</p>
                @endif
                <p>Venta del paquete − pago al staff − transporte pagado a alguien del staff − $30 de materiales. Si el transporte lo hicieron César o Maybeli, no se resta. El costo de transporte cobrado al cliente no cuenta como ganancia.</p>
            </div>
        </div>
    @elseif ($tab === 'pulso')
        <div class="wp-row" role="group" aria-label="Qué pagos">
            <div class="wp-seg">
                @foreach ($pulseModes as $k => $m)
                    <button type="button" wire:click="setPulseMode('{{ $k }}')" aria-pressed="{{ $pulseMode === $k ? 'true' : 'false' }}">{{ $m[1] }}</button>
                @endforeach
            </div>
            <p>{{ $pulseHelp }}</p>
        </div>
        <div class="wp-stage">
            <div class="wp-card">
                <h2>Semanas calientes y frías</h2>
                <p>{{ ucfirst($pulseNoun) }} por semana contra tu promedio del periodo ({{ $pulse['average_per_week'] }} por semana). Pasa el dedo o el mouse por un cuadro para ver la semana.</p>
                <div class="wp-heat">
                    @foreach ($pulse['weeks'] as $w)
                        <i class="{{ $heat($w) }}" title="Semana del {{ $fecha($w['start']) }}: {{ $countLabel($w['count']) }} · {{ $usd($w['cents']) }} · {{ $prevYear }}: {{ $w['last_year'] }}"></i>
                    @endforeach
                </div>
                <div class="wp-legend"><span>Fría</span><i class="c2"></i><i class="c1"></i><i class="m"></i><i class="w1"></i><i class="w2"></i><span>Caliente</span></div>
            </div>
            <div class="wp-card">
                <span class="wp-pill">{{ $pulseTitle }} en el periodo</span>
                <div class="wp-big">{{ $usd($pulse['cents']) }}</div>
                <p>{{ $countLabel($pulse['count']) }} · {{ $prevYear }}: {{ $countLabel($pulse['last_year_count']) }}.</p>
                <span class="wp-pill">Semana más caliente</span>
                @if ($pulse['hottest'])
                    <div class="wp-big hot">{{ $fecha($pulse['hottest']['start'], 'j M') }}</div>
                    <p>{{ $countLabel($pulse['hottest']['count']) }} · {{ $usd($pulse['hottest']['cents']) }} · {{ $pulse['hottest']['heat'] }}× tu promedio.</p>
                @else
                    <p>No hubo {{ $pulseNoun }} en este periodo.</p>
                @endif
            </div>
        </div>
        <div class="wp-duo">
            <div class="wp-card">
                <h2>¿En qué días del mes entra el dinero?</h2>
                <p>{{ ucfirst($pulseNoun) }} y monto en cada parte del mes. La barra compara por día, porque 26–31 tiene seis días.</p>
                <div class="wp-bars">
                    @foreach ($pulse['day_of_month_per_day'] as $range => $v)
                        <div class="wp-bar two" style="--c1:var(--gold);--c2:var(--pink)"><span>Días {{ $range }}</span><i style="width:{{ round($v * 100 / $domMax) }}%"></i><b><span>{{ $usd($pulse['day_of_month_cents'][$range]) }}</span> · {{ $countLabel($pulse['day_of_month'][$range]) }}</b></div>
                    @endforeach
                </div>
                @if ($pulse['count'])<p>Lo más fuerte: <b class="hot">días {{ $pulse['strongest_days'] }}</b>. Lo más flojo: <b class="cold">días {{ $pulse['weakest_days'] }}</b>.</p>@endif
            </div>
            <div class="wp-card">
                <h2>¿Qué día de la semana pagan?</h2>
                <p>{{ ucfirst($pulseNoun) }} y monto por día de la semana.</p>
                <div class="wp-bars">
                    @foreach ($pulse['weekday'] as $d => $v)
                        <div class="wp-bar two"><span>{{ $dias[$d] }}</span><i style="width:{{ round($v * 100 / $wdMax) }}%"></i><b><span>{{ $usd($pulse['weekday_cents'][$d]) }}</span> · {{ $countLabel($v) }}</b></div>
                    @endforeach
                </div>
                @if ($pulse['count'])<p>Día más caliente: <b class="hot">{{ $dias[array_key_first($wdSorted)] }}</b>; más frío: <b class="cold">{{ $dias[array_key_last($wdSorted)] }}</b>.</p>@endif
            </div>
        </div>
    @elseif ($tab === 'canales')
        <div class="wp-stage">
            <div class="wp-card">
                <h2>De dónde llegan tus clientes</h2>
                <p>Fiestas del periodo por canal, y cuánto gastan en promedio.</p>
                <table>
                    <thead><tr><th>Canal</th><th style="width:45%">Fiestas</th><th>Ticket promedio</th></tr></thead>
                    <tbody>
                        @forelse ($channels as $c)
                            <tr><td>{{ $c['label'] }}</td>
                                <td><div class="wp-bar" style="grid-template-columns:minmax(0,1fr) auto"><i style="width:{{ round($c['parties'] * 100 / $chMax) }}%"></i><b>{{ $c['parties'] }}</b></div></td>
                                <td>{{ $usd($c['average_cents']) }}</td></tr>
                        @empty
                            <tr><td colspan="3">No hay fiestas en este periodo.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="wp-card">
                <span class="wp-pill">Dónde invertir</span>
                @if ($bestTicket)
                    <div class="wp-big">{{ $bestTicket['label'] }}</div>
                    <p>Ticket promedio más alto: {{ $usd($bestTicket['average_cents']) }} en {{ $bestTicket['known'] }} fiestas.</p>
                @else
                    <p>Aún no hay suficientes fiestas por canal (mínimo 3) para comparar.</p>
                @endif
                <p>"Sin dato" son fiestas donde no se anotó cómo llegó el cliente.</p>
                <span class="wp-pill">Prospectos de la web</span>
                @if ($p['funnel']['count'])
                    <div class="wp-big">{{ $p['funnel']['conversion_pct'] }} %</div>
                    <p>{{ $p['funnel']['booked'] }} de {{ $p['funnel']['count'] }} reservaron · {{ $p['funnel']['lost'] }} perdidos · {{ $p['funnel']['open'] }} en proceso.</p>
                    @foreach ($p['funnel']['reasons'] as $why => $n)
                        <div class="wp-bar" style="--c1:var(--hot);--c2:var(--pink)"><span>{{ $why }}</span><i style="width:{{ round($n * 100 / max($p['funnel']['reasons'])) }}%"></i><b>{{ $n }}</b></div>
                    @endforeach
                @else
                    <p>No llegaron prospectos de la web en este periodo.</p>
                @endif
            </div>
        </div>
    @elseif ($tab === 'zonas')
        <div class="wp-stage">
            <div class="wp-card">
                <h2>Zonas con más fiestas</h2>
                <p>Por código postal del evento. Útil para anuncios por zona y para planear rutas del staff.</p>
                <div class="wp-bars">
                    @forelse ($p['zones'] as $z)
                        <div class="wp-bar" style="--c1:var(--purple);--c2:var(--pink)"><span>{{ $z['zip'] }}{{ $z['city'] ? ' · '.$z['city'] : '' }}</span><i style="width:{{ round($z['parties'] * 100 / $zMax) }}%"></i><b>{{ $z['parties'] }} · {{ $usd($z['cents']) }}</b></div>
                    @empty
                        <p>No hay fiestas con código postal en este periodo.</p>
                    @endforelse
                </div>
            </div>
            <div class="wp-card">
                <span class="wp-pill">Zona más fuerte</span>
                @if ($p['zones'])
                    <div class="wp-big">{{ $p['zones'][0]['zip'] }}</div>
                    <p>{{ $p['zones'][0]['parties'] }} fiestas · {{ $usd($p['zones'][0]['cents']) }}</p>
                @else
                    <p>Sin datos.</p>
                @endif
            </div>
        </div>
    @else
        @php $a = $p['ahead']; @endphp
        <div class="wp-duo">
            <div class="wp-card">
                <span class="wp-pill">Próximas 4 semanas</span>
                <h2>Dinero por cobrar: {{ $usd($a['receivable']['cents']) }}</h2>
                <p>Saldos de fiestas hasta el {{ $fecha($a['receivable']['until'], 'j M') }}, por fecha de vencimiento.@if ($a['receivable']['unknown']) {{ $a['receivable']['unknown'] }} fiestas sin saldo conocido.@endif</p>
                <ul class="wp-list">
                    @forelse ($a['receivable']['items'] as $i)
                        <li>{{ $i['name'] }} <span>vence {{ $fecha($i['due']) }} · {{ $usd($i['cents']) }}</span></li>
                    @empty
                        <li>Nada pendiente.</li>
                    @endforelse
                </ul>
            </div>
            <div class="wp-card">
                <span class="wp-pill">Próximos 8 sábados</span>
                <h2>Sábados con menos fiestas</h2>
                <p>Fiestas ya reservadas cada sábado. Los marcados en verde son los más ligeros: buen momento para promocionar.</p>
                @php $satMin = min(array_column($a['saturdays'], 'parties')); @endphp
                <div class="wp-sats">
                    @foreach ($a['saturdays'] as $s)
                        <div class="wp-sat {{ $s['parties'] === $satMin ? 'light' : '' }}"><b>{{ $s['parties'] }}</b>{{ $fecha($s['date'], 'j M') }}</div>
                    @endforeach
                </div>
            </div>
            <div class="wp-card">
                <span class="wp-pill">Campaña "Un año después"</span>
                <h2>Fiestas de {{ ucfirst(\Carbon\CarbonImmutable::parse($a['anniversary']['month'].'-01')->subYear()->locale('es')->translatedFormat('F Y')) }}</h2>
                <p>Clientes que celebraron hace un año en el mes que viene. Solo la lista: no se envía nada.</p>
                <ul class="wp-list">
                    @forelse ($a['anniversary']['items'] as $i)
                        <li>{{ $i['name'] }} <span>{{ $fecha($i['date']) }}{{ $i['rebooked'] ? ' · ya volvió a reservar' : '' }}</span></li>
                    @empty
                        <li>No hay fiestas de ese mes el año pasado.</li>
                    @endforelse
                </ul>
            </div>
            <div class="wp-card">
                <span class="wp-pill">Cuándo anunciar</span>
                <h2>Ventana de reserva por temporada</h2>
                <p>Cuántas semanas antes de la fiesta reservan. Anuncia antes de la última columna para llegar a 3 de cada 4 clientes.</p>
                <table>
                    <thead><tr><th>Temporada</th><th>Mitad reserva con</th><th>Anuncia con</th></tr></thead>
                    <tbody>
                        @foreach ($p['booking_window'] as $s)
                            <tr><td>{{ $s['label'] }}</td>
                                <td>{{ $s['median_weeks'] === null ? 'Sin datos' : $s['median_weeks'].' sem' }}</td>
                                <td>{{ $s['announce_weeks'] === null ? '—' : $s['announce_weeks'].'+ sem' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
