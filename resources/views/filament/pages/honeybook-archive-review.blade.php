<x-filament-panels::page>
    @php($r=$this->report())
    <p>{{ $r['historical_projects'] }} fiestas archivadas · {{ $r['prospects'] }} prospectos. Solo información de origen: no son deudas ni pagos verificados.</p>
    <x-filament::section><x-slot name="heading">Saldos pendientes de HoneyBook para revisar</x-slot>
        @forelse($r['balances_to_review'] as $item)
            <p>{{ $item['source_date'] }} · {{ $item['project_name'] }} · {{ \App\Filament\Support\Labels::money($item['balance_cents']) }}
                <a href="{{ \App\Filament\Resources\Customers\CustomerResource::getUrl('view',['record'=>$item['customer_id']]) }}">Revisar historial</a></p>
        @empty <p>No hay saldos positivos de origen entre las fiestas históricas copiadas.</p> @endforelse
    </x-filament::section>
    <x-filament::section><x-slot name="heading">Cómo nos conoció — fuente HoneyBook</x-slot>
        @forelse($r['lead_sources'] as $source)<p>{{ $source['label'] }} · {{ $source['projects'] }} proyectos · {{ $source['customers'] }} clientas</p>
        @empty <p>Sin datos de origen disponibles.</p> @endforelse
    </x-filament::section>
</x-filament-panels::page>
