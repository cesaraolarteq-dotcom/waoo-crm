<?php
namespace Tests\Unit;

use App\Support\StaffPayEstimate;
use PHPUnit\Framework\TestCase;

// Owner 2026-11-23: $50 minimum for 1-hour services, $40 for 30 minutes, Magic animator 1 hour = $40 (only exception).
class StaffPayEstimateRulesTest extends TestCase
{
    public function test_minimums_and_the_magic_animator_exception(): void
    {
        $this->assertSame(5000, StaffPayEstimate::pay('host', 'Música y Juegos', 2500, 60));
        $this->assertSame(4000, StaffPayEstimate::pay('host', 'Pintacaritas', 2500, 30));
        $this->assertSame(4000, StaffPayEstimate::pay('entertainer', 'Mágico (Magic)', 3500, 60));
        $this->assertSame(5000, StaffPayEstimate::pay('host', 'Mágico (Magic)', 2500, 60), 'Only the animator.');
        $this->assertSame(7000, StaffPayEstimate::pay('entertainer', 'Mágico (Magic)', 3500, 120), 'Two hours: normal rate.');
        $this->assertSame(7000, StaffPayEstimate::pay('entertainer', 'Música y Juegos', 3500, 120));
    }

    public function test_character_rates_stay_pending_until_the_owner_confirms_them(): void
    {
        $this->assertArrayNotHasKey('performer', StaffPayEstimate::HOURLY_CENTS);
        $this->assertArrayNotHasKey('performer_wig', StaffPayEstimate::HOURLY_CENTS);
        foreach (['face_painter', 'spa', 'dj', 'operator'] as $skill) $this->assertSame(2500, StaffPayEstimate::HOURLY_CENTS[$skill]);
        $this->assertSame(5000, StaffPayEstimate::pay('face_painter', 'Pintacaritas', 2500, 60), 'One hour: $50 minimum.');
    }
}
