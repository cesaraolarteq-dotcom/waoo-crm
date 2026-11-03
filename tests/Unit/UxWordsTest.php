<?php
namespace Tests\Unit;

use App\Filament\Support\Labels;
use App\Services\Changes\RescheduleEvent;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Owner 2026-11-02 audit, delivery 1: plain words, names that never show an old date, no year on this year's dates. */
class UxWordsTest extends TestCase
{
    public function test_a_moved_party_keeps_its_name_but_with_the_new_date(): void
    {
        $at = fn ($s) => CarbonImmutable::parse($s, 'America/Chicago');
        $this->assertSame('Estrella (Star) 11/07 Sofía Ramírez', RescheduleEvent::retitle('Estrella (Star) 11/15 Sofía Ramírez', $at('2026-11-15 14:00'), $at('2026-11-07 14:00')));
        $this->assertSame('Magic Package 01/09/27 María González', RescheduleEvent::retitle('Magic Package 12/5/26 María González', $at('2026-12-05 14:00'), $at('2027-01-09 14:00')));
        $this->assertSame('Estrella · Sofía Ramírez', RescheduleEvent::retitle('Estrella · Sofía Ramírez', $at('2026-11-15 14:00'), $at('2026-11-07 14:00')));
        $this->assertSame('Fiesta 111/15', RescheduleEvent::retitle('Fiesta 111/15', $at('2026-11-15 14:00'), $at('2026-11-07 14:00')), 'Only a whole date token moves.');
    }

    public function test_this_years_dates_skip_the_year(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'America/Chicago'));
        $this->assertStringNotContainsString('2026', Labels::when(CarbonImmutable::parse('2026-11-07 14:00', 'America/Chicago')));
        $this->assertStringContainsString('2027', Labels::when(CarbonImmutable::parse('2027-01-09 14:00', 'America/Chicago')));
        $this->assertSame('Confirmada', Labels::label(Labels::EVENT_STATUS, 'capacity_reserved'));
    }
}
