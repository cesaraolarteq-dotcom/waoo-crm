@php
    $s = $this->state();
    $c = $s['contract'];
    $stateLabel = ['draft' => 'Borrador', 'issued' => 'Emitido · esperando firma', 'signed' => 'Firmado', 'superseded' => 'Reemplazado', 'void' => 'Anulado'];
@endphp
<x-filament-panels::page>
    @include('filament.pages.partials.honeybook-responsible', ['event' => $this->record])
    <div class="wmoney-states">
        <span>Contrato: <b>{{ $c ? ($stateLabel[$c->state] ?? $c->state) : 'Sin preparar' }}</b></span>
        <span>Plantilla: <b>{{ $s['template'] ? 'versión '.$s['template']->version : 'ninguna activa' }}</b></span>
        <span>Montos: <b>{{ $s['revision'] ? \App\Filament\Support\Labels::money($s['revision']->total_cents).' (revisión '.$s['revision']->revision.')' : 'sin fijar' }}</b></span>
    </div>

    @if ($s['blockers'] && (! $c || $c->state === 'superseded'))
        <div class="wmoney-warn" role="alert">Antes de preparar el contrato: {{ implode(' ', $s['blockers']) }}</div>
    @endif

    @if ($newLink)
        @include('filament.pages.partials.link-panel', ['panel' => [...$newLink, 'heading' => $newLink['recovered'] ?? false ? 'Enlace activo (el mismo de antes)' : 'Enlace creado']])
        <div class="wqb-next"><button type="button" class="wcal-btn" wire:click="hideLink">Listo, ocultar</button></div>
    @endif

    <div class="wfile">
        <x-filament::section>
            <x-slot name="heading">Documento</x-slot>
            @if ($c)
                <dl class="wfile-dl">
                    <div><dt>Número</dt><dd>WP-{{ $c->event_id }}-{{ $c->revision }}</dd></div>
                    <div><dt>Firma</dt><dd>{{ $c->commercial_snapshot['client']['first_name'] ?? '' }} {{ $c->commercial_snapshot['client']['last_name'] ?? '' }} · {{ $c->signer_email }}</dd></div>
                    @if ($c->issued_at)<div><dt>Emitido</dt><dd>{{ \App\Filament\Support\Labels::when($c->issued_at) }}</dd></div>@endif
                    @if ($c->presented_pdf_sha256)<div><dt>Huella del PDF</dt><dd><code>{{ substr($c->presented_pdf_sha256, 0, 16) }}…</code></dd></div>@endif
                </dl>
                <div class="wqb-next" style="margin-top:12px">
                    @if ($c->presented_pdf_path)<a class="wcal-btn" target="_blank" rel="noopener" href="{{ url('/admin/api/contracts/'.$c->id.'/pdf') }}">Ver PDF emitido</a>@endif
                    @if ($c->final_pdf_path)<a class="wcal-btn" target="_blank" rel="noopener" href="{{ url('/admin/api/contracts/'.$c->id.'/pdf?artifact=final') }}">Ver PDF firmado</a>@endif
                </div>
            @else
                <p class="wmoney-p">Todavía no hay contrato. Cuando estén los montos, la dirección y el correo, toca “Preparar contrato”.</p>
            @endif
        </x-filament::section>
        <x-filament::section>
            <x-slot name="heading">Firma del cliente</x-slot>
            @if ($s['signature'])
                <dl class="wfile-dl">
                    <div><dt>Firmó</dt><dd class="ok">{{ $s['signature']->signer_name }}</dd></div>
                    <div><dt>Cuándo</dt><dd>{{ \App\Filament\Support\Labels::when($s['signature']->accepted_at) }}</dd></div>
                    <div><dt>Cómo</dt><dd>Con su enlace personal; aceptó el PDF {{ substr($s['signature']->presented_pdf_sha256 ?? $c->presented_pdf_sha256, 0, 12) }}…</dd></div>
                </dl>
                @unless ($c->final_pdf_path)<p class="wmoney-p" style="margin-top:8px">El PDF firmado se está generando; la firma ya quedó guardada.</p>@endunless
            @else
                <p class="wmoney-p">Solo el cliente puede firmar, desde su enlace. Tú no puedes firmar por el cliente.</p>
            @endif
        </x-filament::section>
    </div>

    @if ($s['submission'] || $s['detailsState'])
        <x-filament::section>
            <x-slot name="heading">Datos del cliente</x-slot>
            <x-slot name="afterHeader">
                <x-filament::badge :color="match ($s['detailsState']) { 'review_details' => 'warning', 'waiting_details' => 'gray', default => 'success' }">
                    {{ ['waiting_details' => 'Esperando sus datos', 'review_details' => 'Por revisar', 'ready_to_issue' => 'Aprobados', 'waiting_signature' => 'Aprobados', 'signed' => 'Aprobados · firmado'][$s['detailsState']] ?? '' }}
                </x-filament::badge>
            </x-slot>
            @if ($s['submission'])
                @php
                    $d = $s['submission']->data;
                    $tz = 'America/Chicago';
                    $orig = \App\Filament\Support\Labels::when($s['e']->starts_at);
                    $req = ! ($d['start_time_confirmed'] ?? true) && ! empty($d['requested_start_at']) ? \App\Filament\Support\Labels::when(\Carbon\CarbonImmutable::parse($d['requested_start_at'])) : null;
                @endphp
                <dl class="wfile-dl">
                    <div><dt>Nombre</dt><dd>{{ $d['first_name'] ?? '' }} {{ $d['last_name'] ?? '' }}</dd></div>
                    <div><dt>Contacto</dt><dd>{{ $d['phone'] ?? '' }} · {{ $d['email'] ?? '' }}</dd></div>
                    <div><dt>Su dirección</dt><dd>{{ $d['billing_address'] ?? '' }}</dd></div>
                    <div><dt>Dirección de la fiesta</dt><dd>{{ $d['event_address'] ?? '' }}@if (! empty($d['event_postal_code'])) · ZIP {{ $d['event_postal_code'] }}@endif</dd></div>
                    <div><dt>Hora</dt><dd>@if ($req)<span class="wwarn-inline">Pidió otra hora: {{ $req }}</span> (actual: {{ $orig }})@else Confirmó {{ $orig }}@endif</dd></div>
                    @if (! empty($d['child_name']) || ! empty($d['child_age']))<div><dt>Cumpleañero/a</dt><dd>{{ $d['child_name'] ?? '' }}@if (! empty($d['child_age'])) · cumple {{ $d['child_age'] }}@endif</dd></div>@endif
                    @if (! empty($d['theme']))<div><dt>Tema</dt><dd>{{ $d['theme'] }}</dd></div>@endif
                    @if (! empty($d['event_language']))<div><dt>Idioma de la fiesta</dt><dd>{{ ['es' => 'Español', 'en' => 'Inglés', 'bilingual' => 'Bilingüe'][$d['event_language']] ?? $d['event_language'] }}</dd></div>@endif
                    @if (! empty($d['setup_area']))<div><dt>Dónde</dt><dd>{{ ['indoor' => 'Interior', 'outdoor' => 'Exterior'][$d['setup_area']] ?? $d['setup_area'] }}</dd></div>@endif
                    @if (! empty($d['children_count']))<div><dt>Niños aprox.</dt><dd>{{ $d['children_count'] }}@if ((int) $d['children_count'] > 25) · sugiere recreadores extra @endif</dd></div>@endif
                    @if (! empty($d['notes']))<div><dt>Notas</dt><dd>{{ $d['notes'] }}</dd></div>@endif
                    <div><dt>Envío</dt><dd>versión {{ $s['submission']->revision }} · {{ \App\Filament\Support\Labels::when(\Carbon\CarbonImmutable::parse($s['submission']->submitted_at)) }}</dd></div>
                </dl>
                @if ($s['detailsState'] === 'review_details')<p class="wmoney-p" style="margin-top:8px">Revisa la dirección y el ZIP contra el costo de transporte que cotizaste y toca “Aprobar datos del cliente”.</p>@endif
            @else
                <p class="wmoney-p">Todavía no los envía. Con su enlace llena nombre, contacto, dirección y confirma la hora.</p>
            @endif
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Enlaces creados</x-slot>
        @forelse ($s['grants'] as $g)
            <div class="wmoney-row"><div><b>{{ $g->state }}</b> · vence {{ $g->expires }}@if ($g->details) · incluye sus datos @endif @if ($g->active)@if ($g->can_reveal) · {{ ($this->copyLinkAction)(['id' => $g->id]) }}@else · <small>enlace anterior: no se puede volver a copiar</small>@endif · {{ ($this->revokeAction)(['id' => $g->id]) }}@endif</div><small>{{ $g->opened ? 'El cliente ya abrió el contrato' : 'Todavía no abre el contrato' }}</small></div>
        @empty
            <p class="wmoney-p">Ninguno todavía. En una reserva rápida se crea al guardarla; si no, después de emitir el contrato.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
