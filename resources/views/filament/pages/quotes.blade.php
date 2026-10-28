@php($rows = $this->rows())
<x-filament-panels::page>
    <div class="wmoney">
        <div class="wmoney-states" role="tablist" aria-label="Filtrar por estado">
            @foreach (['all' => 'Todas'] + \App\Filament\Support\QuoteForm::STATUS as $k => $label)
                <button type="button" role="tab" aria-selected="{{ $status === $k ? 'true' : 'false' }}" wire:click="$set('status', '{{ $k }}')" class="wlead-tab {{ $status === $k ? 'on' : '' }}">{{ $label }}</button>
            @endforeach
        </div>
        <x-filament::section>
            @forelse ($rows as $r)
                <a class="wmoney-row wquote-row" href="{{ \App\Filament\Pages\QuoteDetail::getUrl(['quote' => $r->id]) }}">
                    <div><b>{{ $r->number }}</b> · {{ $r->client }} · {{ $r->when }} <span class="wlead-st st-{{ $r->status }}">{{ \App\Filament\Support\QuoteForm::STATUS[$r->status] ?? $r->status }}</span></div>
                    <small>Total {{ $r->total }} · versión {{ $r->revision }} · válida hasta {{ $r->valid }}</small>
                </a>
            @empty
                <p class="wmoney-p">No hay cotizaciones aquí. Usa “Nueva cotización” arriba, o “Crear cotización” en un prospecto o en la ficha de un evento.</p>
            @endforelse
        </x-filament::section>
        @if (($this->pageInfo['last'] ?? 1) > 1)
            <nav class="wlead-pages" aria-label="Páginas">
                <button type="button" class="wcal-btn" wire:click="goToPage({{ $this->pageInfo['current'] - 1 }})" @disabled($this->pageInfo['current'] <= 1)>← Anteriores</button>
                <span>{{ $this->pageInfo['from'] }}–{{ $this->pageInfo['to'] }} de {{ $this->pageInfo['total'] }} · página {{ $this->pageInfo['current'] }} de {{ $this->pageInfo['last'] }}</span>
                <button type="button" class="wcal-btn" wire:click="goToPage({{ $this->pageInfo['current'] + 1 }})" @disabled($this->pageInfo['current'] >= $this->pageInfo['last'])>Siguientes →</button>
            </nav>
        @endif
    </div>
</x-filament-panels::page>
