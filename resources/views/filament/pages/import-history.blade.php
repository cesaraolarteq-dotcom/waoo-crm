<x-filament-panels::page>
    @php($P = \App\Filament\Pages\ImportHistory::class)
    @php($v = $this->view())
    @if (! $v)
        <x-filament::section>
            <x-slot name="heading">1. Sube el archivo del historial</x-slot>
            <p class="wqb-hint">Un CSV en UTF-8 con las 19 columnas del historial (project_id … event_type) más las columnas opcionales aprobadas, máximo 5 MB y 2,000 filas. No lo mandes por chat ni correo: súbelo solo aquí. Queda cifrado y no se guarda el nombre del archivo.</p>
            <form wire:submit="stageFile" class="wimp-form">
                <input type="file" wire:model="csv" accept=".csv,text/csv" aria-label="Archivo CSV del historial de HoneyBook">
                <button type="submit" class="wcal-btn on" wire:loading.attr="disabled" wire:target="csv,stageFile">Revisar archivo</button>
            </form>
            @error('csv')<p class="wmoney-warn">{{ $message }}</p>@enderror
        </x-filament::section>
        @if ($list = $this->batches())
            <x-filament::section>
                <x-slot name="heading">Archivos en revisión</x-slot>
                <ul class="wimp-list">
                    @foreach ($list as $b)
                        <li><button type="button" class="wcal-btn" wire:click="open({{ $b['id'] }})">Archivo {{ $b['id'] }} · subido {{ $b['when'] }}</button></li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    @elseif (isset($v['error']))
        <x-filament::section>
            <p class="wmoney-warn">{{ $v['error'] }}</p>
            <button type="button" class="wcal-btn" wire:click="open(null)">Volver</button>
        </x-filament::section>
    @else
        @php($r = $v['ok'])
        <x-filament::section>
            <x-slot name="heading">2. Revisión del archivo {{ $r['batch']['id'] }}</x-slot>
            <x-slot name="afterHeader"><button type="button" class="wcal-btn" wire:click="open(null)">Otro archivo</button></x-slot>
            <div class="wimp-counts">
                @foreach ($P::COUNTS as $k => $label)
                    <div><b>{{ $r['counts'][$k] ?? 0 }}</b><span>{{ $label }}</span></div>
                @endforeach
            </div>
            <p class="wqb-hint">Estas cifras son lo que dice el archivo y se pueden repetir entre sí (una fila puede ser cancelada y no tener correo). Corte: {{ \Carbon\CarbonImmutable::parse($r['batch']['cutoff'])->setTimezone('America/Chicago')->locale('es')->translatedFormat('j M Y, g:i a') }}.</p>
            <p class="wfile-note">{{ $r['bulk']['eligible'] }} sin conflicto · {{ $r['bulk']['review_required'] }} por revisar · {{ $r['counts']['imported'] }} guardadas · {{ $r['counts']['excluded'] }} excluidas. Estado: {{ $r['batch']['state'] }}.</p>
            @if($r['commit_available'])
                @if(!$bulkPin)
                    <button type="button" class="wcal-btn on" wire:click="startBulk({{ $r['batch']['version'] }}, '{{ $r['coverage_sha256'] }}', '{{ $r['bulk']['plan_sha256'] }}')">Importar todas las filas sin conflicto</button>
                @else
                    <p>Se guardarán las filas sin conflicto. Las demás seguirán pendientes; no se cobra ni se envía nada.</p>
                    <label>Idioma para clientas nuevas <select wire:model="newCustomerLocale"><option value="es">Español</option><option value="en">Inglés</option></select></label>
                    <button type="button" class="wcal-btn on" wire:click="importSafe" wire:loading.attr="disabled">Confirmar importación sin conflicto</button>
                @endif
                <details><summary>Lista de revisión ({{ count($r['review_rows']) }})</summary>
                    @foreach($r['review_rows'] as $pending)<p><button type="button" wire:click="showRow({{ $pending['id'] }})">Revisar fila {{ $pending['id'] }}</button> · {{ implode(' · ', $P::issues($pending['reason_codes'], true)) }}</p>@endforeach
                </details>
                @if(!$closePin)<button type="button" class="wcal-btn" wire:click="startClose({{ $r['batch']['version'] }}, '{{ $r['coverage_sha256'] }}')">Cerrar lote…</button>
                @else <p>El cierre exige todas las filas resueltas y conserva la evidencia cifrada.</p><button type="button" class="wcal-btn" wire:click="closeBatch">Confirmar cierre</button>@endif
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Filas ({{ $r['page']['total'] }})</x-slot>
            <div class="wimp-table-wrap">
                <table class="wimp-table">
                    <thead><tr><th>#</th><th>Fecha</th><th>Revisar</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($r['rows'] as $i => $row)
                            @php($found = $P::issues($row['issue_codes']))
                            <tr @class(['is-open' => $rowId === $row['id']])>
                                <td>{{ ($r['page']['number'] - 1) * 25 + $i + 1 }}</td>
                                <td>{{ $row['source_date'] ?? 'Sin fecha válida' }}</td>
                                <td>{{ $found ? implode(' · ', $found) : 'Nada más' }}</td>
                                <td><button type="button" class="wcal-btn" wire:click="showRow({{ $row['id'] }})">Ver</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($r['page']['last'] > 1)
                <div class="wimp-pager">
                    <button type="button" class="wcal-btn" wire:click="goPage({{ $r['page']['number'] - 1 }})" @disabled($r['page']['number'] <= 1)>← Anterior</button>
                    <span>Página {{ $r['page']['number'] }} de {{ $r['page']['last'] }}</span>
                    <button type="button" class="wcal-btn" wire:click="goPage({{ $r['page']['number'] + 1 }})" @disabled($r['page']['number'] >= $r['page']['last'])>Siguiente →</button>
                </div>
            @endif
        </x-filament::section>

        @if ($rowId && ($d = $this->row()))
            <x-filament::section>
                <x-slot name="heading">Fila seleccionada</x-slot>
                <x-slot name="afterHeader"><button type="button" class="wcal-btn" wire:click="closeRow">Cerrar</button></x-slot>
                @if (isset($d['error']))
                    <p class="wmoney-warn">{{ $d['error'] }}</p>
                @else
                    @php($row = $d['ok'])
                    @php($n = $row['normalized'])
                    <p class="wqb-hint">Así viene en el archivo, sin cambios. El archivo está guardado cifrado. Ningún importe de origen acredita un cobro en el CRM.</p>
                    <dl class="wfile-dl">
                        @foreach ($row['source'] as $field => $value)
                            @php($label = $P::SOURCE[$field] ?? $field)
                            @if (($row['source'][$field] ?? '') !== '')
                                <div><dt>{{ $label }}</dt><dd>{{ $P::displaySource($field, $row['source'][$field]) }}</dd></div>
                            @endif
                        @endforeach
                    </dl>
                    <h3 class="wfile-h">Cómo lo entiende el CRM</h3>
                    <dl class="wfile-dl">
                        <div><dt>Fecha</dt><dd>{{ $n['source_date'] ?? 'Sin fecha válida' }}@if (($n['end_date'] ?? null) && $n['end_date'] !== $n['source_date']) → {{ $n['end_date'] }}@endif</dd></div>
                        <div><dt>Hora</dt><dd>
                            @if ($n['time_precision'] === 'date') Solo fecha, sin hora
                            @elseif ($n['start_at'] && $n['end_at'])
                                {{ \Carbon\CarbonImmutable::parse($n['start_at'])->setTimezone('America/Chicago')->format('g:i a') }} – {{ \Carbon\CarbonImmutable::parse($n['end_at'])->setTimezone('America/Chicago')->format('g:i a T') }}
                            @else Hora sin resolver @endif
                        </dd></div>
                        <div><dt>Total</dt><dd>{{ $P::money($n['booked_total_cents'], $n['currency']) }} <small>(lo que dice HoneyBook; no es un pago verificado)</small></dd></div>
                    </dl>
                    @if($row['previous'])<p>Cambios respecto a la versión {{ $row['previous']['version'] }}: {{ implode(' · ', $row['previous']['changed_fields']) ?: 'Decisión de clasificación' }}. Guardar una diferencia requiere tu motivo y conserva la versión anterior.</p>@endif
                    @if($row['commit_available'])
                        <label>Clienta <select wire:model="reviewCustomerId"><option value="">Crear con los datos de origen</option>
                            @foreach($row['customer_match']['candidates'] as $candidate)<option value="{{ $candidate['id'] }}">{{ $candidate['name'] }} · {{ $candidate['email'] }} · {{ $candidate['phone'] }}</option>@endforeach
                        </select></label>
                        <label>Estado del historial <select wire:model="reviewStatus"><option value="archived_unknown">Archivado, estado sin confirmar</option><option value="completed">Completado</option><option value="cancelled">Cancelado</option></select></label>
                        <label>Tipo <select wire:model="reviewType">@foreach(['unknown'=>'Sin confirmar','birthday'=>'Cumpleaños','baby_shower'=>'Baby shower','gender_reveal'=>'Revelación','corporate'=>'Corporativo','other'=>'Otro'] as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                        <label>Motivo de revisión o exclusión <textarea wire:model="reviewReason" maxlength="1000"></textarea></label>
                        <button type="button" wire:click="decideRow(false)" wire:loading.attr="disabled">Guardar esta fila</button>
                        <button type="button" wire:click="decideRow(true)" wire:loading.attr="disabled">Excluir con motivo</button>
                    @endif
                    @if ($all = $P::issues($row['issue_codes'], true))
                        <ul class="wimp-issues">@foreach ($all as $t)<li>{{ $t }}</li>@endforeach</ul>
                    @endif
                @endif
            </x-filament::section>
        @endif

        @if($r['commit_available'] && $r['counts']['imported'] === 0 && $r['counts']['excluded'] === 0)
        <x-filament::section>
            <x-slot name="heading">Descartar este archivo</x-slot>
            @if (! $discardPin)
                <p class="wqb-hint">Si subiste el archivo equivocado, descártalo. Se borran el archivo y todas sus filas de la base de datos (no de las copias de seguridad). Puedes volver a subirlo después.</p>
                <button type="button" class="wcal-btn" wire:click="startDiscard({{ $r['batch']['version'] }}, '{{ $r['coverage_sha256'] }}')">Descartar…</button>
            @else
                <form wire:submit="discard" class="wimp-form" style="flex-direction:column;align-items:stretch;gap:8px">
                    <label for="discard-reason"><b>¿Por qué lo descartas?</b> <small>(queda guardado en privado)</small></label>
                    <textarea id="discard-reason" wire:model="discardReason" rows="2" maxlength="1000" class="wqb-input"></textarea>
                    @error('discardReason')<p class="wmoney-warn">{{ $message }}</p>@enderror
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <button type="submit" class="wcal-btn danger" wire:loading.attr="disabled" wire:target="discard">Sí, descartar el archivo {{ $r['batch']['id'] }}</button>
                        <button type="button" class="wcal-btn" wire:click="cancelDiscard">No, volver</button>
                    </div>
                </form>
            @endif
        </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
