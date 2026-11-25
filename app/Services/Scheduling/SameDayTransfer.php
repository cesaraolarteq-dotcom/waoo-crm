<?php

namespace App\Services\Scheduling;

use Carbon\CarbonInterface;

/**
 * Whether two parties of the same person or costume are close enough to need a real drive check between them.
 * 12 hours covers any drive in the service area (70 mi max) plus 30 minutes and setup. Owner 2026-11-25: a party on
 * another day (last week, next month) must never be treated as the previous or next stop — the route provider cannot
 * price a past departure, and the owner was asked to type drive times between parties on different days.
 */
final class SameDayTransfer
{
    public const MAX_GAP_HOURS = 12;

    public static function needed(CarbonInterface $fromEnd, CarbonInterface $toStart): bool
    {
        return $fromEnd->diffInMinutes($toStart, true) < self::MAX_GAP_HOURS * 60;
    }
}
