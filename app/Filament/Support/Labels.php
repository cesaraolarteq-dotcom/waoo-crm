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
    public const EVENT_STATUS = ['draft' => 'Borrador', 'capacity_reserved' => 'Recursos apartados', 'cancelled' => 'Cancelada'];

    public const EVENT_STATUS_COLOR = ['draft' => 'gray', 'capacity_reserved' => 'info', 'cancelled' => 'danger'];

    public const SKILL = [
        'performer' => 'Personaje', 'entertainer' => 'Animador/a', 'host' => 'Recreador/a', 'dj' => 'DJ',
        'face_painter' => 'Pintacaritas', 'driver' => 'Conductor/a', 'foam_operator' => 'Espuma',
        'spa_facilitator' => 'Spa', 'photo_booth' => 'Photo booth',
        // Skills the owner's catalog import writes (ImportOwnerCatalog::SKILLS).
        'operator' => 'Operador/a', 'spa' => 'Facilitadora de spa', 'performer_wig' => 'Personaje con peluca',
    ];

    // Same keys as the owner's catalog page and waoo:import-catalog, so a unit created here matches what a package requires.
    public const EQUIPMENT = ['speaker' => 'Bocina', 'mic' => 'Micrófono', 'bubbles' => 'Máquina de burbujas', 'robot_tall' => 'Robot LED alto', 'robot_low' => 'Robot LED bajo', 'robot_suit' => 'Traje de robot LED (alto o bajo)',
        'smoke' => 'Pistola de humo / CO₂', 'foam' => 'Cañón de espuma', 'photo_booth' => 'Photo booth', 'vanity' => 'Tocador', 'disco_ball' => 'Bola disco',
        'sparkler' => 'Chisperos', 'table' => 'Mesa', 'face_kit' => 'Maleta de pintacaritas', 'cloud_machine' => 'Máquina de baile en las nubes',
        // Earlier keys, kept only so old test data still shows a name. Do not use for new units.
        'robot' => 'Robot LED (antiguo)', 'foam_cannon' => 'Cañón de espuma (antiguo)'];

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
