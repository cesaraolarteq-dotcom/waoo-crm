<?php
namespace Tests\Review;

// Opt-in clock regression: all original notice UI assertions still run, frozen inside the failing five-minute window.
class Pr43ReviewProbeTest extends \Tests\Feature\ZelleNoticeScreensTest
{
    protected function setUp(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\CarbonImmutable::parse('2026-10-16T00:01:00-05:00'));
        \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::parse('2026-10-16T00:01:00-05:00'));
        parent::setUp();
    }
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            \Carbon\Carbon::setTestNow();
            \Carbon\CarbonImmutable::setTestNow();
        }
    }
}
