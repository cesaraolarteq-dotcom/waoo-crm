@php($f = $this->file())
<x-filament-panels::page>
    <div class="wfile">
        <x-filament::section>
            <x-slot name="heading">Contacto</x-slot>
            <dl class="wfile-dl">
                <div><dt>Teléfono</dt><dd>{{ $f['c']->phone ?: '—' }}</dd></div>
                <div><dt>Correo</dt><dd>{{ $f['c']->email ?: '—' }}</dd></div>
                <div><dt>Idioma</dt><dd>{{ $f['c']->locale === 'en' ? 'Inglés' : 'Español' }}</dd></div>
                <div><dt>Clienta desde</dt><dd>{{ $f['since'] ?? '—' }}</dd></div>
                @if ($f['paid'] !== null)<div><dt>Pagado (verificado)</dt><dd class="ok">{{ \App\Filament\Support\Labels::money($f['paid']) }}</dd></div>@endif
            </dl>
        </x-filament::section>
        <x-filament::section>
            <x-slot name="heading">Próximas fiestas</x-slot>
            @forelse ($f['upcoming'] as $e)
                <a class="wdash-row" href="{{ $e->url }}"><span><b>{{ $e->when }}</b><small>{{ $e->title }}@if ($e->type) · {{ $e->type }}@endif</small></span><x-filament::badge :color="$e->color">{{ $e->status }}</x-filament::badge></a>
            @empty
                <p class="wmoney-p">No tiene fiestas próximas.</p>
            @endforelse
        </x-filament::section>
    </div>
    <x-filament::section>
        <x-slot name="heading">Fiestas anteriores</x-slot>
        @forelse ($f['past'] as $e)
            <a class="wdash-row" href="{{ $e->url }}"><span><b>{{ $e->when }}</b><small>{{ $e->title }}@if ($e->type) · {{ $e->type }}@endif</small></span><x-filament::badge :color="$e->color">{{ $e->status }}</x-filament::badge></a>
        @empty
            <p class="wmoney-p">Todavía no hay fiestas anteriores.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
