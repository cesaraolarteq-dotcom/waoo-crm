<div class="wqb-hold">
    @php
        $state = $hold['state'] ?? 'review_required';
        $issues = $hold['issues'] ?? [];
        $integrity = count(array_intersect($issues, ['integrity_invalid','hold_unavailable'])) > 0;
        $rows = $integrity ? [] : ($hold['rows'] ?? []);
        $labels = ['held'=>'Trajes apartados', 'review_required'=>'Apartado por revisar', 'blocked'=>'No se pudo apartar el traje',
            'needs_character'=>'Falta elegir el personaje', 'not_required'=>'Este paquete no necesita unidad de traje',
            'not_attempted'=>'Apartado pendiente', 'released'=>'Trajes liberados', 'consumed'=>'Trajes incluidos en la asignación'];
    @endphp
    <h3>{{ $labels[$state] ?? 'Apartado por revisar' }}</h3>
    <p>El depósito sigue registrado. Personal, equipo y traslados se confirman por separado en la ficha del evento.</p>
    @if (in_array('timing_unknown', $issues, true))
        <p class="wmoney-warn" role="status">El traje está apartado con el horario conocido. Faltan minutos de montaje o desmontaje; hay que revisarlos antes de confirmar la capacidad.</p>
    @endif
    @if ($integrity)<p class="wmoney-warn" role="status">No se puede comprobar el inventario apartado. Actualiza y revisa la ficha; no se dará por disponible.</p>@endif
    @if ($state === 'blocked')<p class="wmoney-warn">No hay una unidad disponible para este horario y vestuario. Revisa otra unidad, personaje u horario antes de confirmar.</p>@endif
    @if ($rows)
        <ul>
            @foreach ($rows as $row)
                <li>{{ $row['character_name'] }}@if ($row['outfit'] !== null) · Vestuario: {{ $row['outfit'] }}@endif <small>· Unidad #{{ $row['resource_id'] }}</small></li>
            @endforeach
        </ul>
    @endif
    @if ($problem)<p class="wmoney-warn" role="alert">{{ $problem }}</p>@endif
    <div class="wqb-next">
        <button type="button" class="wcal-btn" wire:click="reviewLatest" wire:loading.attr="disabled">Actualizar apartado</button>
        @if (! $integrity && in_array($state, ['not_attempted','blocked','needs_character','released'], true))
            <button type="button" class="wcal-btn" wire:click="attempt" wire:loading.attr="disabled">Intentar apartar trajes</button>
        @endif
    </div>
    @if (! $integrity && $rows && in_array($state, ['held','review_required'], true))
        <details>
            <summary>Liberar solo los trajes de esta fiesta</summary>
            <p>No cancela la fiesta ni devuelve el depósito.</p>
            <label>Motivo de la liberación <textarea wire:model="reason" minlength="10" maxlength="1000" rows="2" required @disabled($releaseInput !== null)></textarea></label>
            @error('reason')<p role="alert">{{ $message }}</p>@enderror
            <button type="button" class="wcal-btn" wire:click="releaseHold" wire:loading.attr="disabled"
                wire:confirm="¿Liberar estos trajes? La fiesta y el pago seguirán guardados.">Liberar trajes</button>
        </details>
    @endif
</div>
