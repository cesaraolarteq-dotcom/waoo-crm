<?php

namespace App\Console\Commands;

use App\Filament\Support\Labels;
use App\Models\{Character, ServiceVariant};
use App\Services\Availability\{CheckWebsiteCandidates, WebsiteCapacity};
use App\Services\Scheduling\BookingNoticePolicy;
use App\Support\ChicagoTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class AvailabilityDatesReport extends Command
{
    protected $signature = 'waoo:fechas-reporte {dates?* : Fechas YYYY-MM-DD, hasta cinco}';

    protected $description = 'Consulta fiestas y horarios por paquete y personaje para los próximos cinco sábados';

    public function handle(CheckWebsiteCandidates $candidates): int
    {
        $now = CarbonImmutable::now(ChicagoTime::TZ);
        $dates = $this->argument('dates');
        if ($dates === []) {
            $first = $now->isSaturday() ? $now : $now->next(CarbonImmutable::SATURDAY);
            $dates = array_map(fn ($week) => $first->addWeeks($week)->toDateString(), range(0, 4));
        }
        if (count($dates) > 5 || validator(['dates' => $dates], [
            'dates.*' => ['date_format:Y-m-d', 'after_or_equal:'.$now->toDateString(), 'before:2101-01-01', 'distinct'],
        ])->fails()) {
            $this->error('Indica hasta cinco fechas distintas, de hoy en adelante, en formato YYYY-MM-DD.');
            return self::FAILURE;
        }

        $packages = ServiceVariant::where('status', 'active')->orderBy('name')->get();
        $characters = Character::where('active', true)->pluck('name', 'id');
        $this->line('Hora de Houston. Inicio cada 30 minutos de 09:00 a 20:30. Sin dirección de destino.');
        $this->line('Para paquetes con personajes, cada fila prueba un solo personaje con la cantidad exigida.');
        // External calendar readers may cache responses. Keep those writes in process memory.
        $cache = Cache::getDefaultDriver();
        Cache::setDefaultDriver('array');
        try {
            foreach ($dates as $date) {
                $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, ChicagoTime::TZ);
                $calendar = CheckWebsiteCandidates::busy($day->subDay(), $day->addDays(2), readOnly: true);
                $capacity = new WebsiteCapacity($day, $calendar);
                $source = $capacity->calendarOk() ? '' : ' registradas en el CRM (calendario externo sin confirmar)';
                $this->newLine();
                $this->info($date.' · '.$capacity->partiesOn($date).' fiestas'.$source);

                foreach ($packages as $package) {
                    $options = collect((array) $package->character_options)->pluck('character_id')->unique()
                        ->filter(fn ($id) => $characters->has($id))->values();
                    if ($options->isEmpty()) {
                        $this->show($capacity, $candidates, $package, $day, $now, [], $package->name);
                    }
                    foreach ($options as $id) {
                        $quantity = max(1, (int) $package->character_quantity);
                        $this->show($capacity, $candidates, $package, $day, $now,
                            [['character_id' => (int) $id, 'quantity' => $quantity]],
                            $package->name.' · '.$quantity.' × '.$characters[$id]);
                    }
                }
            }
        } finally {
            Cache::setDefaultDriver($cache);
        }

        return self::SUCCESS;
    }

    private function show(WebsiteCapacity $capacity, CheckWebsiteCandidates $candidates, ServiceVariant $package,
        CarbonImmutable $day, CarbonImmutable $now, array $characters, string $label): void
    {
        [$requirements, $complete] = $candidates->requirements($package, $characters);
        if (!$complete || !$package->duration_minutes || $package->setup_minutes === null || $package->breakdown_minutes === null) {
            $this->line("$label · revisar (faltan personajes o tiempos del paquete)");
            return;
        }
        if (!$capacity->calendarOk()) {
            $this->line("$label · revisar (calendario externo sin confirmar)");
            return;
        }

        $states = [];
        foreach (range(0, 23) as $slot) {
            $start = $day->setTime(9, 0)->addMinutes(30 * $slot);
            if ($start <= $now) continue;
            $from = $start->subMinutes($package->setup_minutes);
            $to = $start->addMinutes($package->duration_minutes + $package->breakdown_minutes + WebsiteCapacity::GAP_MINUTES);
            $state = $capacity->check($from, $to, $day->toDateString(), $requirements);
            $missing = $capacity->missingCandidateResource();
            $shortage = match ($missing['kind'] ?? null) {
                'equipment' => 'falta '.mb_strtolower(Labels::EQUIPMENT[$missing['equipment_kind'] ?? ''] ?? 'equipo compatible'),
                'costume' => 'falta el traje compatible',
                'vehicle' => 'falta un vehículo con los asientos requeridos',
                'staff' => 'falta personal con el perfil requerido',
                default => null,
            };
            $reason = match ($state) {
                'available' => BookingNoticePolicy::requiresWebsiteApproval($start, $now) ? 'requiere aprobación por cercanía de fecha' : 'se puede vender',
                'owner_approval_required' => $shortage ? "requiere aprobación ($shortage)" : 'requiere aprobación de capacidad o traslado',
                'review_required' => 'revisar (capacidad sin comprobar)',
                default => !$capacity->simultaneousOk($from, $to) || $capacity->partiesOn($day->toDateString()) >= WebsiteCapacity::MAX_PER_DAY
                    ? 'lleno (límite de fiestas)' : 'lleno ('.($shortage ?? 'combinación de recursos sin cupo').')',
            };
            $states[$reason][] = $start->format('H:i');
        }
        $summary = [];
        foreach ($states as $reason => $times) $summary[] = $reason.' '.implode(', ', $times);
        $this->line($label.' · '.($summary === [] ? 'sin horarios futuros en el rango' : implode(' | ', $summary)));
    }
}
