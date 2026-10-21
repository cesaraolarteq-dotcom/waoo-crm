@php
    $statePath = $getStatePath();
    $busy = $getBusy();
    $min = $getMinDate();
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    {{-- Owner 2026-10-21: always-visible month calendar (see App\Filament\Support\PartyCalendar). --}}
    <div class="waoo-cal" wire:ignore
        x-data="{
            state: $wire.{{ $field->applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            busy: @js($busy),
            min: @js($min),
            months: ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'],
            longDays: ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'],
            view: null,
            init() {
                const start = (this.state || this.min || new Date().toISOString().slice(0, 10)).slice(0, 7).split('-');
                this.view = { y: +start[0], m: +start[1] - 1 };
            },
            pad(n) { return String(n).padStart(2, '0') },
            iso(d) { return this.view.y + '-' + this.pad(this.view.m + 1) + '-' + this.pad(d) },
            cells() {
                const first = new Date(this.view.y, this.view.m, 1).getDay();
                const days = new Date(this.view.y, this.view.m + 1, 0).getDate();
                return [...Array(first).fill(null), ...Array.from({ length: days }, (_, i) => i + 1)];
            },
            move(step) { const d = new Date(this.view.y, this.view.m + step, 1); this.view = { y: d.getFullYear(), m: d.getMonth() } },
            canGoBack() { return !this.min || (this.view.y * 12 + this.view.m) > (+this.min.slice(0, 4) * 12 + +this.min.slice(5, 7) - 1) },
            past(d) { return this.min && this.iso(d) < this.min },
            count(d) { return this.busy[this.iso(d)] || 0 },
            pick(d) { if (!this.past(d)) this.state = this.iso(d) },
            label() {
                if (!this.state) return 'Elige el día de la fiesta';
                const [y, m, d] = this.state.slice(0, 10).split('-').map(Number);
                const w = new Date(y, m - 1, d).getDay();
                return this.longDays[w] + ' ' + d + ' de ' + this.months[m - 1] + ' de ' + y;
            },
            dayAria(d) { const n = this.count(d); return d + ' de ' + this.months[this.view.m] + (n ? ', ' + n + (n === 1 ? ' fiesta' : ' fiestas') : '') },
        }">
        <div class="waoo-cal-head">
            <button type="button" class="waoo-cal-nav" x-on:click="move(-1)" x-bind:disabled="!canGoBack()" aria-label="Mes anterior">‹</button>
            <span class="waoo-cal-title" x-text="months[view.m] + ' ' + view.y" aria-live="polite"></span>
            <button type="button" class="waoo-cal-nav" x-on:click="move(1)" aria-label="Mes siguiente">›</button>
        </div>
        <div class="waoo-cal-grid" role="grid">
            <template x-for="w in ['D','L','M','M','J','V','S']"><span class="waoo-cal-dow" x-text="w" aria-hidden="true"></span></template>
            <template x-for="(d, i) in cells()" :key="view.y + '-' + view.m + '-' + i">
                <div class="waoo-cal-cell">
                    <template x-if="d">
                        <button type="button" class="waoo-cal-day" x-on:click="pick(d)" x-bind:disabled="past(d)" x-bind:aria-label="dayAria(d)"
                            x-bind:aria-pressed="state && state.slice(0, 10) === iso(d) ? 'true' : 'false'"
                            x-bind:class="{ 'is-selected': state && state.slice(0, 10) === iso(d), 'is-today': min === iso(d), 'is-one': count(d) === 1, 'is-many': count(d) > 1 }">
                            <span x-text="d"></span>
                        </button>
                    </template>
                </div>
            </template>
        </div>
        <p class="waoo-cal-picked" x-text="label()"></p>
        <p class="waoo-cal-legend"><span class="dot"></span> 1 fiesta <span class="bar"></span> 2 o más</p>
    </div>
</x-dynamic-component>
