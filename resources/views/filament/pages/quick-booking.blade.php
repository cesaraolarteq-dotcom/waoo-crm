<x-filament-panels::page>
    @if ($leadSummary && ! $result)
        <div class="wmoney-warn" role="status">Convirtiendo el prospecto de <b>{{ $leadSummary['name'] }}</b>. En la web eligió: {{ $leadSummary['package'] ?: 'sin paquete' }}{{ $leadSummary['type'] ? ' · '.$leadSummary['type'] : '' }}@if ($leadSummary['travel']) · vio transporte {{ $leadSummary['travel'] }}@endif. Revisa paquete, hora, transporte y depósito: nada se toma de la web sin tu confirmación.</div>
    @endif
    {{ $this->content }}
    @if (! $result && filled(config('waoo.google_maps_browser_key')))
        {{-- Address suggestions from Google (Places New); picking one fills address + ZIP and runs "Calcular". The owner can still edit everything. --}}
        <script src="{{ \App\Support\AssetVersion::url('js/waoo-places.js') }}" data-key="{{ config('waoo.google_maps_browser_key') }}" defer></script>
    @endif
    @if ($result)
        <div class="wqb-done">
            <h2>¡Reserva guardada! <span>{{ $result['summary'] }}</span></h2>
            @if ($result['link_pending'] ?? false)
                <p class="wqb-warn">El pago y el evento están guardados. Falta crear el enlace: genera otro desde el evento; no vuelvas a registrar el depósito.</p>
            @elseif ($result['shown_before'])
                <p class="wqb-warn">Este enlace ya se creó antes: cópialo de nuevo desde el evento → Contrato y enlace.</p>
            @else
                @include('filament.pages.partials.link-panel', ['panel' => ['url' => $result['link'], 'expires' => $result['expires'], 'msg_es' => $result['msg_es'], 'msg_en' => $result['msg_en'],
                    'phone' => $result['phone'] ?? null, 'locale' => $result['locale'], 'heading' => 'Enlace de la clienta']])
            @endif
            <livewire:quick-costume-hold-panel :event-id="$result['event_id']" :key="'quick-hold-'.$result['event_id']" />
            <div class="wqb-next">
                <a class="wcal-btn" href="{{ \App\Filament\Resources\Events\EventResource::getUrl('money', ['record' => $result['event_id']]) }}">Ver el dinero de este evento</a>
                <button type="button" class="wcal-btn" wire:click="startOver">Nueva reserva</button>
            </div>
        </div>
    @endif
</x-filament-panels::page>
