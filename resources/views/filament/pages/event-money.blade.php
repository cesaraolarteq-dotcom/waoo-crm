@php
    $f = $this->summary();
    $rev = $f['financial_revision'];
    $money = fn ($c) => \App\Filament\Support\Labels::money($c);
    $paid = $f['net_received_cents'];
@endphp
<x-filament-panels::page>
    @include('filament.pages.partials.honeybook-responsible', ['event' => $this->record])
    <div class="wmoney">
        <div class="wmoney-states" aria-label="Estados por separado">
            <span>Pago: <b>{{ $paid > 0 ? ($rev && $f['balance_cents'] === 0 ? 'Pagado completo' : 'Pago recibido') : 'Sin pagos' }}</b></span>
            <span>Recursos: <b>{{ \App\Filament\Support\Labels::label(\App\Filament\Support\Labels::EVENT_STATUS, $f['capacity_status']) }}</b></span>
            <span>Contrato: <b>{{ $f['contract_status'] === 'not_issued' ? 'Sin emitir' : $f['contract_status'] }}</b></span>
        </div>

        @if (! $rev)
            <x-filament::section>
                <x-slot name="heading">Primero fija los montos acordados</x-slot>
                <p class="wmoney-p">Los montos salen del precio guardado de cada servicio y del costo de transporte. Si el transporte está pendiente, complétalo en el evento: nunca se toma como $0.</p>
                <p class="wmoney-p">Precio de servicios hoy: {{ $money($this->record->services()->join('price_versions', 'price_versions.id', '=', 'event_services.price_version_id')->sum('price_versions.amount_cents')) }} · Transporte: {{ $money($this->record->travel_fee_cents) }}</p>
            </x-filament::section>
        @else
            @if ($f['capacity_status'] === 'draft' && $rev->event_version !== $this->record->fresh()->version)
                <div class="wmoney-warn" role="alert">El borrador cambió después de fijar estos montos. Revisa los datos del evento y usa “Fijar montos acordados” para crear una revisión nueva. Los montos anteriores y los pagos recibidos se conservan.</div>
            @endif
            <div class="wmoney-grid">
                <div><span>Total acordado</span><b>{{ $money($rev->total_cents) }}</b><small>Servicios {{ $money($rev->subtotal_cents) }} + transporte {{ $money($rev->travel_fee_cents) }}@if (($rev->discount_cents ?? 0) > 0) − descuento {{ $money($rev->discount_cents) }}@endif</small></div>
                <div><span>Depósito (50 %)</span><b>{{ $money($rev->deposit_target_cents) }}</b>
                    <small>@if ($f['deposit_shortfall_cents'] > 0) Faltan {{ $money($f['deposit_shortfall_cents']) }} para el depósito @else Depósito cubierto ✓ @endif</small></div>
                <div><span>Recibido</span><b class="ok">{{ $money($paid) }}</b><small>{{ $f['refunded_cents'] ? 'Devuelto '.$money($f['refunded_cents']) : 'Solo pagos verificados' }}</small></div>
                <div><span>Saldo pendiente</span><b>{{ $money($f['balance_cents']) }}</b>
                    <small>Vence {{ \App\Filament\Support\Labels::when($rev->balance_due_at) }} · en el contrato: “{{ $f['balance_label'] }}”</small></div>
            </div>
            @if ($f['credit_cents'] > 0)
                <div class="wmoney-warn" role="alert">Pagó {{ $money($f['credit_cents']) }} de más. Decide con el cliente si se aplica a otro servicio o se le devuelve; el sistema no devuelve nada solo.</div>
            @endif
        @endif

        @php($notices = $this->notices())
        @if ($notices->isNotEmpty())
            <x-filament::section>
                <x-slot name="heading">Avisos de Zelle del cliente</x-slot>
                <x-slot name="description">Un aviso no es un pago. Revisa tu banco y verifica lo que de verdad llegó, o recházalo.</x-slot>
                @foreach ($notices as $n)
                    <div class="wmoney-row">
                        <div><b>Dice {{ $n->amount }}</b> ·
                            @if ($n->state === 'pending') <b class="wmoney-pending">Por verificar</b> · {{ ($this->verifyNoticeAction)(['id' => $n->id]) }} {{ ($this->rejectNoticeAction)(['id' => $n->id]) }}
                            @elseif ($n->state === 'verified') <b class="ok">Verificado</b> · llegó {{ $n->real }}
                            @else <b class="wmoney-rejected">Rechazado</b> @endif
                        </div>
                        <small>Avisó {{ $n->when }}@if ($n->sent) · dice que lo envió {{ $n->sent }}@endif @if ($n->reference) · su ref. {{ $n->reference }}@endif @if ($n->payer) · a nombre de {{ $n->payer }}@endif @if ($n->note) · “{{ $n->note }}”@endif
                            @if ($n->old_amounts) · lo hizo sobre montos anteriores @endif
                            @if ($n->reviewed) <br>Revisado {{ $n->reviewed }} · {{ $n->review_note }}@endif</small>
                    </div>
                @endforeach
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Pagos registrados</x-slot>
            @forelse ($this->payments() as $p)
                <div class="wmoney-row">
                    <div><b>{{ $p->amount }}</b> · {{ $p->method }} · {{ $p->kind }}</div>
                    <small>{{ $p->when }} · ref. {{ $p->reference }} · verificó {{ $p->by }}@if ($p->note) · {{ $p->note }}@endif</small>
                </div>
            @empty
                <p class="wmoney-p">Todavía no hay pagos. Usa “Registrar Zelle verificado” cuando lo veas en el banco.</p>
            @endforelse
        </x-filament::section>

        @if ($t = $this->tips())
            <x-filament::section>
                <x-slot name="heading">Propinas para el staff</x-slot>
                <x-slot name="description">Con tarjeta, aparte del pago de la fiesta: no cambian el saldo ni los ingresos. Sin comisiones a la vista: las absorbe Waoo.</x-slot>
                @if ($tipLink)
                    @include('filament.pages.partials.link-panel', ['panel' => $tipLink])
                @endif
                <dl class="wfile-dl">
                    <div><dt>Propinas recibidas</dt><dd>{{ \App\Filament\Support\Labels::money($t['gross_cents']) }} · {{ count($t['receipts']) }} {{ count($t['receipts']) === 1 ? 'pago' : 'pagos' }}</dd></div>
                    @if ($t['refunded_cents'])<div><dt>Devueltas</dt><dd>{{ \App\Filament\Support\Labels::money($t['refunded_cents']) }}</dd></div>@endif
                    <div><dt>Para repartir al staff</dt><dd>@if ($t['net_staff_cents'] === null)<b>Pendiente</b> <small>· hay un cobro en curso o por revisar</small>@else<b>{{ \App\Filament\Support\Labels::money($t['net_staff_cents']) }}</b>@endif</dd></div>
                </dl>
                <div class="wrep-actions" style="margin-top:8px">
                    @if ($t['grant'])
                        <span>Enlace activo · vence el {{ $t['grant']['expires'] }}</span>
                        {{ ($this->tipRevokeAction)(['grant' => $t['grant']['id'], 'version' => $t['grant']['version']]) }}
                    @elseif ($t['can_issue'])
                        {{ $this->tipIssueAction }}
                    @else
                        <small>Se puede crear el enlace cuando la fiesta haya terminado y la cobre el CRM.</small>
                    @endif
                </div>
                <p class="wqb-hint">El reparto entre el staff se decide aparte (pagos al staff).</p>
            </x-filament::section>
        @endif

        @if ($rev && $paid > 0 && $f['capacity_status'] === 'draft' && \App\Filament\Resources\Events\Pages\ConfirmCapacity::canAccessFor($this->record))
            <a class="wmoney-next" href="{{ \App\Filament\Resources\Events\EventResource::getUrl('confirm', ['record' => $this->record]) }}">Siguiente paso: apartar staff y trajes con este pago →</a>
        @endif
    </div>
</x-filament-panels::page>
