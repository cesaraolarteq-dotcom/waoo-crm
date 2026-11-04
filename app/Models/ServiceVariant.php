<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceVariant extends Model
{
    protected $fillable = ['name', 'long_description', 'status', 'duration_minutes', 'setup_minutes', 'breakdown_minutes', 'requirements', 'character_quantity', 'character_options', 'offered_as_extra', 'extra_character_cents', 'extends_event'];
    protected function casts(): array { return ['requirements' => 'array', 'character_options' => 'array', 'character_quantity' => 'integer', 'duration_minutes' => 'integer', 'version' => 'integer', 'setup_minutes' => 'integer', 'breakdown_minutes' => 'integer', 'offered_as_extra' => 'boolean', 'extends_event' => 'boolean', 'extra_character_cents' => 'integer']; }

    /** Price of each character beyond the included ones: this package's own, or the general one. */
    public static function extraCharacterPrice(?self $variant): int
    {
        return (int) ($variant?->extra_character_cents ?? config('waoo.extra_character_cents'));
    }

    /**
     * Owner 2026-11-04: minutes the party lasts — the longest service, plus every «extra time» service on top.
     * @param list<array{0: ?self, 1: int}> $services [variant, minutes]
     */
    public static function partyMinutes(array $services): int
    {
        $base = 0; $extra = 0;
        foreach ($services as [$variant, $minutes]) {
            if ($variant?->extends_event) $extra += $minutes;
            elseif (!$variant || !\App\Services\Records\SameLengthExtras::applies($variant)) $base = max($base, $minutes);
        }

        return $base + $extra;
    }
}
