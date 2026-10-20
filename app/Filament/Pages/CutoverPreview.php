<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

// LOCAL/TESTING ONLY design preview of the HoneyBook → CRM switch-over (docs/RESPONSIBLE-SYSTEM-API.md C2/C3,
// docs/plans/PHASE-8.md). Same shape as GET /admin/api/cutover/honeybook; fictitious aggregates only, no client list.
// Buttons do nothing. The real screen waits for Codex's C2/C3 services and César's freshness policy.
class CutoverPreview extends Page
{
    protected static ?string $slug = 'vista-previa-cambio-final';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $title = 'Pasar las fiestas de HoneyBook al CRM (vista previa)';
    protected string $view = 'filament.pages.cutover-preview';

    public bool $attest = false;

    public static function canAccess(): bool
    {
        return parent::canAccess() && app()->environment(['local', 'testing']);
    }

    public function getSubheading(): ?string
    {
        return 'Diseño para revisar con números de ejemplo. Nada cambia, nada se cobra ni se envía.';
    }

    public function state(): array
    {
        return [
            'counts' => ['events' => 18, 'ended' => 0, 'with_balance' => 11, 'contracts' => 18],
            'totals' => ['total' => 12840, 'paid' => 6420, 'balance' => 6420],
            'unknowns' => 1,
            'missing' => ['1 fiesta sin el PDF del contrato firmado de HoneyBook', '2 fiestas con tu revisión de cuentas de hace más de 4 horas (la regla que aprobaste)'],
            'reconciliation' => ['when' => 'lun. 2 nov., 9:10 a. m.', 'expires' => 'lun. 2 nov., 1:10 p. m.'],
            'reminders' => ['balance' => 11, 'week_before' => 14, 'thank_you' => 18],
        ];
    }
}
