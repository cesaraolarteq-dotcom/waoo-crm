<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

// Local Houston date + time from a form -> ISO 8601 with the explicit offset the services require (docs/RECORD-SERVICES.md).
// Never guesses: the November repeated hour needs the owner's choice (CDT or CST); the March skipped hour is refused.
final class ChicagoTime
{
    public const TZ = 'America/Chicago';

    public const CHOICES = ['cdt' => 'Primera vez (horario de verano, CDT)', 'cst' => 'Segunda vez (horario estándar, CST)'];

    private const OFFSETS = ['cdt' => '-05:00', 'cst' => '-06:00'];

    /** @return array<string, string> choice => ISO 8601, only the offsets that really produce that local time */
    public static function candidates(?string $date, ?string $time): array
    {
        if (! $date || ! $time || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^\d{2}:\d{2}/', $time)) {
            return [];
        }
        $time = substr($time, 0, 5);
        $found = [];
        foreach (self::OFFSETS as $choice => $offset) {
            $at = CarbonImmutable::createFromFormat('Y-m-d H:i P', "{$date} {$time} {$offset}");
            if ($at && $at->setTimezone(self::TZ)->format('Y-m-d H:i') === "{$date} {$time}") {
                $found[$choice] = $at->format('Y-m-d\TH:i:sP');
            }
        }

        return $found;
    }

    public static function isAmbiguous(?string $date, ?string $time): bool
    {
        return count(self::candidates($date, $time)) === 2;
    }

    /** @throws ValidationException on the skipped hour, or on the repeated hour without a choice */
    public static function toIso(string $date, string $time, ?string $choice, string $field): string
    {
        $candidates = self::candidates($date, $time);
        if ($candidates === []) {
            throw ValidationException::withMessages([$field => 'Esa hora no existe ese día en Houston (cambio de horario de marzo). Elige otra hora.']);
        }
        if (count($candidates) === 1) {
            return reset($candidates);
        }
        if (! $choice || ! isset($candidates[$choice])) {
            throw ValidationException::withMessages([$field => 'Esa hora se repite ese día (cambio de horario de noviembre). Indica cuál de las dos es.']);
        }

        return $candidates[$choice];
    }

    /** Stored UTC instant -> [date, time, choice] for filling a form. */
    public static function toLocalParts(?\DateTimeInterface $at): array
    {
        if (! $at) {
            return [null, null, null];
        }
        $local = CarbonImmutable::instance($at)->setTimezone(self::TZ);
        $date = $local->format('Y-m-d');
        $time = $local->format('H:i');
        $choice = self::isAmbiguous($date, $time) ? ($local->format('P') === '-05:00' ? 'cdt' : 'cst') : null;

        return [$date, $time, $choice];
    }
}
