<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Owner decision 2026-10-28: website14 / internal owner confirmation7, in Houston calendar days. */
final class BookingNoticePolicy
{
    public static function requiresWebsiteApproval(CarbonInterface $startsAt, ?CarbonInterface $asOf = null): bool
    {
        return self::beforeNotice($startsAt, 14, $asOf);
    }

    public static function requiresOwnerConfirmationReason(CarbonInterface $startsAt, ?CarbonInterface $asOf = null): bool
    {
        return self::beforeNotice($startsAt, 7, $asOf);
    }

    private static function beforeNotice(CarbonInterface $startsAt, int $days, ?CarbonInterface $asOf): bool
    {
        $threshold = CarbonImmutable::instance($asOf ?? now())->setTimezone('America/Chicago')->addDays($days);

        return $startsAt->lessThan($threshold);
    }
}
