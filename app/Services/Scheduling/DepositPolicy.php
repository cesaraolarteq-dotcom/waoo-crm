<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Owner 2026-11-04: a party for today or tomorrow (Houston calendar) is booked only with at least 24 h of notice and paid 100 %,
 * at the normal price. Otherwise the deposit is 50 % (rounded up to the cent).
 */
final class DepositPolicy
{
    public static function fullPayment(CarbonInterface $startsAt, ?CarbonInterface $asOf = null): bool
    {
        $tomorrow = CarbonImmutable::instance($asOf ?? now())->setTimezone('America/Chicago')->addDay()->toDateString();

        return CarbonImmutable::instance($startsAt)->setTimezone('America/Chicago')->toDateString() <= $tomorrow;
    }

    public static function underDayNotice(CarbonInterface $startsAt, ?CarbonInterface $asOf = null): bool
    {
        return CarbonImmutable::instance($startsAt)->lessThan(CarbonImmutable::instance($asOf ?? now())->addHours(24));
    }

    public static function targetCents(int $totalCents, CarbonInterface $startsAt, ?CarbonInterface $asOf = null): int
    {
        return self::fullPayment($startsAt, $asOf) ? $totalCents : intdiv($totalCents, 2) + $totalCents % 2;
    }
}
