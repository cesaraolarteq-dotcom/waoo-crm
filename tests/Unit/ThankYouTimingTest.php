<?php
namespace Tests\Unit;

use App\Models\{Event, FinancialRevision};
use App\Services\Mail\EventMailSource;
use Carbon\CarbonImmutable;
use Tests\TestCase;

// Owner 2026-10-18: the thank-you + review email goes out the day after the party at 10:00 am Houston time (CST and CDT).
class ThankYouTimingTest extends TestCase
{
    public function test_thank_you_is_due_the_next_houston_day_at_ten(): void
    {
        foreach ([['2026-10-17T19:00:00-05:00', '2026-10-18T15:00:00Z'], ['2026-11-28T23:30:00-06:00', '2026-11-29T16:00:00Z'], ['2026-03-07T21:00:00-06:00', '2026-03-08T15:00:00Z']] as [$end, $due]) {
            $e = (new Event)->forceFill(['ends_at' => CarbonImmutable::parse($end)]);
            $this->assertTrue(CarbonImmutable::parse($due)->equalTo(EventMailSource::due('thank_you_review', $e, new FinancialRevision)), $end);
        }
    }
}
