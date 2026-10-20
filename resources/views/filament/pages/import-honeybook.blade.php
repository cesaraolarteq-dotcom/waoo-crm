<x-filament-panels::page>
    @php($batch = $this->batch())
    @if (! $batch)
        <x-filament::section class="wimp-upload">
            <x-slot name="heading">1. Sube el archivo</x-slot>
            <p class="wqb-hint">Un CSV en UTF-8 con las 24 columnas acordadas (honeybook_project_id … notes). No lo mandes por chat ni correo: súbelo solo aquí.</p>
            <form wire:submit="stageFile" class="wimp-form">
                <input type="file" wire:model="csv" accept=".csv,text/csv" aria-label="Archivo CSV de HoneyBook">
                <button type="submit" class="wcal-btn on" wire:loading.attr="disabled" wire:target="csv,stageFile">Revisar archivo</button>
            </form>
            @error('csv')<p class="wmoney-warn">{{ $message }}</p>@enderror
        </x-filament::section>
        @if ($list = $this->batches())
            <x-filament::section>
            <x-slot name="heading">Archivos subidos</x-slot>
                <ul class="wimp-list">
                    @foreach ($list as $b)
                        <li><button type="button" class="wcal-btn" wire:click="$set('batchId', {{ $b['id'] }})">Lote {{ $b['id'] }} · {{ $b['rows'] }} filas · {{ $b['when'] }} · {{ $b['status'] }}</button></li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    @else
        @php($rec = $batch->reconciliation)
        @php($cov = $this->coverage())
        <x-filament::section>
            <x-slot name="heading">2. Revisión del lote {{ $batch->id }}</x-slot>
            <x-slot name="afterHeader"><button type="button" class="wcal-btn" wire:click="$set('batchId', null)">Otro archivo</button></x-slot>
            <div class="wimp-counts">
                @foreach (\App\Filament\Pages\ImportHoneyBook::STATUS as $k => $label)
                    <div><b>{{ $rec['counts'][$k] ?? 0 }}</b><span>{{ $label }}</span></div>
                @endforeach
            </div>
            <dl class="wfile-dl wimp-totals" style="margin:12px 0">
                @foreach (['total_cents' => 'Total vendido', 'paid_cents' => 'Pagado (según HoneyBook)', 'balance_cents' => 'Saldo pendiente', 'travel_cents' => 'Costo de transporte'] as $f => $label)
                    @php($t = $rec['totals'][$f] ?? null)
                    <div><dt>{{ $label }}</dt><dd>{{ \App\Filament\Support\Labels::money($t['sum_cents'] ?? 0) }}@if (($t['unknown_count'] ?? 0) > 0) <small>· {{ $t['unknown_count'] }} sin dato</small>@endif</dd></div>
                @endforeach
            </dl>
            <p class="wqb-hint">Lo pagado se guarda como "importado de HoneyBook", no como Zelle o tarjeta verificados. Si el total de una fila no cuadra (por ejemplo, incluye el cargo de tarjeta), puedes importarla aceptando la diferencia con una nota tuya; los importes originales no cambian. Los demás errores deben corregirse antes de importar.</p>
        </x-filament::section>

        @if ($cov)
            <x-filament::section>
            <x-slot name="heading">Cobertura</x-slot>
                <p>{{ $cov['counts']['draft'] + $cov['counts']['capacity_confirmed'] }} de {{ $cov['counts']['source_rows'] - $cov['counts']['skipped'] }} eventos importados ·
                    {{ $cov['counts']['capacity_confirmed'] }} con personal y equipo confirmados ·
                    {{ $cov['counts']['unresolved'] }} por corregir · {{ $cov['counts']['unmapped'] }} sin importar
                    @if ($cov['counts']['outdated']) · {{ $cov['counts']['outdated'] }} cambiaron en el archivo @endif</p>
                @if ($cov['accepted_source_differences'] ?? [])
                    <p class="wqb-hint">{{ count($cov['accepted_source_differences']) }} {{ count($cov['accepted_source_differences']) === 1 ? 'evento importado' : 'eventos importados' }} con una diferencia de total aceptada por ti (con nota).</p>
                @endif
                @if ($batch->status === 'imported_cleanup_pending')
                    <p class="wmoney-warn">Todo quedó importado, pero el archivo original todavía no se pudo borrar.</p>
                    <button type="button" class="wcal-btn on" wire:click="{{ $coverageVersion ? 'finalize' : 'attestCoverage('.$cov['version'].')' }}">{{ $coverageVersion ? 'Reintentar borrar el archivo' : 'Revisar y reintentar' }}</button>
                @elseif ($batch->status === 'imported')
                    <p class="wqb-hint">Lote cerrado: el archivo original se borró.</p>
                @elseif ($cov['domain_complete'])
                    {{-- The attestation pins the batch version shown here; any later change requires a new review. --}}
                    <label class="wimp-check"><input type="checkbox" @checked($coverageReviewed) wire:click="attestCoverage({{ $cov['version'] }})" @disabled($coverageReviewed)> Revisé que el archivo tiene todos los eventos futuros confirmados de HoneyBook.</label>
                    @error('coverageReviewed')<p class="wmoney-warn">{{ $message }}</p>@enderror
                    <button type="button" class="wcal-btn on" wire:click="finalize" @disabled(! $coverageReviewed)>Cerrar lote y borrar el archivo</button>
                @else
                    <p class="wqb-hint">Para cerrar el lote, cada fila que no esté omitida tiene que estar importada.</p>
                @endif
            </x-filament::section>
        @endif

        @if ($row = $this->row())
            <x-filament::section class="wimp-row" x-data x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'start' })" wire:key="row-panel-{{ $rowId }}">
            <x-slot name="heading">3. Importar la fila {{ $row['line'] }} · {{ $row['source']['project_name'] }}</x-slot>
                <div class="wfile">
                    <dl class="wfile-dl">
                        <div><dt>Clienta</dt><dd>{{ $row['source']['client_first_name'] }} {{ $row['source']['client_last_name'] }}</dd></div>
                        <div><dt>Contacto</dt><dd>{{ $row['source']['client_email'] }} · {{ $row['source']['client_phone'] }}</dd></div>
                        <div><dt>Fecha</dt><dd>{{ $row['source']['event_date'] }} · {{ $row['source']['start_time'] }}–{{ $row['source']['end_time'] }} (Houston)</dd></div>
                        <div><dt>Lugar</dt><dd>{{ $row['source']['event_address'] }} · {{ $row['source']['event_zip'] }}</dd></div>
                        <div><dt>En HoneyBook</dt><dd>{{ $row['source']['services'] }}@if ($row['source']['characters']) · {{ $row['source']['characters'] }}@endif</dd></div>
                        <div><dt>Contrato</dt><dd>{{ $row['source']['contract_signed'] === 'yes' ? 'Firmado en HoneyBook (se queda allá)' : 'Sin firmar en HoneyBook' }}</dd></div>
                        @if ($row['source']['notes'])<div><dt>Notas</dt><dd>{{ $row['source']['notes'] }}</dd></div>@endif
                    </dl>
                    <dl class="wfile-dl">
                        @foreach ($row['money'] as $label => $cents)
                            <div><dt>{{ $label }}</dt><dd>{{ \App\Filament\Support\Labels::money($cents, 'Sin dato') }}</dd></div>
                        @endforeach
                        <div><dt>Pagos</dt><dd>@forelse ($row['payments'] as $p){{ $p }}<br>@empty Ninguno @endforelse</dd></div>
                        @if ($row['warnings'])<div><dt>Aviso</dt><dd class="wmoney-warn">Hay pagos repetidos idénticos: revisa que no estén duplicados.</dd></div>@endif
                    </dl>
                </div>
                @if ($row['difference'])
                    <div class="wmoney-warn wimp-diff">
                        <b>El total de HoneyBook no coincide con la suma.</b>
                        Paquete + transporte − descuento = {{ \App\Filament\Support\Labels::money($row['difference']['expected']) }};
                        HoneyBook dice {{ \App\Filament\Support\Labels::money($row['difference']['total']) }}
                        (diferencia {{ $row['difference']['delta'] >= 0 ? '+' : '−' }}{{ \App\Filament\Support\Labels::money(abs($row['difference']['delta'])) }}).
                        Se guarda tal cual solo si lo aceptas y escribes una nota abajo.
                    </div>
                @endif
                @if ($mappingSuggested)<p class="wqb-hint">Equivalencia sugerida por una fila anterior con el mismo paquete y personajes: revísala antes de importar.</p>@endif
                @if (! $row['matches'])<p class="wqb-hint">No hay fichas con este correo o teléfono: se creará una clienta nueva.</p>@endif
                {{ $this->content }}
            </x-filament::section>
        @endif

        @if ($resync)
            @php($v = $resync)
            <x-filament::section id="resync-panel">
                <x-slot name="heading">Revisar pagos de HoneyBook</x-slot>
                <x-slot name="description">Solo se actualiza lo que la clienta pagó en HoneyBook. No se vuelve a reservar, no cambia el precio y HoneyBook sigue cobrando. Estos pagos no están verificados en tu banco.</x-slot>
                <x-slot name="afterHeader"><button type="button" class="wcal-btn" wire:click="closeResync">Cerrar</button></x-slot>
                @if ($v['kind'] === 'unchanged')
                    <p>No cambió nada desde la última vez. No hay que hacer nada.</p>
                @elseif ($v['kind'] === 'new')
                    <p>Esta fiesta todavía no está importada: usa “Importar”.</p>
                @else
                    @if ($v['changes'])
                        <div class="wimp-table-wrap"><table class="wimp-table">
                            <thead><tr><th></th><th>Antes (en el CRM)</th><th>Ahora (en HoneyBook)</th></tr></thead>
                            <tbody>@foreach ($v['changes'] as $ch)<tr><td><b>{{ \App\Filament\Pages\ImportHoneyBook::RESYNC_FIELDS[$ch['field']] ?? $ch['field'] }}</b></td>
                                <td>{{ \App\Filament\Pages\ImportHoneyBook::resyncValue($ch['field'], $ch['from']) }}</td><td>{{ \App\Filament\Pages\ImportHoneyBook::resyncValue($ch['field'], $ch['to']) }}</td></tr>@endforeach</tbody>
                        </table></div>
                    @endif
                    @if ($v['blocked'])
                        <p class="wmoney-warn" role="alert"><b>No se puede actualizar desde aquí:</b></p>
                        <ul class="wchg-effects">@foreach ($v['blocked'] as $b)<li>{{ \App\Filament\Pages\ImportHoneyBook::RESYNC_BLOCKS[$b['code']] ?? $b['code'] }}</li>@endforeach</ul>
                    @elseif ($v['kind'] === 'money_only')
                        @if ($v['source_difference']['can_retain'] ?? false)
                            <label class="wimp-check"><input type="checkbox" wire:model="resyncRetain"> Mantener la diferencia de total que ya aceptaste (por ejemplo, el 3 % de tarjeta)</label>
                        @endif
                        <label class="wimp-check"><input type="checkbox" wire:model="resyncReviewed"> Revisé estos pagos en HoneyBook</label>
                        <label class="p-field" style="display:grid;gap:4px;max-width:640px"><span>Nota (solo la ves tú)</span>
                            <textarea wire:model="resyncNote" rows="2" maxlength="1000" placeholder="Ej.: pagó el saldo por Zelle el 20/10" style="width:100%;padding:8px;border:1px solid #DCE1EE;border-radius:10px"></textarea></label>
                        <div class="wrep-actions"><button type="button" class="wcal-btn on" wire:click="applyResync">Actualizar pagos</button></div>
                    @endif
                @endif
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Filas del archivo</x-slot>
            <div class="wimp-table-wrap">
                <table class="wimp-table">
                    <thead><tr><th>Línea</th><th>Fecha (Houston)</th><th>Clienta</th><th>En HoneyBook</th><th>Total</th><th>Pagado</th><th>Saldo</th><th>Estado</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($this->rows() as $r)
                        <tr wire:key="row-{{ $r['id'] }}" @class(['is-open' => $rowId === $r['id']])>
                            <td>{{ $r['line'] }}</td><td>{{ $r['when'] }}</td><td>{{ $r['client'] }}</td>
                            <td>{{ $r['services'] }}@if ($r['characters'])<br><small>{{ $r['characters'] }}</small>@endif<br><small>{{ $r['contract'] }}</small></td>
                            <td>{{ $r['total'] }}</td><td>{{ $r['paid'] }}</td><td>{{ $r['balance'] }}</td>
                            <td>
                                <span @class(['wimp-pill', 'is-'.($r['imported_current'] ? 'ready' : $r['status'])])>{{ $r['imported_current'] ? 'Importada' : (\App\Filament\Pages\ImportHoneyBook::STATUS[$r['status']] ?? $r['status']) }}</span>
                                @if ($r['skip'])<br><small>{{ $r['skip'] === 'past' ? 'Ya pasó' : 'Cancelado' }}</small>@endif
                                @unless ($r['imported_current'])@foreach ($r['reasons'] as $why)<br><small>{{ $why }}</small>@endforeach @endunless
                                @if ($r['outdated'])<br><small>{{ $r['money_review'] ? 'Cambiaron los pagos en HoneyBook: revísalos' : 'Cambió en el archivo: vuelve a importarla' }}</small>@endif
                            </td>
                            <td>
                                @if ($r['event_id'])<a class="wcal-btn" href="{{ \App\Filament\Resources\Events\EventResource::getUrl('view', ['record' => $r['event_id']]) }}">Ver evento</a>@endif
                                @if ($r['money_review'])<button type="button" class="wcal-btn on" wire:click="openResync({{ $r['id'] }})">Revisar pagos</button>
                                @elseif ($r['importable'])<button type="button" class="wcal-btn on" wire:click="openRow({{ $r['id'] }})">{{ $r['outdated'] ? 'Actualizar' : 'Importar' }}</button>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
