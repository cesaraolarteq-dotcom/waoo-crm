<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Owner-approved notice: fourteen local calendar days, not fourteen UTC 24-hour periods. */
final class BookingNoticePolicy
{
    public static function requiresOwnerApproval(CarbonInterface $startsAt, ?CarbonInterface $asOf = null): bool
    {
        $threshold = CarbonImmutable::instance($asOf ?? now())->setTimezone('America/Chicago')->addDays(14);

        return $startsAt->lessThan($threshold);
    }
}
