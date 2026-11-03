<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffAvailability extends Model
{
    protected $table = 'staff_availability';

    protected $guarded = [];

    protected $casts = ['days' => 'array', 'week_start' => 'date:Y-m-d', 'answered_at' => 'immutable_datetime'];

    public const DAYS = ['sat', 'fri', 'sun', 'weekday']; // Saturday first: it is the day with most parties (owner 2026-11-03)

    /** "sat" | "fri" | "sun" | "weekday" for a Houston date. */
    public static function dayKey(\Carbon\CarbonInterface $local): string
    {
        return match ($local->dayOfWeekIso) { 5 => 'fri', 6 => 'sat', 7 => 'sun', default => 'weekday' };
    }

    /** yes | no | null (no answer) for a person on the day of a party. */
    public static function for(int $resourceId, \Carbon\CarbonInterface $startsAt): ?string
    {
        $local = \Carbon\CarbonImmutable::instance($startsAt)->setTimezone('America/Chicago');
        $row = self::where('resource_id', $resourceId)->whereDate('week_start', $local->startOfWeek(\Carbon\CarbonImmutable::MONDAY)->toDateString())->first();

        return $row?->days[self::dayKey($local)] ?? null;
    }
}
