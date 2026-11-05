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
    /** Infer the original agreement, excluding change fees which are paid with the balance. Never use today's date for a revision. */
    public static function revisionPercent(\App\Models\FinancialRevision $revision): int
    {
        if(in_array($revision->deposit_percent_snapshot,[50,100],true))return $revision->deposit_percent_snapshot;
        $fees = (int) collect($revision->lines_snapshot ?? [])->where('kind', 'change_fee')->sum('amount_cents');
        $base = $revision->total_cents - $fees;
        return $base > 1 && $revision->deposit_target_cents >= $base ? 100 : 50;
    }

    /** Choices may exceed, but must never undercut, the frozen financial target. */
    public static function collectionPercents(\App\Models\FinancialRevision $revision): array
    {
        $percents=$revision->deposit_percent_snapshot===100?[100]:[50,100];
        return array_values(array_filter($percents, fn ($p) => ($p === 100 ? $revision->total_cents : intdiv($revision->total_cents + 1, 2)) >= $revision->deposit_target_cents));
    }

    /** A newly offered unattended booking needs 24 hours; previously recorded money remains reconcilable. */
    public static function assertNotice(CarbonInterface $startsAt): void
    {
        \App\Exceptions\RecordWriteRejected::when(self::underDayNotice($startsAt), 409, 'Faltan menos de 24 horas: la regla es no reservar.');
    }

    /** Stop the provider's payment window before the 24-hour boundary, without renewing the original 48-hour lease. */
    public static function checkoutExpiry(int $expiry, CarbonInterface $startsAt, int $createdAt): int
    {
        $expiry = min($expiry, $startsAt->getTimestamp() - 86400);
        \App\Exceptions\RecordWriteRejected::unless($expiry >= $createdAt + 1860, 409, 'No queda tiempo suficiente para abrir otro cobro. Revisa el apartado.');
        return $expiry;
    }

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
