<x-filament-panels::page>
    @php($board = $this->board())
    @php($money = fn ($c) => \App\Filament\Support\Labels::money($c))
    <div class="wtp-head">
        <div class="wtp-week">
            <button type="button" class="wcal-btn" wire:click="shiftWeek(-1)" aria-label="Semana anterior">←</button>
            <b>{{ $this->range() }}</b> <span class="wtp-muted">· {{ count($board) }} {{ count($board) === 1 ? 'persona' : 'personas' }} · {{ $money(array_sum(array_column($board, 'total_cents'))) }}</span>
            <button type="button" class="wcal-btn" wire:click="shiftWeek(1)" aria-label="Semana siguiente">→</button>
        </div>
        <button type="button" class="wcal-btn" wire:click="$toggle('editingRates')">{{ $editingRates ? 'Cerrar tarifas' : 'Tarifas' }}</button>
    </div>

    @if ($editingRates)
        <x-filament::section>
            <x-slot name="heading">Tarifas</x-slot>
            <x-slot name="description">Con esto el CRM sugiere el pago. Siempre puedes cambiar el monto de cada fiesta. Deja vacío lo que no uses.</x-slot>
            <div class="wpay-rates">
                @foreach ($this->roles() as $role => $label)
                    <label class="wpay-rate"><span>{{ $label }}</span>
                        <span class="wpay-in">$<input type="text" inputmode="decimal" wire:model="rates.{{ $role }}.amount" placeholder="0" aria-label="Tarifa de {{ $label }}"></span>
                        <select wire:model="rates.{{ $role }}.per" aria-label="Por hora o por fiesta"><option value="hour">por hora</option><option value="party">por fiesta</option></select>
                    </label>
                @endforeach
            </div>
            <div style="margin-top:12px"><button type="button" class="wcal-btn on" wire:click="saveRates">Guardar tarifas</button></div>
        </x-filament::section>
    @endif

    @forelse ($board as $p)
        <section class="wtp-card wpay-person" wire:key="pay-{{ $p['resource_id'] }}">
            <header>
                <div><b class="wtp-title">{{ $p['name'] }}</b><div class="wtp-muted">{{ count($p['lines']) }} {{ count($p['lines']) === 1 ? 'fiesta' : 'fiestas' }}</div></div>
                <div class="wpay-total"><b>{{ $money($p['total_cents']) }}</b>
                    @if ($p['paid'])<span class="wtp-chip ok">Pagado</span>
                    @else<button type="button" class="wcal-btn on" wire:click="markPaid({{ $p['resource_id'] }})">Marcar pagado</button>@endif
                </div>
            </header>
            @foreach ($p['lines'] as $l)
                @php($key = $l['event_id'].'-'.$p['resource_id'])
                <div class="wpay-line">
                    <div class="wpay-what"><b>{{ $l['title'] }}</b> <span class="wtp-muted">{{ $l['when'] }}</span>
                        <ul class="wpay-why">@foreach ($l['why'] as $w)<li>{{ $w }}</li>@endforeach
                            @if ($l['decided'] !== null && $l['decided'] !== $l['suggested_cents'])<li><b>Cambiado por ti</b>{{ $l['suggested_cents'] !== null ? ' (sugerido '.$money($l['suggested_cents']).')' : '' }}@if ($l['note']) · {{ $l['note'] }}@endif</li>@endif
                        </ul></div>
                    @if (isset($edits[$key]))
                        <div class="wpay-edit">
                            <span class="wpay-in">$<input type="text" inputmode="decimal" wire:model="edits.{{ $key }}.amount" aria-label="Monto"></span>
                            <input type="text" wire:model="edits.{{ $key }}.note" placeholder="Por qué (opcional)" maxlength="300" aria-label="Por qué">
                            <button type="button" class="wcal-btn on" wire:click="saveLine({{ $l['event_id'] }}, {{ $p['resource_id'] }}, {{ $l['suggested_cents'] ?? 'null' }})">Guardar</button>
                        </div>
                    @else
                        <div class="wpay-amt"><b>{{ $l['suggested_cents'] === null && $l['decided'] === null ? 'Falta tarifa' : $money($l['amount_cents']) }}</b>
                            @unless ($l['paid'])<button type="button" class="wtp-rep wpay-change" wire:click="edit({{ $l['event_id'] }}, {{ $p['resource_id'] }}, {{ $l['amount_cents'] }}, @js($l['note']))">Cambiar</button>@endunless</div>
                    @endif
                </div>
            @endforeach
        </section>
    @empty
        <div class="wtp-empty">Nadie trabajó esta semana según «Planear el fin de semana».</div>
    @endforelse
</x-filament-panels::page>
