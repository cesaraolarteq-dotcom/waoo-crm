@php
    $t = $this->team();
    $payState = ['review' => ['Por aprobar', 'warning'], 'approved' => ['Aprobado', 'success'], 'missing' => ['Falta la tarifa', 'gray']];
@endphp
<x-filament-panels::page>
    <p class="wmoney-warn" role="note">Vista previa de diseño. Datos y montos de ejemplo; nada se guarda ni se envía.</p>

    <x-filament::section>
        <x-slot name="heading">Equipo de la fiesta</x-slot>
        <x-slot name="description">{{ $t['event'] }} · En la ficha de cada evento. Tú le preguntas a cada persona por WhatsApp; aquí solo queda quién va.</x-slot>
        @foreach ($t['roles'] as $r)
            <div class="wmoney-row">
                <div><b>{{ $r['role'] }}</b> · {{ $r['person'] }}@if ($r['driver']) <x-filament::badge color="info" size="sm">Maneja</x-filament::badge>@endif</div>
            </div>
        @endforeach
        <p class="wqb-hint">Cuando confirmas la fiesta, aparece sola en la página de cada persona del equipo. Si nadie maneja, la fiesta no se puede confirmar (regla: al menos una persona que maneje).</p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Enlaces del staff</x-slot>
        <x-slot name="description">Cada persona tiene un solo enlace que no cambia: se lo mandas una vez y siempre muestra sus fiestas de la semana. Si alguien lo pierde o deja de trabajar contigo, lo cambias o lo apagas.</x-slot>
        @foreach ($this->links() as $l)
            <div class="wmoney-row">
                <div><b>{{ $l['person'] }}</b> · {{ $l['week'] }}</div>
                <small>{{ $l['opened'] }}</small>
                <div class="wrep-actions" style="margin-top:6px">
                    <button type="button" class="wcal-btn on" disabled>Enviar por WhatsApp</button>
                    <button type="button" class="wcal-btn" disabled>Copiar enlace</button>
                    <button type="button" class="wcal-btn" disabled>Cambiar enlace</button>
                    <button type="button" class="wcal-btn" disabled>Apagar</button>
                </div>
            </div>
        @endforeach
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
