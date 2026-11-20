<x-filament-panels::page>
    @php($versions = $this->versions())
    @php($history = $versions->where('current', false))
    {{-- Owner 2026-11-19: one contract in use (plus the next draft to approve); older versions stay as history. --}}
    @forelse ($versions->where('current', true)->sortBy(fn ($v) => $v->state === 'active' ? 0 : 1) as $v)
        <x-filament::section>
            <x-slot name="heading">{{ $v->state === 'active' ? 'Contrato en uso' : 'Nuevo texto por aprobar' }} · versión {{ $v->version }}</x-slot>
            <x-slot name="afterHeader">
                <x-filament::badge :color="$v->state === 'active' ? 'success' : 'warning'">{{ $v->state === 'active' ? 'Activa' : 'Borrador' }}</x-filament::badge>
            </x-slot>
            <p class="wmoney-p">{{ $v->note }} · {{ $v->by }} · {{ $v->when }}@if ($v->approved) · aprobada {{ $v->approved }}@endif</p>
            <details class="wterms"><summary>Ver el texto</summary><div class="wterms-body">{!! $v->terms !!}</div></details>
            @if ($v->state === 'draft')
                <div style="margin-top:10px">{{ ($this->activateAction)(['id' => $v->id, 'version' => $v->version, 'sha' => $v->sha]) }}</div>
            @endif
        </x-filament::section>
    @empty
        <x-filament::section>
            <x-slot name="heading">Todavía no hay plantilla</x-slot>
            <p class="wmoney-p">Toca “Nueva versión del texto”, pega los términos y luego actívala. Sin una versión activa no se puede emitir ningún contrato.</p>
        </x-filament::section>
    @endforelse
    @if ($history->isNotEmpty())
        <details class="wterms"><summary>Versiones anteriores ({{ $history->count() }})</summary>
            @foreach ($history as $v)
                <div class="wterms-body">
                    <p class="wmoney-p"><strong>Versión {{ $v->version }}</strong> · {{ ['retired' => 'Retirada', 'replaced' => 'Reemplazada (nunca se usó)'][$v->state] ?? $v->state }} · {{ $v->note }}</p>
                    <details><summary>Ver el texto</summary>{!! $v->terms !!}</details>
                </div>
            @endforeach
        </details>
    @endif
</x-filament-panels::page>
