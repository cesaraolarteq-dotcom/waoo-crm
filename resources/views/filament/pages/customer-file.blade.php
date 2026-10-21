@php($f = $this->file())
<x-filament-panels::page>
    <div class="wfile">
        <x-filament::section>
            <x-slot name="heading">Contacto</x-slot>
            <dl class="wfile-dl">
                <div><dt>Teléfono</dt><dd>{{ $f['c']->phone ?: '—' }}</dd></div>
                <div><dt>Correo</dt><dd>{{ $f['c']->email ?: '—' }}</dd></div>
                <div><dt>Idioma</dt><dd>{{ $f['c']->locale === 'en' ? 'Inglés' : 'Español' }}</dd></div>
                <div><dt>Cliente desde</dt><dd>{{ $f['since'] ?? '—' }}</dd></div>
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
    @if($origin = $this->honeyBookSources())
        <x-filament::section><x-slot name="heading">Cómo nos conoció — fuente HoneyBook</x-slot>
            <p>{{ implode(' · ', $origin['lead_sources']) ?: 'Sin dato de origen' }}</p>
            @foreach($origin['sms_consent_evidence'] as $consent)<p>Permiso SMS registrado en HoneyBook (evidencia de origen): {{ $consent['raw_value'] }}</p>@endforeach
        </x-filament::section>
    @endif
    @if($history = $this->honeyBookHistory())
    <x-filament::section>
        <x-slot name="heading">Historial HoneyBook</x-slot>
        <p>Información original de HoneyBook; los importes no son recibos verificados en banco.</p>
        @forelse($history['items'] as $item)
            <article class="rounded-xl border border-gray-200 p-4 my-3">
                <h3>{{ $item['record_kind'] === 'prospect' ? 'Prospecto sin fecha' : $item['source_date'] }} · {{ $item['project_name'] }}</h3>
                <p>Total: {{ \App\Filament\Pages\ImportHistory::money($item['booked_total_cents'], $item['currency']) }} · Pagado en HoneyBook: {{ \App\Filament\Pages\ImportHistory::money($item['paid_total_cents'], $item['currency']) }}</p>
                <p>Festejado/a: {{ $item['source_fields']['child_name'] ?: ($item['source_fields']['celebrant_name'] ?? 'Sin dato') }} · Edad: {{ $item['source_fields']['celebrant_age'] ?? 'Sin dato' }}</p>
                @foreach($item['originals'] as $original)<p><a href="{{ url('/admin/api/history/originals/'.$original['id']) }}">Original HoneyBook: {{ $original['kind'] }} (PDF)</a></p>@endforeach
                @if($resolution=$item['balance_resolution'])
                    <p>Decisión del dueño: {{ \App\Filament\Pages\HoneyBookArchiveReview::RESOLUTIONS[$resolution['kind']] }}. No es verificación bancaria.</p>
                    @if($resolution['stale'])
                        <p>Revisar: el origen cambió.</p>
                    @endif
                    <p>{{ $resolution['owner_note'] }}</p>
                @endif
                <details><summary>Notas y datos originales</summary><dl>
                @foreach($item['source_fields'] as $field => $value)
                    <dt>{{ \App\Filament\Pages\ImportHistory::SOURCE[$field] ?? $field }}</dt><dd class="whitespace-pre-wrap">{{ $value === '' ? 'Sin dato' : \App\Filament\Pages\ImportHistory::displaySource($field,$value) }}</dd>
                @endforeach
                </dl></details>
            </article>
        @empty <p>No hay historial importado para esta clienta.</p> @endforelse
        <p>Página {{ $history['page']['number'] }} de {{ $history['page']['last'] }}</p>
        @if($history['page']['number'] > 1)<button wire:click="historyPage({{ $history['page']['number'] - 1 }})">Anteriores</button>@endif
        @if($history['page']['number'] < $history['page']['last'])<button wire:click="historyPage({{ $history['page']['number'] + 1 }})">Siguientes</button>@endif
    </x-filament::section>
    @endif
</x-filament-panels::page>
