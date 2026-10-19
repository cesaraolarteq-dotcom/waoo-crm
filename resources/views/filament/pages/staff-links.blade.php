@php($list = $this->rows())
<x-filament-panels::page>
    @if ($list['sample'])
        <p class="wmoney-warn" role="note">Vista previa con personas de ejemplo. Los botones se activan cuando Codex termine su parte; ahora no se crea ningún enlace.</p>
    @endif

    @if ($newLink)
        @include('filament.pages.partials.link-panel', ['panel' => $newLink])
    @endif

    <x-filament::section>
        <x-slot name="heading">Tu equipo</x-slot>
        <x-slot name="description">Cuando confirmas una fiesta con alguien, aparece sola en su enlace. Tú le preguntas antes por WhatsApp; aquí no responden nada.</x-slot>
        @forelse ($list['data'] as $r)
            <div class="wmoney-row">
                <div><b>{{ $r['display_name'] }}</b> · {{ $r['locale'] === 'en' ? 'English' : 'Español' }}
                    @if (! $r['active'])<x-filament::badge color="gray" size="sm">Inactiva</x-filament::badge>
                    @elseif ($r['grant'])<x-filament::badge color="success" size="sm">Enlace activo</x-filament::badge>
                    @else<x-filament::badge color="warning" size="sm">Sin enlace</x-filament::badge>@endif
                </div>
                @if ($r['grant'])<small>{{ \App\Filament\Pages\StaffLinks::opened($r['grant']['last_opened_at']) }}</small>@endif
                <div class="wrep-actions" style="margin-top:6px">
                    {{ ($this->configureAction)(['staff' => $r['staff_id']]) }}
                @if ($r['active'])
                        @if ($r['grant'])
                            {{ ($this->revealAction)(['staff' => $r['staff_id']]) }}
                            {{ ($this->rotateAction)(['staff' => $r['staff_id']]) }}
                            {{ ($this->revokeAction)(['staff' => $r['staff_id']]) }}
                        @else
                            {{ ($this->issueAction)(['staff' => $r['staff_id']]) }}
                        @endif
                @endif
                </div>
            </div>
        @empty
            <p class="wqb-hint">Todavía nadie tiene su página. Configura a tu staff abajo y crea su enlace.</p>
        @endforelse
    </x-filament::section>

    @php($new = \App\Filament\Support\StaffLinksSource::unconfigured())
    @if ($new)
        <x-filament::section>
            <x-slot name="heading">Staff sin configurar ({{ count($new) }})</x-slot>
            <x-slot name="description">Personas activas del staff que todavía no tienen página. Elige su idioma y, si quieres, su teléfono; luego crea su enlace.</x-slot>
            @foreach ($new as $u)
                <div class="wmoney-row"><div><b>{{ $u['name'] }}</b></div>
                    <div class="wrep-actions" style="margin-top:6px">{{ ($this->configureAction)(['resource' => $u['resource_id']]) }}</div></div>
            @endforeach
        </x-filament::section>
    @endif

    <p class="wqb-hint">El enlace no vence solo. Si alguien lo pierde o lo comparte, usa “Cambiar enlace”: el anterior deja de funcionar al instante. Si deja de trabajar contigo, “Apagar”.</p>
</x-filament-panels::page>
