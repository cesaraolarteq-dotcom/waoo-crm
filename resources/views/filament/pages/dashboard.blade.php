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
                                <li>@if ($item['url'])<a href="{{ $item['url'] }}">{{ $item['label'] }}</a>@else{{ $item['label'] }}@endif</li>
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
                <span><b>{{ $e->when }}</b><small>{{ $e->title }} · {{ $e->client }} · transporte {{ $e->travel }}</small></span>
                <x-filament::badge :color="$e->color">{{ $e->status }}</x-filament::badge>
            </a>
        @empty
            <p class="wdash-ok">No hay fiestas de aquí al domingo.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
