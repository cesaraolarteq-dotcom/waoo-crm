<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

// LOCAL/TESTING ONLY design preview of the owner side of STAFF-01 (team of each party + one fixed personal link per person; read-only staff page, owner 2026-10-18) and STAFFPAY-01
// (pay statement per person and period), docs/plans/PHASE-6.md. Fictitious data; buttons do nothing. The real screens
// wait for Codex's APIs and the owner's pay rules; amounts here are examples, not Waoo's rates.
class StaffPlanPreview extends Page
{
    protected static ?string $slug = 'vista-previa-staff';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $title = 'Staff y pagos (vista previa)';
    protected string $view = 'filament.pages.staff-plan-preview';

    public static function canAccess(): bool
    {
        return parent::canAccess() && app()->environment(['local', 'testing']);
    }

    public function getSubheading(): ?string
    {
        return 'Diseño para revisar con datos de ejemplo. Nada se guarda ni se envía; los montos no son tus tarifas.';
    }

    public function team(): array
    {
        return [
            'event' => 'Cumpleaños de Sofía · sáb. 7 nov. · 2:00 p. m. · Katy 77494',
            'roles' => [
                ['role' => 'Animadora', 'person' => 'Lucía', 'driver' => true],
                ['role' => 'Personaje: Rumi (Gala)', 'person' => 'Mateo', 'driver' => false],
                ['role' => 'Pintacaritas', 'person' => 'Andrea', 'driver' => false],
            ],
        ];
    }

    public function links(): array
    {
        return [
            ['person' => 'Lucía', 'week' => '2 fiestas esta semana', 'opened' => 'Lo abrió hoy, 9:12 a. m.'],
            ['person' => 'Mateo', 'week' => '1 fiesta esta semana', 'opened' => 'Lo abrió ayer'],
            ['person' => 'Andrea', 'week' => 'Sin fiestas esta semana', 'opened' => 'Todavía no lo abre'],
        ];
    }

    public function pay(): array
    {
        return [
            'period' => 'Semana del lun. 26 oct. al dom. 1 nov.',
            'rows' => [
                ['person' => 'Lucía', 'jobs' => 3, 'hours' => '7.5 h', 'planned' => '$262.50', 'extras' => '$20', 'tips' => '$30', 'expenses' => '$12', 'total' => '$324.50', 'state' => 'review'],
                ['person' => 'Mateo', 'jobs' => 2, 'hours' => '5 h', 'planned' => '$300', 'extras' => '—', 'tips' => '$15', 'expenses' => '—', 'total' => '$315', 'state' => 'approved'],
                ['person' => 'Andrea', 'jobs' => 1, 'hours' => '3 h', 'planned' => 'Pendiente', 'extras' => '—', 'tips' => '—', 'expenses' => '—', 'total' => 'Pendiente', 'state' => 'missing'],
            ],
        ];
    }
}
