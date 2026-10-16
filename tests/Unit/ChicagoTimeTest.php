<?php
namespace Tests\Unit;

use App\Filament\Support\ChicagoTime;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChicagoTimeTest extends TestCase
{
    public function test_ordinary_times_get_the_right_offset_in_summer_and_winter(): void
    {
        $this->assertSame('2026-07-04T15:00:00-05:00', ChicagoTime::toIso('2026-07-04', '15:00', null, 'start'));
        $this->assertSame('2026-12-19T15:00:00-06:00', ChicagoTime::toIso('2026-12-19', '15:00', null, 'start'));
    }

    public function test_november_repeated_hour_needs_an_explicit_choice(): void
    {
        $this->assertTrue(ChicagoTime::isAmbiguous('2026-11-01', '01:30'));
        $this->assertSame('2026-11-01T01:30:00-05:00', ChicagoTime::toIso('2026-11-01', '01:30', 'cdt', 'start'));
        $this->assertSame('2026-11-01T01:30:00-06:00', ChicagoTime::toIso('2026-11-01', '01:30', 'cst', 'start'));
        $this->expectException(ValidationException::class);
        ChicagoTime::toIso('2026-11-01', '01:30', null, 'start');
    }

    public function test_march_skipped_hour_is_refused(): void
    {
        $this->assertSame([], ChicagoTime::candidates('2027-03-14', '02:30'));
        $this->expectException(ValidationException::class);
        ChicagoTime::toIso('2027-03-14', '02:30', 'cdt', 'start');
    }

    public function test_round_trip_keeps_the_chosen_occurrence(): void
    {
        foreach (['cdt', 'cst'] as $choice) {
            $iso = ChicagoTime::toIso('2026-11-01', '01:30', $choice, 'start');
            $this->assertSame(['2026-11-01', '01:30', $choice], ChicagoTime::toLocalParts(new \DateTimeImmutable($iso)));
        }
        $this->assertSame(['2026-07-04', '15:00', null], ChicagoTime::toLocalParts(new \DateTimeImmutable('2026-07-04T20:00:00Z')));
    }
}
