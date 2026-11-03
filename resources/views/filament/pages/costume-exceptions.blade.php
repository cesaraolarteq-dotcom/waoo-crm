<x-filament-panels::page>
    <p class="wtp-muted wce-intro">Casi todos los trajes siguen la regla normal: los de <b>cabeza</b> los usa cualquier Personaje y los de <b>peluca</b> solo quien tiene "Personaje con peluca". Aquí marcas solo los trajes que únicamente pueden usar ciertas personas (por talla o altura).</p>

    <section class="wtp-card">
        <header><b class="wtp-title">Marcar un traje</b></header>
        <label class="wce-field"><span>Traje</span>
            <select wire:model.live="costumeId" aria-label="Traje">
                <option value="">Elige un traje…</option>
                @foreach ($this->costumes() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </label>
        @if ($costumeId)
            <p class="wtp-muted">{{ $this->rule() }}</p>
            <fieldset class="wce-people">
                <legend>¿Quién puede usarlo? Si marcas personas, <b>solo ellas</b> lo podrán usar.</legend>
                @forelse ($this->performers() as $id => $name)
                    <label class="wce-person" wire:key="p-{{ $id }}"><input type="checkbox" value="{{ $id }}" wire:model="people"> {{ $name }}</label>
                @empty
                    <p class="wtp-muted">Nadie del staff tiene el rol "Personaje" todavía.</p>
                @endforelse
            </fieldset>
            <div class="wce-actions">
                <button type="button" class="wcal-btn on" wire:click="save">Guardar</button>
                @if ($people !== [])<button type="button" class="wcal-btn" wire:click="clear">Volver a la regla normal</button>@endif
            </div>
        @endif
    </section>

    @php($list = $this->exceptions())
    <h3 class="wce-h">Trajes con excepción <span class="wtp-muted">· {{ count($list) }}</span></h3>
    @forelse ($list as $c)
        <button type="button" class="wtp-card wce-row" wire:key="c-{{ $c['id'] }}" wire:click="pick({{ $c['id'] }})">
            <b class="wtp-title">{{ $c['name'] }}</b>
            <span class="wtp-muted">Solo: {{ implode(', ', $c['people']) }}</span>
        </button>
    @empty
        <p class="wtp-muted">Ninguno. Todos los trajes siguen la regla normal.</p>
    @endforelse
</x-filament-panels::page>
