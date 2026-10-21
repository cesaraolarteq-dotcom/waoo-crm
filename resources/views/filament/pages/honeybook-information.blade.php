<x-filament-panels::page>
    <p>Datos originales conservados para decidir juntos dónde utilizarlos. No acreditan pagos, firmas ni permiso para enviar mensajes.</p>
    @php($entries = $this->entries())
    @forelse($entries as $entry)
        @php($source = $entry->evidence())
        <section class="rounded-xl border border-gray-200 p-4 space-y-2">
            <h2 class="font-semibold">{{ $source['record']['category'] }} · {{ $source['record']['field'] }}</h2>
            <p>Origen: {{ $source['record']['source_type'] }} · {{ $source['record']['source_id'] }}</p>
            <p>Extracción: {{ $source['extracted_at'] }} · Pendiente de organizar</p>
            <p class="whitespace-pre-wrap">{{ $source['record']['value'] }}</p>
        </section>
    @empty
        <p>Todavía no hay información adicional copiada. Las reservas y el historial se revisan por separado.</p>
    @endforelse
    {{ $entries->links() }}
</x-filament-panels::page>
