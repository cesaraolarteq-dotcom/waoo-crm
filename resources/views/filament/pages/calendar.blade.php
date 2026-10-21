@php
    [$from, $to] = $this->range();
    $byDay = $this->eventsByDay();
    $todayKey = now('America/Chicago')->format('Y-m-d');
    $days = [];
    for ($d = $from; $d->lte($to); $d = $d->addDay()) { $days[] = $d; }
    $startHour = 7; $endHour = 23; $pxPerMin = 1.1;
    $google = $this->google();
    $busy = $google && $google['connected'] ? $this->googleBusy() : [];
@endphp
<x-filament-panels::page>
    <div class="wcal">
        <div class="wcal-bar">
            <div class="wcal-nav">
                <button type="button" wire:click="move(-1)" class="wcal-btn" aria-label="Anterior">‹</button>
                <button type="button" wire:click="today" class="wcal-btn">Hoy</button>
                <button type="button" wire:click="move(1)" class="wcal-btn" aria-label="Siguiente">›</button>
                <h2 class="wcal-title">{{ $this->heading() }}</h2>
            </div>
            <div class="wcal-modes" role="tablist" aria-label="Vista">
                @foreach (['month' => 'Mes', 'week' => 'Semana', 'day' => 'Día'] as $key => $label)
                    <button type="button" role="tab" aria-selected="{{ $mode === $key ? 'true' : 'false' }}" wire:click="setMode('{{ $key }}')" @class(['wcal-btn', 'on' => $mode === $key])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <p class="wcal-legend">
            <span><i style="background:#F9C930"></i>Estrella</span><span><i style="background:#8D53D9"></i>Mágico</span><span><i style="background:#15D1C4"></i>Música y Juegos</span><span><i style="background:#FF017C"></i>MyJ 2.0</span><span><i style="background:#122F94"></i>Waoo</span>
            <span class="wcal-legend-note">Rayado = montaje y desmontaje · Hora de Houston</span>
            @if ($busy)<span><i class="wcal-gdot"></i>Ocupado en tu Google</span>@endif
        </p>
        @if ($google)
            <div @class(['wcal-google', 'warn' => $google['connected'] && ($google['error'] || $google['pending'])])>
                @if (session('google_calendar'))<p class="wcal-google-flash" role="status">{{ session('google_calendar') }}</p>@endif
                @if ($google['connected'])
                    <span><b>Google Calendar conectado</b>{{ $google['email'] ? ' · '.$google['email'] : '' }} · las fiestas van a «{{ $google['name'] }}»</span>
                    @if ($google['error'])<span class="wcal-google-err">{{ $google['error'] }}</span>@elseif ($google['pending'])<span class="wcal-google-err">{{ $google['pending'] }} {{ $google['pending'] === 1 ? 'fiesta sin enviar' : 'fiestas sin enviar' }}</span>@endif
                    <span class="wcal-google-actions">
                        <button type="button" class="wcal-btn" wire:click="syncGoogle" wire:loading.attr="disabled">Sincronizar ahora</button>
                        @if ($google['error'])<a class="wcal-btn" href="{{ url('/admin/google/connect') }}">Volver a conectar</a>@endif
                        <button type="button" class="wcal-btn" wire:click="disconnectGoogle" wire:confirm="¿Desconectar Google Calendar? Las fiestas que ya están en Google se quedan allí.">Desconectar</button>
                    </span>
                @else
                    <span>Conecta tu Google Calendar: las fiestas aparecen solas en «{{ $google['name'] }}» y lo que tengas ocupado en Google se ve aquí.</span>
                    <a class="wcal-btn on" href="{{ url('/admin/google/connect') }}">Conectar Google Calendar</a>
                @endif
            </div>
        @endif

        @if ($mode === 'month')
            <div class="wcal-month">
                @foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $dn)
                    <div class="wcal-dow">{{ $dn }}</div>
                @endforeach
                @foreach ($days as $d)
                    @php
                        $key = $d->format('Y-m-d');
                        $list = $byDay[$key] ?? collect();
                    @endphp
                    <button type="button" wire:click="openDay('{{ $key }}')" @class(['wcal-mday', 'out' => $d->month !== $this->anchor()->month, 'today' => $key === $todayKey])>
                        <span class="wcal-mnum">{{ $d->day }}</span>
                        @foreach ($list->take(3) as $e)
                            <span class="wcal-mev" style="--pkg: {{ $e->pkg }}">{{ $e->start->format('g:ia') }} {{ $e->title }}{{ $e->warnings ? ' ⚠' : '' }}</span>
                        @endforeach
                        @if ($list->count() > 3)<span class="wcal-more">+{{ $list->count() - 3 }} más</span>@endif
                        @foreach (array_slice($busy[$key] ?? [], 0, 2) as $b)<span class="wcal-gbusy">{{ $b['from'] }}–{{ $b['to'] }} ocupado</span>@endforeach
                    </button>
                @endforeach
            </div>
        @elseif ($mode === 'week')
            <div class="wcal-week">
                @foreach ($days as $d)
                    @php
                        $key = $d->format('Y-m-d');
                        $list = $byDay[$key] ?? collect();
                    @endphp
                    <section @class(['wcal-wday', 'today' => $key === $todayKey])>
                        <button type="button" class="wcal-whead" wire:click="openDay('{{ $key }}')">{{ ucfirst($d->locale('es')->translatedFormat('D j')) }} <small>{{ $list->count() ? $list->count().' '.($list->count() === 1 ? 'fiesta' : 'fiestas') : '' }}</small></button>
                        @forelse ($list as $e)
                            @include('filament.pages.partials.calendar-card', ['e' => $e])
                        @empty
                            @if (empty($busy[$key]))<p class="wcal-empty">Libre</p>@endif
                        @endforelse
                        @foreach ($busy[$key] ?? [] as $b)<p class="wcal-gbusy">Ocupado en Google · {{ $b['from'] }}–{{ $b['to'] }}</p>@endforeach
                    </section>
                @endforeach
            </div>
        @else
            @php
                $list = $byDay[$this->anchor()->format('Y-m-d')] ?? collect();
            @endphp
            <div class="wcal-day">
                <div class="wcal-grid" style="height: {{ ($endHour - $startHour) * 60 * $pxPerMin }}px">
                    @for ($h = $startHour; $h < $endHour; $h++)
                        <div class="wcal-hour" style="top: {{ ($h - $startHour) * 60 * $pxPerMin }}px"><span>{{ \Carbon\Carbon::createFromTime($h)->format('g a') }}</span></div>
                    @endfor
                    @foreach ($list->values() as $i => $e)
                        @php
                            $base = $this->anchor()->setTime($startHour, 0);
                            $top = max(0, $base->diffInMinutes($e->prep_from ?? $e->start, false)) * $pxPerMin;
                            $bodyTop = max(0, $base->diffInMinutes($e->start, false)) * $pxPerMin;
                            $bodyH = max(24, $e->start->diffInMinutes($e->end) * $pxPerMin);
                            $bottom = max(0, $base->diffInMinutes($e->prep_to ?? $e->end, false)) * $pxPerMin;
                            $lane = $i % 2;
                        @endphp
                        <div class="wcal-prep" style="top: {{ $top }}px; height: {{ max(0, $bottom - $top) }}px; left: calc(64px + {{ $lane }} * (100% - 64px) / 2); --pkg: {{ $e->pkg }}"></div>
                        <div class="wcal-slot" style="top: {{ $bodyTop }}px; min-height: {{ $bodyH }}px; left: calc(64px + {{ $lane }} * (100% - 64px) / 2);">
                            @include('filament.pages.partials.calendar-card', ['e' => $e])
                        </div>
                    @endforeach
                </div>
                @if ($list->isEmpty())<p class="wcal-empty">No hay fiestas este día.</p>@endif
                @foreach ($busy[$this->anchor()->format('Y-m-d')] ?? [] as $b)<p class="wcal-gbusy">Ocupado en tu Google · {{ $b['from'] }}–{{ $b['to'] }}</p>@endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
