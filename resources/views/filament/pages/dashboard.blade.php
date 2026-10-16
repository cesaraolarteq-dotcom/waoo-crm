<x-filament-panels::page>
    <x-filament::section>
        <div class="waoo-welcome">
            <h2>Hola, <em>{{ \Illuminate\Support\Str::of(auth()->user()->name)->before(' ') }}</em></h2>
            <p>Este es el CRM de Waoo Party. Lo vamos construyendo por partes; esto es lo que viene.</p>
            <div class="waoo-next">
                <div><b>Reserva rápida</b><span>Tú pones paquete, fecha y depósito; la clienta llena sus datos por enlace.</span></div>
                <div><b>Calendario</b><span>Eventos, traslados y el margen de 45 minutos entre fiestas.</span></div>
                <div><b>Clientas</b><span>Fichas con eventos, contratos y pagos.</span></div>
                <div><b>Staff y trajes</b><span>Quién va a cada fiesta y con qué traje.</span></div>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
