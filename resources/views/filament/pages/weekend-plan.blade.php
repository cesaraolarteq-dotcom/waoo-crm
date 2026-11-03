<x-filament-panels::page>
    @php($b = $this->board())
    <div class="wtp-head">
        <div class="wtp-week">
            <button type="button" class="wcal-btn" wire:click="shiftWeek(-1)" aria-label="Fin de semana anterior">←</button>
            <b>{{ $b['range'] }}</b> <span class="wtp-muted">· {{ $b['count'] }} {{ $b['count'] === 1 ? 'fiesta' : 'fiestas' }}</span>
            <button type="button" class="wcal-btn" wire:click="shiftWeek(1)" aria-label="Fin de semana siguiente">→</button>
        </div>
        @if ($b['count'])
            <div class="wtp-actions">
                <button type="button" class="wcal-btn" wire:click="suggest" wire:loading.attr="disabled">Sugerir todo</button>
                <button type="button" class="wcal-btn on" wire:click="saveAll" wire:loading.attr="disabled">Guardar</button>
            </div>
        @endif
    </div>

    @forelse ($b['days'] as $day)
        <h3 class="wtp-day">{{ $day['label'] }} <span class="wtp-avail">Staff: <b>{{ $day['avail']['yes'] }}</b> pueden · {{ $day['avail']['no'] }} no · {{ $day['avail']['none'] }} sin responder</span></h3>
        <div class="wtp-grid">
            @foreach ($day['parties'] as $p)
                <section class="wtp-card" wire:key="party-{{ $p['id'] }}">
                    <header>
                        <div><a href="{{ $p['url'] }}" class="wtp-title">{{ $p['title'] }}</a>
                            <div class="wtp-muted">{{ $p['time'] }} · {{ $p['packages'] }}</div>
                            @if ($p['gaps'])<div class="wtp-missing">Falta: {{ implode(', ', $p['gaps']) }}</div>@endif</div>
                        <span @class(['wtp-chip', 'ok' => $p['missing'] === 0])>{{ $p['missing'] === 0 ? 'Completo' : 'Faltan '.$p['missing'] }}</span>
                    </header>
                    @foreach ($p['seats'] as $s)
                        <label class="wtp-seat"><span>{{ $s['label'] }}</span>
                            <select wire:model.live="picks.{{ $p['id'] }}.people.{{ $s['key'] }}">
                                <option value="">— Elegir —</option>
                                @foreach ($s['options'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                            @if ($s['status'])<span class="wtp-ans {{ $s['status'][0] }}">{{ $s['status'][1] }}@if ($s['status'][0] === 'bad') <button type="button" class="wtp-rep" wire:click="replace({{ $p['id'] }}, '{{ $s['key'] }}')">Reemplazar</button>@endif</span>@endif
                        </label>
                    @endforeach
                    @foreach ($p['costumes'] as $c)
                        <div class="wtp-seat wtp-costume"><span>{{ $c['label'] }}</span>
                            <select wire:model.live="picks.{{ $p['id'] }}.costumes.{{ $c['key'] }}.unit_id" aria-label="Traje de {{ $c['label'] }}">
                                <option value="">Traje…</option>
                                @foreach ($c['units'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                            <select wire:model.live="picks.{{ $p['id'] }}.costumes.{{ $c['key'] }}.wearer_id" aria-label="Quién usa {{ $c['label'] }}">
                                <option value="">Quién lo usa…</option>
                                @foreach ($c['wearers'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                            @if ($c['status'])<span class="wtp-ans {{ $c['status'][0] }}">{{ $c['status'][1] }}@if ($c['status'][0] === 'bad') <button type="button" class="wtp-rep" wire:click="replace({{ $p['id'] }}, '{{ $c['key'] }}', true)">Reemplazar</button>@endif</span>@endif
                        </div>
                    @endforeach
                    <label class="wtp-seat wtp-driver"><span>Conductor</span>
                        @if ($p['auto_driver'] && ! ($picks[$p['id']]['driver'] ?? null))
                            <b>{{ $p['auto_driver'] }}</b>
                        @else
                            <select wire:model.live="picks.{{ $p['id'] }}.driver">
                                <option value="">{{ $p['drivers'] ? '— Elegir —' : ($p['team_empty'] ? 'Se elige al armar el equipo' : 'Nadie del equipo maneja') }}</option>
                                @foreach ($p['drivers'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                                <option value="external">Conductor externo</option>
                            </select>
                        @endif
                    </label>
                </section>
            @endforeach
        </div>
    @empty
        <div class="wtp-empty">No hay fiestas confirmadas esta semana.</div>
    @endforelse
</x-filament-panels::page>
