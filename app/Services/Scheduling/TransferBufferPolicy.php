<?php

namespace App\Services\Scheduling;

final class TransferBufferPolicy
{
    public static function minimumGapMinutes(?int $breakdown, ?int $setup, int $travel, int $contingency): ?int
    {
        if ($breakdown === null || $setup === null || $breakdown < 0 || $setup < 0 || $travel < 0 || $contingency < 0) {
            return null;
        }

        return $travel + max(30, $breakdown + $setup + $contingency);
    }
}
