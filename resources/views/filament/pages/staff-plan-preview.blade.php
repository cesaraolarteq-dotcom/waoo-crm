@php
    $t = $this->team();
    $state = ['accepted' => ['Aceptó', 'success'], 'pending' => ['Sin responder', 'warning'], 'declined' => ['No puede', 'danger']];
    $payState = ['review' => ['Por aprobar', 'warning'], 'approved' => ['Aprobado', 'success'], 'missing' => ['Falta la tarifa', 'gray']];
@endphp
<x-filament-panels::page>
    <p class="wmoney-warn" role="note">Vista previa de diseño. Datos y montos de ejemplo; nada se guarda ni se envía.</p>

    <x-filament::section>
        <x-slot name="heading">Equipo de la fiesta</x-slot>
        <x-slot name="description">{{ $t['event'] }} · En la ficha de cada evento. Solo lo aceptado cuenta como confirmado.</x-slot>
        <x-slot name="afterHeader"><button type="button" class="wcal-btn on" disabled>Invitar al equipo</button></x-slot>
        @foreach ($t['roles'] as $r)
            <div class="wmoney-row">
                <div><b>{{ $r['role'] }}</b> · {{ $r['person'] }} <x-filament::badge :color="$state[$r['state']][1]" size="sm">{{ $state[$r['state']][0] }}</x-filament::badge>@if ($r['driver']) <x-filament::badge color="info" size="sm">Maneja</x-filament::badge>@endif</div>
                <small>{{ $r['note'] }}</small>
                <div class="wrep-actions" style="margin-top:6px">
                    @if ($r['state'] === 'pending')<button type="button" class="wcal-btn" disabled>Recordar por WhatsApp</button>@endif
                    @if ($r['state'] === 'declined')<button type="button" class="wcal-btn on" disabled>Buscar reemplazo</button>@endif
                    <button type="button" class="wcal-btn" disabled>Copiar su enlace</button>
                </div>
            </div>
        @endforeach
        <p class="wqb-hint">Si nadie del equipo maneja, la fiesta no se puede confirmar (regla: al menos una persona que maneje, sin sumar gente).</p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Inicio · Staff sin responder ({{ count($this->waiting()) }})</x-slot>
        <x-slot name="description">Tarjeta nueva en “Necesita tu atención”.</x-slot>
        @foreach ($this->waiting() as $w)<div class="wmoney-row"><div>{{ $w['label'] }}</div></div>@endforeach
    </x-filament::section>

    @php($p = $this->pay())
    <x-filament::section>
        <x-slot name="heading">Pagos al staff · {{ $p['period'] }}</x-slot>
        <x-slot name="description">Calcula y aprueba; no es nómina ni impuestos. Cada persona ve solo lo suyo en su portal.</x-slot>
        <x-slot name="afterHeader"><button type="button" class="wcal-btn" disabled>Exportar CSV</button></x-slot>
        <div class="wimp-table-wrap">
            <table class="wimp-table">
                <thead><tr><th>Persona</th><th>Fiestas</th><th>Horas</th><th>Pago previsto</th><th>Extras</th><th>Propinas</th><th>Reembolsos</th><th>Total</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                @foreach ($p['rows'] as $r)
                    <tr><td><b>{{ $r['person'] }}</b></td><td>{{ $r['jobs'] }}</td><td>{{ $r['hours'] }}</td><td>{{ $r['planned'] }}</td><td>{{ $r['extras'] }}</td><td>{{ $r['tips'] }}</td><td>{{ $r['expenses'] }}</td><td><b>{{ $r['total'] }}</b></td>
                        <td><x-filament::badge :color="$payState[$r['state']][1]" size="sm">{{ $payState[$r['state']][0] }}</x-filament::badge></td>
                        <td>@if ($r['state'] === 'review')<button type="button" class="wcal-btn on" disabled>Aprobar</button>@endif</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="wqb-hint">Faltan tus reglas para calcularlo de verdad: tarifa del personaje con peluca, tiempo de preparación, horas mínimas, transporte del staff, propinas y cada cuánto pagas.</p>
    </x-filament::section>
</x-filament-panels::page>
