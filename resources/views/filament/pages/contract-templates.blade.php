<x-filament-panels::page>
    @php($versions = $this->versions())
    @forelse ($versions as $v)
        <x-filament::section>
            <x-slot name="heading">Versión {{ $v->version }}</x-slot>
            <x-slot name="afterHeader">
                <x-filament::badge :color="$v->state === 'active' ? 'success' : ($v->state === 'draft' ? 'warning' : 'gray')">
                    {{ ['active' => 'Activa', 'draft' => 'Borrador', 'retired' => 'Retirada'][$v->state] ?? $v->state }}
                </x-filament::badge>
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
</x-filament-panels::page>
