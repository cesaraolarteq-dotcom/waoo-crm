@php
    $rows = $this->rows();
    $counts = $this->counts();
    $demand = ['full' => 'Fecha casi llena', 'filling' => 'Fecha muy pedida'];
    $types = \App\Filament\Pages\Leads::STATUS;
@endphp
<x-filament-panels::page>
    <div class="wmoney">
        <div class="wmoney-states" role="tablist" aria-label="Filtrar por estado">
            @foreach (['new' => 'Nuevos', 'contacted' => 'Contactados', 'quoted' => 'Cotizados', 'booked' => 'Reservados', 'lost' => 'Perdidos', 'all' => 'Todos'] as $k => $label)
                <button type="button" role="tab" aria-selected="{{ $status === $k ? 'true' : 'false' }}" wire:click="$set('status', '{{ $k }}')" class="wlead-tab {{ $status === $k ? 'on' : '' }}">
                    {{ $label }}@if ($k !== 'all' && ($counts[$k] ?? 0)) <b>{{ $counts[$k] }}</b>@endif
                </button>
            @endforeach
        </div>
        @forelse ($rows as $r)
            <x-filament::section>
                <div class="wlead">
                    <div class="wlead-head">
                        <div><b class="wlead-name">{{ $r->name }}</b> <span class="wlead-st st-{{ $r->status }}">{{ $types[$r->status] ?? $r->status }}</span>
                            @if (isset($demand[$r->demand]))<span class="wlead-st st-hot">{{ $demand[$r->demand] }}</span>@endif</div>
                        <small>Llegó {{ $r->received }}</small>
                    </div>
                    <dl class="wlead-dl">
                        <div><dt>Fecha</dt><dd>{{ $r->date ? \Carbon\CarbonImmutable::parse($r->date)->locale('es')->translatedFormat('D j M Y') : '—' }}{{ $r->time ? ' · '.$r->time : '' }}</dd></div>
                        <div><dt>ZIP</dt><dd>{{ $r->zip }}</dd></div>
                        <div><dt>Paquete (web)</dt><dd>{{ $r->package ?: '—' }}</dd></div>
                        <div><dt>Tipo</dt><dd>{{ $r->type ?: '—' }}</dd></div>
                        <div><dt>Contacto</dt><dd>{{ $r->phone }} · {{ $r->email }}</dd></div>
                        @if ($r->travel)<div><dt>Transporte que vio</dt><dd>{{ $r->travel }} <small>(solo informativo)</small></dd></div>@endif
                    </dl>
                    @if ($r->message)<p class="wlead-msg">“{{ $r->message }}”</p>@endif
                    @if ($r->hints)<p class="wmoney-warn">Puede ser una clienta registrada: @foreach ($r->hints as $h){{ $h['name'] }} (mismo {{ implode(' y ', array_map(fn ($m) => $m === 'email' ? 'correo' : 'teléfono', $h['matched_by'])) }})@if (! $loop->last), @endif @endforeach. Revísalo al convertir.</p>@endif
                    @if ($r->note)<p class="wmoney-p"><b>Nota:</b> {{ $r->note }}</p>@endif
                    <div class="wlead-actions">
                        @if ($r->status === 'booked' && $r->event_id)
                            <a class="wcal-btn" href="{{ \App\Filament\Resources\Events\EventResource::getUrl('view', ['record' => $r->event_id]) }}">Ver la reserva</a>
                        @elseif (in_array($r->status, ['new', 'contacted', 'quoted'], true))
                            <a class="wcal-btn on" href="{{ \App\Filament\Pages\QuickBooking::getUrl(['lead' => $r->id]) }}">Convertir en reserva rápida</a>
                        @endif
                        {{ ($this->updateAction)(['id' => $r->id]) }}
                    </div>
                </div>
            </x-filament::section>
        @empty
            <x-filament::section><p class="wmoney-p">No hay prospectos en este estado.</p></x-filament::section>
        @endforelse
        @if (($this->pageInfo['last'] ?? 1) > 1)
            <nav class="wlead-pages" aria-label="Páginas de prospectos">
                <button type="button" class="wcal-btn" wire:click="goToPage({{ $this->pageInfo['current'] - 1 }})" @disabled($this->pageInfo['current'] <= 1)>← Anteriores</button>
                <span>{{ $this->pageInfo['from'] }}–{{ $this->pageInfo['to'] }} de {{ $this->pageInfo['total'] }} · página {{ $this->pageInfo['current'] }} de {{ $this->pageInfo['last'] }}</span>
                <button type="button" class="wcal-btn" wire:click="goToPage({{ $this->pageInfo['current'] + 1 }})" @disabled($this->pageInfo['current'] >= $this->pageInfo['last'])>Siguientes →</button>
            </nav>
        @endif
    </div>
</x-filament-panels::page>
