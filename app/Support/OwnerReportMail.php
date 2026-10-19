<?php

namespace App\Support;

use App\Filament\Support\{Labels, ReportsSource};
use Carbon\CarbonImmutable;

// REPORT-A Monday email design (internal, to sales@waooparty.com). Built from the same report payloads as the
// Reportes screen; draft design only — the real sender is Codex's prepared snapshot + MAIL outbox, still inactive.
final class OwnerReportMail
{
    /** @return array{subject:string, period:string, tiles:array, packages:string, weekend:string, notes:array} */
    /** $operations/$bookings: the completed week; $weekend: the upcoming weekend (anchor = the Monday it is sent). */
    public static function weekly(array $operations, array $bookings, array $weekend): array
    {
        $s = CarbonImmutable::parse($operations['period']['starts_on'], 'America/Chicago')->locale('es');
        $e = CarbonImmutable::parse($operations['period']['ends_before'], 'America/Chicago')->subDay()->locale('es');
        $money = fn (?int $c) => Labels::money($c, 'Pendiente');
        $sch = $operations['scheduled'];
        $ws = CarbonImmutable::parse($weekend['window']['starts_on'], 'America/Chicago')->locale('es');
        $period = $s->translatedFormat('j M').' – '.$e->translatedFormat('j M');

        return [
            // ≤ 60 characters with the longest month names ("sept." / "dic."), money in the subject.
            'subject' => mb_substr('Waoo · semana '.$period.': entraron '.$money($operations['cash']['net_received_cents']), 0, 60),
            'period' => 'Semana del '.$s->translatedFormat('l j \d\e F').' al '.$e->translatedFormat('l j \d\e F'),
            'tiles' => [
                ['label' => 'Dinero que entró', 'value' => $money($operations['cash']['net_received_cents']), 'note' => 'por fecha de pago'],
                ['label' => 'Saldos por cobrar', 'value' => $money($sch['pending_balance_cents']), 'note' => $sch['event_count'].' fiestas de la semana'],
                ['label' => 'Staff del próximo fin de semana', 'value' => $money($weekend['estimate']['staff_cost_cents']), 'note' => 'estimado'],
                ['label' => 'Reservas confirmadas', 'value' => (string) $bookings['booking_count'], 'note' => 'nuevas esta semana'],
            ],
            'packages' => ReportsSource::packagesLine($bookings),
            'weekend' => 'Próximo fin de semana ('.$ws->translatedFormat('D j').' y '.$ws->addDay()->translatedFormat('D j M').'): '.$weekend['event_count'].' fiestas confirmadas'
                .($weekend['draft_event_count'] ? ' y '.$weekend['draft_event_count'].' borrador sin confirmar' : '').', saldos por cobrar '.$money($weekend['pending_balance_cents']).'.',
            'notes' => array_values(array_filter([
                $sch['actual']['pending_events'] ? 'Faltan los costos reales de '.$sch['actual']['pending_events'].($sch['actual']['pending_events'] === 1 ? ' fiesta.' : ' fiestas.') : null,
                $bookings['package_unknown_booking_count'] ? $bookings['package_unknown_booking_count'].($bookings['package_unknown_booking_count'] === 1 ? ' reserva sin paquete registrado.' : ' reservas sin paquete registrado.') : null,
            ])),
        ];
    }
}
