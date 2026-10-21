<x-filament-panels::page>
    @php($r=$this->report())
    <p>{{ $r['historical_projects'] }} fiestas archivadas · {{ $r['prospects'] }} prospectos. Solo información de origen: no son deudas ni pagos verificados.</p>
    <p>{{ $r['balance_counts']['unresolved'] }} sin resolver · {{ $r['balance_counts']['resolved'] }} con decisión del dueño · {{ $r['balance_counts']['pending_reschedule'] }} pendientes de reprogramar.</p>
    <x-filament::section><x-slot name="heading">Saldos pendientes de HoneyBook para revisar</x-slot>
        @forelse($r['balances_to_review'] as $item)
            <p>{{ $item['source_date'] }} · {{ $item['project_name'] }} · {{ \App\Filament\Support\Labels::money($item['balance_cents']) }}
                @if($resolution=$item['balance_resolution'])
                    <span>{{ \App\Filament\Pages\HoneyBookArchiveReview::RESOLUTIONS[$resolution['kind']] }}</span>
                    @if($resolution['stale'])
                        <span>Origen actualizado: revisar de nuevo</span>
                    @endif
                    <span>{{ $resolution['owner_note'] }}</span>
                @endif
                <button type="button" wire:click="startResolution({{ $item['history_id'] }}, {{ $item['history_version'] }}, {{ $item['revision_id'] }}, '{{ $item['source_sha256'] }}', {{ $item['balance_resolution']['version'] ?? 0 }})">Registrar o corregir decisión</button>
                <a href="{{ \App\Filament\Resources\Customers\CustomerResource::getUrl('view',['record'=>$item['customer_id']]) }}">Revisar historial</a></p>
        @empty <p>No hay saldos positivos de origen entre las fiestas históricas copiadas.</p> @endforelse
    </x-filament::section>
    @if($resolutionPin)
        <x-filament::section><x-slot name="heading">Decisión del dueño sobre el saldo original</x-slot>
            <p>No cambia el importe de HoneyBook ni registra dinero en el banco. Reprogramar exige elegir una fecha aparte.</p>
            <form wire:submit="saveResolution">
                <label>Decisión <select wire:model="resolutionKind">@foreach(\App\Filament\Pages\HoneyBookArchiveReview::RESOLUTIONS as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                <label>Nota de ustedes <textarea wire:model="resolutionNote" maxlength="2000" required></textarea></label>
                @error('resolutionNote')<p>{{ $message }}</p>@enderror
                <button type="submit" wire:loading.attr="disabled">Guardar decisión</button>
                <button type="button" wire:click="cancelResolution">Cancelar</button>
            </form>
        </x-filament::section>
    @endif
    <x-filament::section><x-slot name="heading">Cómo nos conoció — fuente HoneyBook</x-slot>
        @forelse($r['lead_sources'] as $source)<p>{{ $source['label'] }} · {{ $source['projects'] }} proyectos · {{ $source['customers'] }} clientas</p>
        @empty <p>Sin datos de origen disponibles.</p> @endforelse
    </x-filament::section>
</x-filament-panels::page>
