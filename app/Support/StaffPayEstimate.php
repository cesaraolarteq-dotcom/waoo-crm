<?php

namespace App\Support;

use App\Models\{Event, EventService, ServiceVariant};

/**
 * First draft of an event's estimated costs (owner 2026-10-28, STAFFPAY-01 rates in docs/DECISIONS.md): staff pay per
 * role from each service's staffing and duration, with the $50 minimum per person per service, plus $30 of materials
 * per service. A role without an owner rate stays empty ("tarifa pendiente") so the margin stays pending; driver pay is
 * never computed (the owner types it per party). The owner reviews and saves; nothing is stored from here.
 */
final class StaffPayEstimate
{
    /** Hourly pay in cents per staffing skill, as decided by the owner. Missing skills have no rate yet. */
    // Character rates (personaje / traje / peluca) wait for the owner's confirmation (Ruta s-tarifas): pending, never guessed.
    // Owner 2026-11-26 (Ruta s-tarifas): face painter, spa, DJ and Photo Booth operator $25/h. The driver is decided per payment.
    public const HOURLY_CENTS = ['host' => 2500, 'entertainer' => 3500, 'face_painter' => 2500, 'spa' => 2500, 'dj' => 2500, 'operator' => 2500];
    public const ROLE = ['host' => 'Recreador', 'face_painter' => 'Recreador (pintacaritas)', 'entertainer' => 'Animador', 'operator' => 'Operador',
        'dj' => 'DJ', 'spa' => 'Spa', 'performer' => 'Personaje', 'performer_wig' => 'Personaje de peluca'];
    public const MINIMUM_CENTS = 5000;
    /** Owner 2026-11-23: a 30-minute service pays at least $40. */
    public const HALF_HOUR_MINIMUM_CENTS = 4000;
    /** Owner 2026-11-23: the only exception: the animator of the Magic package, 1 hour, is paid $40 (not $35 and not the $50 minimum). */
    public const MAGIC_ANIMATOR_HOUR_CENTS = 4000;
    public const MATERIALS_CENTS = 3000;

    /** @return list<array{label: string, category: string, amount: ?string, event_service_id: int}> */
    public static function lines(Event $event): array
    {
        $lines = [];
        foreach (EventService::where('event_id', $event->id)->orderBy('id')->get() as $service) {
            $name = ServiceVariant::whereKey($service->service_variant_id)->value('name') ?? 'Servicio';
            $minutes = (int) ($service->duration_minutes ?: ServiceVariant::whereKey($service->service_variant_id)->value('duration_minutes'));
            $hours = rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.');
            foreach ((array) ($service->requirements_snapshot ?? []) as $r) {
                if (($r['kind'] ?? null) !== 'staff') continue;
                $skill = (string) ($r['skill'] ?? '');
                $rate = self::HOURLY_CENTS[$skill] ?? null;
                $pay = $rate === null ? null : self::pay($skill, $name, $rate, $minutes);
                for ($i = 0; $i < max(1, (int) ($r['quantity'] ?? 1)); $i++) {
                    $lines[] = ['label' => (self::ROLE[$skill] ?? ucfirst($skill)).' · '.$name.' ('.$hours.' h)'.($rate === null ? ' · tarifa pendiente' : ''),
                        'category' => 'staff_labor', 'amount' => $pay === null ? null : self::dollars($pay), 'event_service_id' => $service->id];
                }
            }
            $lines[] = ['label' => 'Materiales · '.$name, 'category' => 'materials', 'amount' => self::dollars(self::MATERIALS_CENTS), 'event_service_id' => $service->id];
        }

        return $lines;
    }

    public static function pay(string $skill, string $packageName, int $rateCents, int $minutes): int
    {
        if ($skill === 'entertainer' && $minutes === 60 && preg_match('/m[aá]gic/iu', $packageName)) return self::MAGIC_ANIMATOR_HOUR_CENTS;
        return max(intdiv($rateCents * $minutes + 30, 60), $minutes <= 30 ? self::HALF_HOUR_MINIMUM_CENTS : self::MINIMUM_CENTS);
    }

    private static function dollars(int $cents): string
    {
        return rtrim(rtrim(number_format($cents / 100, 2, '.', ''), '0'), '.');
    }
}
