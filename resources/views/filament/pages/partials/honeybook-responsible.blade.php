{{-- RESPONSIBLE-SYSTEM C1: one system charges and messages per party. Shown only when Codex's column says honeybook. --}}
@if (($event?->getAttribute('responsible_system')) === 'honeybook')
    <p class="wmoney-warn" role="note"><b>HoneyBook cobra y avisa esta fiesta.</b> Aquí la ves con toda su información y su staff queda apartado, pero el CRM no cobra, no crea enlaces de pago ni manda correos hasta el cambio final.</p>
@endif
