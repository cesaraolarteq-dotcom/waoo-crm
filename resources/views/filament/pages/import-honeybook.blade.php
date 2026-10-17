<x-filament-panels::page>
    @php($batch = $this->batch())
    @if (! $batch)
        <section class="wdash-card wimp-upload">
            <h2>1. Sube el archivo</h2>
            <p class="wqb-hint">Un CSV en UTF-8 con las 24 columnas acordadas (honeybook_project_id … notes). No lo mandes por chat ni correo: súbelo solo aquí.</p>
            <form wire:submit="upload" class="wimp-form">
                <input type="file" wire:model="csv" accept=".csv,text/csv" aria-label="Archivo CSV de HoneyBook">
                <button type="submit" class="wcal-btn on" wire:loading.attr="disabled" wire:target="csv,upload">Revisar archivo</button>
            </form>
            @error('csv')<p class="wmoney-warn">{{ $message }}</p>@enderror
        </section>
        @if ($list = $this->batches())
            <section class="wdash-card">
                <h2>Archivos subidos</h2>
                <ul class="wimp-list">
                    @foreach ($list as $b)
                        <li><button type="button" class="wcal-btn" wire:click="$set('batchId', {{ $b['id'] }})">Lote {{ $b['id'] }} · {{ $b['rows'] }} filas · {{ $b['when'] }} · {{ $b['status'] }}</button></li>
                    @endforeach
                </ul>
            </section>
        @endif
    @else
        @php($rec = $batch->reconciliation)
        @php($cov = $this->coverage())
        <section class="wdash-card">
            <div class="wimp-head">
                <h2>2. Revisión del lote {{ $batch->id }}</h2>
                <button type="button" class="wcal-btn" wire:click="$set('batchId', null)">Otro archivo</button>
            </div>
            <div class="wimp-counts">
                @foreach (\App\Filament\Pages\ImportHoneyBook::STATUS as $k => $label)
                    <div><b>{{ $rec['counts'][$k] ?? 0 }}</b><span>{{ $label }}</span></div>
                @endforeach
            </div>
            <dl class="wfile-dl wimp-totals">
                @foreach (['total_cents' => 'Total vendido', 'paid_cents' => 'Pagado (según HoneyBook)', 'balance_cents' => 'Saldo pendiente', 'travel_cents' => 'Costo de transporte'] as $f => $label)
                    @php($t = $rec['totals'][$f] ?? null)
                    <div><dt>{{ $label }}</dt><dd>{{ \App\Filament\Support\Labels::money($t['sum_cents'] ?? 0) }}@if (($t['unknown_count'] ?? 0) > 0) <small>· {{ $t['unknown_count'] }} sin dato</small>@endif</dd></div>
                @endforeach
            </dl>
            <p class="wqb-hint">Lo pagado se guarda como "importado de HoneyBook", no como Zelle o tarjeta verificados. Las filas con diferencias de importes no se pueden importar hasta corregirlas en el archivo.</p>
        </section>

        @if ($cov)
            <section class="wdash-card">
                <h2>Cobertura</h2>
                <p>{{ $cov['counts']['draft'] + $cov['counts']['capacity_confirmed'] }} de {{ $cov['counts']['source_rows'] - $cov['counts']['skipped'] }} eventos importados ·
                    {{ $cov['counts']['capacity_confirmed'] }} con personal y equipo confirmados ·
                    {{ $cov['counts']['unresolved'] }} por corregir · {{ $cov['counts']['unmapped'] }} sin importar@if ($cov['counts']['outdated']) · {{ $cov['counts']['outdated'] }} cambiaron en el archivo@endif</p>
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
            </section>
        @endif

        @if ($row = $this->row())
            <section class="wdash-card wimp-row">
                <h2>3. Importar la fila {{ $row['line'] }} · {{ $row['source']['project_name'] }}</h2>
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
            </section>
        @endif

        <section class="wdash-card">
            <h2>Filas del archivo</h2>
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
                                <span @class(['wimp-pill', 'is-'.$r['status']])>{{ $r['imported_current'] ? 'Importada' : (\App\Filament\Pages\ImportHoneyBook::STATUS[$r['status']] ?? $r['status']) }}</span>
                                @if ($r['skip'])<br><small>{{ $r['skip'] === 'past' ? 'Ya pasó' : 'Cancelado' }}</small>@endif
                                @foreach ($r['reasons'] as $why)<br><small>{{ $why }}</small>@endforeach
                                @if ($r['outdated'])<br><small>Cambió en el archivo: vuelve a importarla</small>@endif
                            </td>
                            <td>
                                @if ($r['event_id'])<a class="wcal-btn" href="{{ \App\Filament\Resources\Events\EventResource::getUrl('view', ['record' => $r['event_id']]) }}">Ver evento</a>@endif
                                @if ($r['importable'])<button type="button" class="wcal-btn on" wire:click="openRow({{ $r['id'] }})">{{ $r['outdated'] ? 'Actualizar' : 'Importar' }}</button>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-filament-panels::page>
