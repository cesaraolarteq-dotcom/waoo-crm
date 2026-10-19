<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

// LOCAL/TESTING ONLY design preview of CHANGE-A (docs/plans/PHASE-7.md): reschedule check side by side, money
// difference with the $100 change fee (waivable with a reason), contract 2.5 counter, and the cancel outcome from the
// contract rules. Fictitious data; buttons do nothing. The real screens wait for Codex's CHANGE-A API.
class ChangePreview extends Page
{
    protected static ?string $slug = 'vista-previa-cambios';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $title = 'Cambiar una fiesta (vista previa)';
    protected string $view = 'filament.pages.change-preview';

    public string $mode = 'reschedule';
    public bool $waive = false;

    public static function canAccess(): bool
    {
        return parent::canAccess() && app()->environment(['local', 'testing']);
    }

    public function getSubheading(): ?string
    {
        return 'Diseño para revisar con datos de ejemplo. Nada se guarda ni se envía.';
    }

    public function setMode(string $mode): void
    {
        $this->mode = in_array($mode, ['reschedule', 'cancel'], true) ? $mode : 'reschedule';
    }

    public function reschedule(): array
    {
        $fee = $this->waive ? 0 : 100;

        return [
            'now' => ['when' => 'sáb. 7 nov. · 2:00 – 4:00 p. m.', 'place' => 'Katy 77494', 'team' => 'Lucía (maneja), Mateo · Rumi (Gala)', 'travel' => 40],
            'new' => ['when' => 'sáb. 14 nov. · 3:00 – 5:00 p. m.', 'place' => 'Katy 77494', 'team' => 'Lucía (maneja) · Rumi (Gala)', 'travel' => 40],
            'checks' => [['ok', 'Personaje Rumi (Gala) libre ese día'], ['ok', 'Al menos una persona que maneja: Lucía'], ['warn', 'Mateo no está libre: elige a otra persona para animación'], ['ok', 'Traslado: llega con 45 min de margen']],
            'money' => [['Paquete', 600, 600], ['Costo de transporte', 40, 40], ['Cargo por cambio de fecha', 0, $fee], ['Descuento', -25, -25]],
            'paid' => 307.50, 'count' => 'Primera reprogramación (el contrato permite 1, dentro de 60 días).',
        ];
    }

    public function cancel(): array
    {
        return [
            'when' => 'sáb. 7 nov. · faltan 19 días', 'paid' => 307.50, 'total' => 615,
            'rules' => [['El depósito no es reembolsable', true], ['Cancelación con menos de 48 h: se puede retener hasta el 100 %', false], ['Por clima, si pagó todo: crédito del 50 % por 60 días', false]],
            'outcome' => 'Se queda el depósito de $307.50. No hay saldo por cobrar. No se devuelve nada automáticamente.',
            'effects' => ['Se libera al staff y los trajes de esa fecha', 'Se anulan los recordatorios y el enlace de pago pendiente', 'Se conservan el contrato firmado, los pagos y el historial'],
        ];
    }
}
