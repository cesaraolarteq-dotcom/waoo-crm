@php
    $s = $this->state();
    $c = $s['contract'];
    $stateLabel = ['draft' => 'Borrador', 'issued' => 'Emitido · esperando firma', 'signed' => 'Firmado', 'superseded' => 'Reemplazado', 'void' => 'Anulado'];
@endphp
<x-filament-panels::page>
    <div class="wmoney-states">
        <span>Contrato: <b>{{ $c ? ($stateLabel[$c->state] ?? $c->state) : 'Sin preparar' }}</b></span>
        <span>Plantilla: <b>{{ $s['template'] ? 'versión '.$s['template']->version : 'ninguna activa' }}</b></span>
        <span>Montos: <b>{{ $s['revision'] ? \App\Filament\Support\Labels::money($s['revision']->total_cents).' (revisión '.$s['revision']->revision.')' : 'sin fijar' }}</b></span>
    </div>

    @if ($s['blockers'] && (! $c || $c->state === 'superseded'))
        <div class="wmoney-warn" role="alert">Antes de preparar el contrato: {{ implode(' ', $s['blockers']) }}</div>
    @endif

    @if ($newLink)
        <div class="wqb-done" x-data="{ lang: @js($newLink['locale']), copied: '', sent: false }" x-init="window.addEventListener('beforeunload', e => { if (!sent) { e.preventDefault(); e.returnValue = ''; } })">
            <h2>Enlace creado <span>Vence el {{ rtrim($newLink['expires'], '.') }}. Cópialo ahora: <b>solo se muestra esta vez</b>.</span></h2>
            <div class="wqb-link"><code>{{ $newLink['url'] }}</code>
                <button type="button" class="wcal-btn" x-on:click="navigator.clipboard.writeText(@js($newLink['url'])).then(() => { copied = 'link'; sent = true })" x-text="copied === 'link' ? 'Copiado' : 'Copiar enlace'"></button></div>
            <div class="wqb-msg">
                <div class="wcal-modes"><button type="button" class="wcal-btn" :class="lang==='es' && 'on'" x-on:click="lang='es'">Español</button><button type="button" class="wcal-btn" :class="lang==='en' && 'on'" x-on:click="lang='en'">English</button></div>
                <p x-show="lang==='es'">{{ $newLink['msg_es'] }}</p>
                <p x-show="lang==='en'" x-cloak>{{ $newLink['msg_en'] }}</p>
                <button type="button" class="wcal-btn on" x-on:click="navigator.clipboard.writeText(lang==='es' ? @js($newLink['msg_es']) : @js($newLink['msg_en'])).then(() => { copied = 'msg'; sent = true })" x-text="copied === 'msg' ? 'Mensaje copiado' : 'Copiar mensaje para WhatsApp'"></button>
                    @php
                        $waEs = \App\Filament\Support\ContactLinks::whatsapp($newLink['phone'] ?? null, $newLink['msg_es']);
                        $waEn = \App\Filament\Support\ContactLinks::whatsapp($newLink['phone'] ?? null, $newLink['msg_en']);
                        $smsEs = \App\Filament\Support\ContactLinks::sms($newLink['phone'] ?? null, $newLink['msg_es']);
                        $smsEn = \App\Filament\Support\ContactLinks::sms($newLink['phone'] ?? null, $newLink['msg_en']);
                    @endphp
                    @if ($waEs)
                        <div class="wqb-send">
                            <a class="wcal-btn on" target="_blank" rel="noopener" :href="lang==='es' ? @js($waEs) : @js($waEn)" x-on:click="sent = true">Abrir WhatsApp con el mensaje</a>
                            <a class="wcal-btn" :href="lang==='es' ? @js($smsEs) : @js($smsEn)" x-on:click="sent = true">Enviar por SMS</a>
                        </div>
                        <small>Se abre en tu WhatsApp o en Mensajes con el texto listo: solo toca enviar.</small>
                    @else
                        <small>No tiene teléfono guardado: copia el mensaje y pégalo en WhatsApp o en un SMS.</small>
                    @endif
                <small>Nada se envía solo.</small>
            </div>
            <div class="wqb-next"><button type="button" class="wcal-btn" wire:click="hideLink">Ya lo copié</button></div>
        </div>
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
            <x-slot name="heading">Firma de la clienta</x-slot>
            @if ($s['signature'])
                <dl class="wfile-dl">
                    <div><dt>Firmó</dt><dd class="ok">{{ $s['signature']->signer_name }}</dd></div>
                    <div><dt>Cuándo</dt><dd>{{ \App\Filament\Support\Labels::when($s['signature']->accepted_at) }}</dd></div>
                    <div><dt>Cómo</dt><dd>Con su enlace personal; aceptó el PDF {{ substr($s['signature']->presented_pdf_sha256 ?? $c->presented_pdf_sha256, 0, 12) }}…</dd></div>
                </dl>
                @unless ($c->final_pdf_path)<p class="wmoney-p" style="margin-top:8px">El PDF firmado se está generando; la firma ya quedó guardada.</p>@endunless
            @else
                <p class="wmoney-p">Solo la clienta puede firmar, desde su enlace. Tú no puedes firmar por ella.</p>
            @endif
        </x-filament::section>
    </div>

    @if ($s['submission'] || $s['detailsState'])
        <x-filament::section>
            <x-slot name="heading">Datos de la clienta</x-slot>
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
                    @if (! empty($d['notes']))<div><dt>Notas</dt><dd>{{ $d['notes'] }}</dd></div>@endif
                    <div><dt>Envío</dt><dd>versión {{ $s['submission']->revision }} · {{ \App\Filament\Support\Labels::when(\Carbon\CarbonImmutable::parse($s['submission']->submitted_at)) }}</dd></div>
                </dl>
                @if ($s['detailsState'] === 'review_details')<p class="wmoney-p" style="margin-top:8px">Revisa la dirección y el ZIP contra el costo de transporte que cotizaste y toca “Aprobar datos de la clienta”.</p>@endif
            @else
                <p class="wmoney-p">Todavía no los envía. Con su enlace llena nombre, contacto, dirección y confirma la hora.</p>
            @endif
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Enlaces creados</x-slot>
        @forelse ($s['grants'] as $g)
            <div class="wmoney-row"><div><b>{{ $g->state }}</b> · vence {{ $g->expires }}@if ($g->details) · incluye sus datos @endif @if ($g->active) · {{ ($this->revokeAction)(['id' => $g->id]) }}@endif</div><small>{{ $g->opened ? 'La clienta ya abrió el contrato' : 'Todavía no abre el contrato' }}</small></div>
        @empty
            <p class="wmoney-p">Ninguno todavía. En una reserva rápida se crea al guardarla; si no, después de emitir el contrato.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
