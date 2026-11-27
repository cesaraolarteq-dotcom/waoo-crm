{{-- Owner 2026-11-25: one clear panel right where the row was clicked (it used to open at the very bottom of the page). --}}
@php($P = \App\Filament\Pages\ImportHistory::class)
@php($d = $this->row())
<div class="wimp-panel" x-data x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'center' })">
@if ($inline ?? false)<div class="wimp-actions" style="justify-content:space-between"><h3 class="wfile-h" style="margin:0">Fila seleccionada</h3><button type="button" class="wcal-btn" wire:click="closeRow">Cerrar</button></div>@endif
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
                        <div class="wimp-fields">
                        <label>Clienta <select wire:model="reviewCustomerId"><option value="">Crear con los datos de origen</option>
                            @foreach($row['customer_match']['candidates'] as $candidate)<option value="{{ $candidate['id'] }}">{{ $candidate['name'] }} · {{ $candidate['email'] }} · {{ $candidate['phone'] }}</option>@endforeach
                        </select></label>
                        <label>Estado del historial <select wire:model="reviewStatus"><option value="archived_unknown">Archivado, estado sin confirmar</option><option value="completed">Completado</option><option value="cancelled">Cancelado</option></select></label>
                        <label>Tipo <select wire:model="reviewType">@foreach(['unknown'=>'Sin confirmar','birthday'=>'Cumpleaños','baby_shower'=>'Baby shower','gender_reveal'=>'Revelación','corporate'=>'Corporativo','other'=>'Otro'] as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                        <label class="wimp-wide">Motivo (obligatorio para excluir, mínimo 5 letras) <textarea wire:model="reviewReason" maxlength="1000" rows="2" placeholder="Ej.: pago de prueba, no es una fiesta"></textarea></label>
                        </div>
                        <div class="wimp-actions">
                            @if (! $P::blocking($row['issue_codes']))
                                <button type="button" class="wcal-btn on" wire:click="decideRow(false)" wire:loading.attr="disabled">Guardar esta fila</button>
                            @else
                                <p class="wqb-hint">Esta fila no se puede guardar (falta la fecha o la hora). Si no es una fiesta, escribe el motivo y exclúyela.</p>
                            @endif
                            <button type="button" class="wcal-btn wimp-danger" wire:click="decideRow(true)" wire:loading.attr="disabled">Excluir con motivo</button>
                        </div>
                    @endif
                    @if ($all = $P::issues($row['issue_codes'], true))
                        <ul class="wimp-issues">@foreach ($all as $t)<li>{{ $t }}</li>@endforeach</ul>
                    @endif
                @endif
</div>
