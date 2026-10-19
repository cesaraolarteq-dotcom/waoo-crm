<?php
namespace App\Services\Reports;

use App\Models\{OwnerReportSnapshot, User};
use App\Services\Finance\Sandbox;
use App\Services\Portal\PortalContext;
use App\Services\Records\RecordActor;

final class RenderOwnerReportEmail
{
    public function execute(User $actor, int $snapshotId): array
    {
        Sandbox::require();
        RecordActor::authorize($actor);
        PortalContext::requireTransport(request());
        $snapshot = OwnerReportSnapshot::where('environment', 'test')->findOrFail($snapshotId);
        abort_unless(hash_equals($snapshot->payload_sha256, hash('sha256', $snapshot->payload_json)), 409, 'El reporte guardado no coincide con su evidencia.');
        $payload = json_decode($snapshot->payload_json, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($payload['schema_version'] ?? null) === 1, 409, 'Versión de reporte no compatible.');
        $start = \Carbon\CarbonImmutable::parse($snapshot->period_starts_on, 'America/Chicago')->locale('es');
        $end = \Carbon\CarbonImmutable::parse($snapshot->period_ends_before, 'America/Chicago')->subDay()->locale('es');
        $range = $snapshot->kind === 'monthly' ? ucfirst($start->translatedFormat('F \d\e Y'))
            : 'Semana del '.$start->translatedFormat($start->year === $end->year ? 'j \d\e M' : 'j \d\e M Y').' al '.$end->translatedFormat('j \d\e M Y');
        $subject = $snapshot->kind === 'monthly' ? 'Reporte de '.$range : $range;
        // Explicit aggregates only: never forward a full financial payload into email copy.
        $money = static fn ($value): string => is_int($value) ? '$'.number_format($value / 100, 2, '.', ',').' USD' : 'Pendiente';
        $scheduled = $payload['operations']['scheduled'] ?? [];
        $sections = [
            ['label' => 'Período', 'value' => $range],
            ['label' => 'Reservas nuevas', 'value' => (string) ($payload['bookings']['booking_count'] ?? 0)],
            ['label' => 'Cobrado neto en CRM (prueba)', 'value' => $money($payload['operations']['cash']['net_received_cents'] ?? null)],
            ['label' => 'Saldo conocido de eventos programados', 'value' => $money($scheduled['pending_balance_cents'] ?? null)],
            ['label' => 'Eventos con saldo desconocido', 'value' => (string) ($scheduled['pending_balance_unknown_events'] ?? 'Pendiente')],
            ['label' => 'Costo directo estimado', 'value' => $money($scheduled['estimate']['direct_cost_cents'] ?? null)],
            ['label' => 'Costo directo real registrado', 'value' => $money($scheduled['actual']['direct_cost_cents'] ?? null)],
            ['label' => 'Cobertura de paquetes', 'value' => ($payload['bookings']['package_coverage_complete'] ?? false) ? 'Completa' : 'Pendiente'],
        ];
        if (is_array($payload['upcoming_weekend'] ?? null)) {
            $weekend = $payload['upcoming_weekend'];
            $sections[] = ['label' => 'Próximo fin de semana', 'value' => $weekend['window']['starts_on'].' / '.$weekend['window']['ends_before']];
            $sections[] = ['label' => 'Costo estimado del personal (fin de semana)', 'value' => $money($weekend['estimate']['staff_cost_cents'] ?? null)];
            $sections[] = ['label' => 'Costo real registrado del personal (fin de semana)', 'value' => $money($weekend['actual']['staff_cost_cents'] ?? null)];
        }
        foreach ($payload['bookings']['packages'] ?? [] as $package) {
            $sections[] = ['label' => 'Paquete: '.$package['name'], 'value' => (string) $package['occurrence_count']];
        }
        $text = "Preparado, sin enviar.\n".implode("\n", array_map(fn ($section) => $section['label'].': '.$section['value'], $sections));
        return ['schema_version' => 1, 'snapshot_id' => $snapshot->id, 'payload_sha256' => $snapshot->payload_sha256,
            'recipient' => 'sales@waooparty.com', 'locale' => 'es', 'subject' => $subject, 'text' => $text,
            'sections' => $sections, 'approved' => false, 'sending_enabled' => false];
    }
}
