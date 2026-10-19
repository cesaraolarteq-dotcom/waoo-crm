@php
    $f = $this->file();
    $e = $f['event'];
    $money = fn ($c) => \App\Filament\Support\Labels::money($c);
@endphp
<x-filament-panels::page>
    <div class="wmoney-states">
        <span>Recursos: <b>{{ $f['status'] }}</b></span>
        @if ($f['finance'])
            <span>Pago: <b>{{ $f['finance']['net_received_cents'] > 0 ? ($f['finance']['financial_revision'] && $f['finance']['balance_cents'] === 0 ? 'Pagado completo' : 'Pago recibido') : 'Sin pagos' }}</b></span>
        @endif
        <span>Contrato: <b>{{ $f['contract'] }}</b></span>
        <span>Tipo: <b>{{ $f['type'] }}</b></span>
        @if ($f['responsible'])<span title="Solo un sistema cobra y manda avisos por fiesta">Cobro y avisos: <b>{{ $f['responsible'] }}</b></span>@endif
    </div>
    <div class="wfile">
        <x-filament::section>
            <x-slot name="heading">Lugar y logística</x-slot>
            <dl class="wfile-dl">
                <div><dt>Dirección</dt><dd>{{ $e->address ?: 'Pendiente (la clienta la completa)' }}</dd></div>
                <div><dt>ZIP</dt><dd>{{ $e->postal_code ?: '—' }}</dd></div>
                @php
                    $tfe = is_array($e->travel_fee_evidence ?? null) ? $e->travel_fee_evidence : null;
                @endphp
                <div><dt>Costo de transporte</dt><dd>{{ $money($e->travel_fee_cents) }}@if ($tfe)
                    {{-- Imported HoneyBook evidence has no accepted_at/mode: show where it came from instead of failing. --}}
                    <small>· @if (isset($tfe['mode'])){{ ['calculated' => 'calculado', 'edited' => 'editado', 'manual' => 'manual'][$tfe['mode']] ?? '' }}@else importado de HoneyBook @endif @if (! empty($tfe['accepted_at']))el {{ \App\Filament\Support\Labels::when(\Carbon\CarbonImmutable::parse($tfe['accepted_at'])) }}@endif @if (! empty($tfe['note'])) · “{{ $tfe['note'] }}”@endif</small>@endif</dd></div>
                <div><dt>Montaje / desmontaje</dt><dd>{{ $e->setup_minutes !== null ? $e->setup_minutes.' min antes' : '—' }} · {{ $e->breakdown_minutes !== null ? $e->breakdown_minutes.' min después' : '—' }}</dd></div>
            </dl>
        </x-filament::section>
        <x-filament::section>
            <x-slot name="heading">Servicios</x-slot>
            @forelse ($f['services'] as $s)
                <div class="wmoney-row"><div><b>{{ $s->name }}</b> · {{ $s->duration }} · {{ $s->price }}@unless ($s->ready) <x-filament::badge color="warning" size="sm">Incompleto</x-filament::badge>@endunless</div>
                    @if ($s->characters)<small>Personajes: {{ $s->characters }}</small>@endif</div>
            @empty
                <p class="wmoney-p">Sin servicios todavía.</p>
            @endforelse
        </x-filament::section>
        <x-filament::section>
            <x-slot name="heading">Staff, trajes y equipo</x-slot>
            @forelse ($f['units'] as $u)
                <div class="wmoney-row"><div><b>{{ $u->name }}</b> · {{ $u->kind }}</div></div>
            @empty
                <p class="wmoney-p">Nada apartado todavía. Un borrador no aparta staff ni trajes.</p>
            @endforelse
        </x-filament::section>
        @if ($f['finance'] && $f['finance']['financial_revision'])
            <x-filament::section>
                <x-slot name="heading">Dinero</x-slot>
                <dl class="wfile-dl">
                    <div><dt>Total</dt><dd>{{ $money($f['finance']['financial_revision']->total_cents) }}</dd></div>
                    <div><dt>Recibido</dt><dd class="ok">{{ $money($f['finance']['net_received_cents']) }}</dd></div>
                    <div><dt>Saldo</dt><dd>{{ $money($f['finance']['balance_cents']) }} · vence {{ \App\Filament\Support\Labels::when($f['finance']['financial_revision']->balance_due_at) }}</dd></div>
                </dl>
            </x-filament::section>
        @endif
    </div>
    @if ($mail = $this->mail())
        <x-filament::section>
            <x-slot name="heading">Correos a la clienta</x-slot>
            <x-slot name="description">Programado no es enviado, y enviado no confirma que llegó. Nada sale sin que César active los correos.</x-slot>
            <x-slot name="afterHeader">
                <button type="button" class="wcal-btn {{ $mail['policy']['paused'] ? 'on' : '' }}" wire:click="toggleMailPause">{{ $mail['policy']['paused'] ? 'Reanudar correos' : 'Pausar correos' }}</button>
            </x-slot>
            @if ($mail['policy']['paused'])<p class="wmoney-warn">En pausa: lo programado no sale hasta que lo reanudes.</p>@endif
            @forelse ($mail['rows'] as $m)
                <div class="wmoney-row"><div><b>{{ $m->template }}</b> · {{ $m->lang }} · {{ $m->when }} <x-filament::badge :color="$m->color" size="sm">{{ $m->status }}</x-filament::badge></div>
                    @if ($m->error)<small>{{ $m->error }}</small>@endif</div>
            @empty
                <p class="wmoney-p">Ningún correo programado para este evento.</p>
            @endforelse
        </x-filament::section>
    @endif
    <x-filament::section>
        <x-slot name="heading">Historial</x-slot>
        <x-slot name="description">Quién hizo cada cambio. No se puede editar.</x-slot>
        @forelse ($f['history'] as $h)
            <div class="wmoney-row"><div><b>{{ $h->what }}</b> · {{ $h->who }}</div><small>{{ $h->when }}</small></div>
        @empty
            <p class="wmoney-p">Sin cambios registrados.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
