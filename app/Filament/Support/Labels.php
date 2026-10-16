<?php

namespace App\Filament\Support;

use Carbon\CarbonInterface;

// Spanish labels and formats shared by the admin lists. Status is always words + color, never color alone.
final class Labels
{
    public const TZ = 'America/Chicago';

    public const KIND = ['staff' => 'Persona', 'costume' => 'Traje', 'equipment' => 'Equipo', 'vehicle' => 'Vehículo'];

    public const RESOURCE_STATUS = ['active' => 'Disponible', 'maintenance' => 'En mantenimiento', 'inactive' => 'Inactivo'];

    public const RESOURCE_STATUS_COLOR = ['active' => 'success', 'maintenance' => 'warning', 'inactive' => 'gray'];

    public const VARIANT_STATUS = ['draft' => 'Borrador', 'active' => 'Activo', 'retired' => 'Retirado'];

    public const VARIANT_STATUS_COLOR = ['draft' => 'warning', 'active' => 'success', 'retired' => 'gray'];

    // capacity_reserved means staff/costumes/equipment are held after a verified deposit. It is not "contract signed" or "paid".
    public const EVENT_STATUS = ['draft' => 'Borrador', 'capacity_reserved' => 'Recursos apartados'];

    public const EVENT_STATUS_COLOR = ['draft' => 'gray', 'capacity_reserved' => 'info'];

    public const SKILL = [
        'performer' => 'Personaje', 'entertainer' => 'Animador/a', 'host' => 'Recreador/a', 'dj' => 'DJ',
        'face_painter' => 'Pintacaritas', 'driver' => 'Conductor/a', 'foam_operator' => 'Espuma',
        'spa_facilitator' => 'Spa', 'photo_booth' => 'Photo booth',
    ];

    public const EQUIPMENT = ['speaker' => 'Bocina', 'robot' => 'Robot LED', 'photo_booth' => 'Photo booth', 'foam_cannon' => 'Cañón de espuma', 'vanity' => 'Tocador'];

    public static function label(array $map, ?string $key): string
    {
        return $key === null ? '—' : ($map[$key] ?? $key);
    }

    public static function money(?int $cents, string $unknown = 'Pendiente'): string
    {
        return $cents === null ? $unknown : '$'.number_format($cents / 100, $cents % 100 ? 2 : 0);
    }

    public static function duration(?int $minutes): string
    {
        if (! $minutes) {
            return '—';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h ? "{$h} h " : '').($m ? "{$m} min" : ''));
    }

    public static function when(?CarbonInterface $at): string
    {
        return $at ? $at->setTimezone(self::TZ)->locale('es')->translatedFormat('D j M Y, g:i a') : '—';
    }
}
