@php
    $cards = $this->attention();
    $week = $this->thisWeek();
@endphp
<x-filament-panels::page>
    <div class="waoo-welcome">
        <h2>Hola, <em>{{ \Illuminate\Support\Str::of(auth()->user()->name)->before(' ') }}</em></h2>
        <p>{{ ucfirst($this->now()->locale('es')->translatedFormat('l j \d\e F')) }} · {{ $week->count() }} {{ $week->count() === 1 ? 'fiesta' : 'fiestas' }} de aquí al domingo</p>
    </div>


    <x-filament::section>
        <x-slot name="heading">Necesita tu atención</x-slot>
        <x-slot name="description">Toca cualquiera para resolverlo. Cuando todo está al día, esta sección queda vacía.</x-slot>
        @if ($cards === [])
            <p class="wdash-ok">✓ Todo al día. No hay nada pendiente.</p>
        @else
            <div class="wdash-cards">
                @foreach ($cards as $c)
                    <details class="wdash-card tone-{{ $c['tone'] }}">
                        <summary><span class="wdash-n">{{ $c['count'] }}</span><span><b>{{ $c['title'] }}</b><small>{{ $c['text'] }}</small></span></summary>
                        <ul>
                            @foreach ($c['items'] as $item)
                                <li>@if ($item['url'])<a class="wdash-item" href="{{ $item['url'] }}"><b>{{ $item['label'] }}</b>@if ($item['meta'] ?? null)<small>{{ $item['meta'] }}</small>@endif<span aria-hidden="true">›</span></a>@else<span class="wdash-item"><b>{{ $item['label'] }}</b>@if ($item['meta'] ?? null)<small>{{ $item['meta'] }}</small>@endif</span>@endif</li>
                            @endforeach
                        </ul>
                    </details>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Esta semana</x-slot>
        <x-slot name="afterHeader"><a class="wcal-btn" href="{{ \App\Filament\Pages\Calendar::getUrl() }}">Ver calendario</a></x-slot>
        @forelse ($week as $e)
            <a class="wdash-row" href="{{ $e->url }}">
                <span><b>{{ $e->when }}</b><small>{{ $e->title }}@if ($e->client) · {{ $e->client }}@endif</small></span>
                <span class="wparty-chips">@forelse ($e->chips as $c)<span class="wparty-chip {{ $c['tone'] }}">{{ $c['label'] }}</span>@empty<span class="wparty-chip ok">Todo listo</span>@endforelse</span>
            </a>
        @empty
            <p class="wdash-ok">No hay fiestas de aquí al domingo.</p>
        @endforelse
    </x-filament::section>

    {{-- Last week's numbers last: what is coming matters more. --}}
    @if ($sum = $this->weekSummary())
        <a class="wrep-home" href="{{ \App\Filament\Pages\Reports::getUrl() }}">
            <span class="wrep-home-h">{{ $sum['label'] }}@if ($sum['sample']) · ejemplo @endif</span>
            <span><b>{{ $sum['cash'] }}</b> entraron</span>
            <span><b>{{ $sum['bookings'] }}</b> {{ $sum['bookings'] === 1 ? 'reserva nueva' : 'reservas nuevas' }}@if ($sum['packages']) <small>({{ $sum['packages'] }})</small>@endif</span>
            @if ($sum['weekend'] !== null)<span>Fin de semana: <b>{{ $sum['weekend'] }}</b> {{ $sum['weekend'] === 1 ? 'fiesta' : 'fiestas' }} @if ($sum['weekend_staff'] !== 'Pendiente') · staff estimado {{ $sum['weekend_staff'] }}@endif</span>@endif
            <span class="wrep-home-more">Ver reportes →</span>
        </a>
    @endif
</x-filament-panels::page>
