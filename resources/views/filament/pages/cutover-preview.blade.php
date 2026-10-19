@php($s = $this->state())
@php($m = fn ($v) => '$'.number_format($v))
<x-filament-panels::page>
    <p class="wmoney-warn" role="note">Vista previa de diseño (cambio final). Números de ejemplo; esta pantalla no cambia nada.</p>

    <x-filament::section>
        <x-slot name="heading">Hoy: HoneyBook cobra y avisa estas fiestas</x-slot>
        <x-slot name="description">Fiestas importadas que todavía no han pasado. El CRM las muestra y aparta al staff, pero no cobra ni manda nada.</x-slot>
        <div class="wmoney-grid wrep-grid">
            <div><span>Fiestas</span><b>{{ $s['counts']['events'] }}</b><small>{{ $s['counts']['contracts'] }} con contrato de HoneyBook</small></div>
            <div><span>Total acordado</span><b>{{ $m($s['totals']['total']) }}</b><small>Montos originales, no precios de hoy</small></div>
            <div><span>Pagado en HoneyBook</span><b>{{ $m($s['totals']['paid']) }}</b><small>Según HoneyBook (no verificado en tu banco por el CRM)</small></div>
            <div><span>Saldo por cobrar</span><b>{{ $m($s['totals']['balance']) }}</b><small>{{ $s['counts']['with_balance'] }} fiestas con saldo @if ($s['unknowns']) · {{ $s['unknowns'] }} sin monto claro @endif</small></div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Paso 1 · Revisar las cuentas con HoneyBook</x-slot>
        <x-slot name="description">Subes el archivo nuevo de HoneyBook y comparas cada fiesta: total, pagado, saldo, fecha y contrato.</x-slot>
        <p>Última revisión: <b>{{ $s['reconciliation']['when'] }}</b> · vale hasta <b>{{ $s['reconciliation']['expires'] }}</b></p>
        @if ($s['missing'])
            <p class="wmoney-warn" role="alert"><b>Todavía no se puede pasar:</b></p>
            <ul class="wchg-effects">@foreach ($s['missing'] as $x)<li>{{ $x }}</li>@endforeach</ul>
        @endif
        <div class="wrep-actions"><button type="button" class="wcal-btn" disabled>Revisar cuentas de nuevo</button></div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Paso 2 · Apagar los avisos de HoneyBook</x-slot>
        <x-slot name="description">Hazlo tú en HoneyBook (recordatorios de pago y correos automáticos de esas fiestas). El CRM no puede apagarlos por ti.</x-slot>
        <label class="wimp-check"><input type="checkbox" wire:model.live="attest"> Ya apagué los avisos automáticos de HoneyBook para estas fiestas</label>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Paso 3 · Pasar todas al CRM</x-slot>
        <x-slot name="description">Se pasan todas juntas o ninguna. Desde ese momento el CRM cobra los saldos y manda los recordatorios que faltan; nada atrasado.</x-slot>
        <dl class="wfile-dl">
            <div><dt>Recordatorios de saldo</dt><dd>{{ $s['reminders']['balance'] }}</dd></div>
            <div><dt>“Falta una semana”</dt><dd>{{ $s['reminders']['week_before'] }}</dd></div>
            <div><dt>Gracias + reseña</dt><dd>{{ $s['reminders']['thank_you'] }}</dd></div>
        </dl>
        <p class="wqb-hint">Se programan, pero los correos siguen apagados hasta que actives el envío por separado.</p>
        <div class="wrep-actions"><button type="button" class="wcal-btn on" disabled>Pasar {{ $s['counts']['events'] }} fiestas al CRM</button></div>
        <p class="wqb-hint">¿Te equivocaste? Se puede deshacer solo si el CRM todavía no mandó ni cobró nada de esas fiestas.</p>
    </x-filament::section>
</x-filament-panels::page>
