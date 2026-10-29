<?php

namespace Tests\Unit;

use App\Services\Scheduling\TransferBufferPolicy;
use PHPUnit\Framework\TestCase;

final class TransferBufferPolicyTest extends TestCase
{
    public function test_travel_is_added_to_the_floor_without_double_counting_work(): void
    {
        self::assertSame(58, TransferBufferPolicy::minimumGapMinutes(10, 10, 28, 10));
        self::assertSame(58, TransferBufferPolicy::minimumGapMinutes(0, 0, 28, 10));
    }

    public function test_longer_setup_and_breakdown_override_the_floor(): void
    {
        self::assertSame(88, TransferBufferPolicy::minimumGapMinutes(20, 30, 28, 10));
    }

    public function test_missing_or_invalid_work_cannot_be_treated_as_zero(): void
    {
        self::assertNull(TransferBufferPolicy::minimumGapMinutes(null, 10, 28, 10));
        self::assertNull(TransferBufferPolicy::minimumGapMinutes(10, null, 28, 10));
        self::assertNull(TransferBufferPolicy::minimumGapMinutes(-1, 10, 28, 10));
    }
}
