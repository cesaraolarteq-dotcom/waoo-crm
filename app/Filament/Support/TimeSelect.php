<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Select;

/**
 * Owner 2026-10-21: the hour/minute picker hid AM/PM ("04:45"). One list of 15-minute slots labelled "4:45 PM",
 * starting at 6:00 AM (parties rarely start earlier) with the small hours at the end. State stays Houston "H:i".
 */
final class TimeSelect
{
    public static function make(string $name): Select
    {
        return Select::make($name)->native(false)->searchable()->placeholder('Elige la hora')
            ->options(fn ($state) => self::options(is_string($state) ? $state : null));
    }

    /** @return array<string, string> "H:i" => "g:i AM/PM"; an off-grid saved time (e.g. 15:07) stays selectable. */
    public static function options(?string $current = null): array
    {
        $slots = [];
        foreach ([...range(6 * 60, 24 * 60 - 15, 15), ...range(0, 6 * 60 - 15, 15)] as $m) {
            $slots[sprintf('%02d:%02d', intdiv($m, 60), $m % 60)] = self::label(intdiv($m, 60), $m % 60);
        }
        if ($current !== null && preg_match('/(\d{2}):(\d{2})(?::\d{2})?$/', trim($current), $x) && ! isset($slots["{$x[1]}:{$x[2]}"]) && (int) $x[1] < 24 && (int) $x[2] < 60) {
            $slots = ["{$x[1]}:{$x[2]}" => self::label((int) $x[1], (int) $x[2])] + $slots;
        }

        return $slots;
    }

    private static function label(int $h, int $m): string
    {
        return sprintf('%d:%02d %s', $h % 12 ?: 12, $m, $h < 12 ? 'AM' : 'PM');
    }
}
