{{-- Audit 2026-11-02: on a phone the four places used every day are one tap away; "Más" opens the full menu. --}}
@php
    $current = \App\Filament\Support\Sections::current();
    $first = fn (string $key) => \App\Filament\Support\Sections::visibleTabs($key)[0]['url'] ?? null;
    $items = array_filter([
        ['key' => 'home', 'label' => 'Inicio', 'icon' => 'heroicon-o-home', 'url' => $first('home')],
        ['key' => 'agenda', 'label' => 'Agenda', 'icon' => 'heroicon-o-calendar-days', 'url' => $first('agenda')],
        ['key' => 'quick', 'label' => 'Reservar', 'icon' => 'heroicon-o-bolt', 'url' => $first('quick'), 'main' => true],
        ['key' => 'sales', 'label' => 'Ventas', 'icon' => 'heroicon-o-chat-bubble-left-right', 'url' => $first('sales')],
    ], fn ($i) => $i['url'] !== null);
@endphp
@if ($items)
    <nav class="wbar" aria-label="Accesos rápidos">
        @foreach ($items as $i)
            <a href="{{ $i['url'] }}" @class(['wbar-item', 'main' => $i['main'] ?? false, 'on' => $current === $i['key']]) @if ($current === $i['key']) aria-current="page" @endif>
                <span class="wbar-ico"><x-filament::icon :icon="$i['icon']" /></span>{{ $i['label'] }}
            </a>
        @endforeach
        <button type="button" class="wbar-item" x-data x-on:click="$store.sidebar.open()">
            <span class="wbar-ico"><x-filament::icon icon="heroicon-o-bars-3" /></span>Más
        </button>
    </nav>
@endif
