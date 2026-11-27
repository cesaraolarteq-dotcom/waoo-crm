<?php

namespace Tests\Feature;

use App\Models\ServiceVariant;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

final class AvailabilityDatesReportTest extends TestCase
{
    use DatabaseMigrations;

    public function test_default_lists_five_saturdays_without_changing_bookings(): void
    {
        $this->travelTo(now('America/Chicago')->setDate(2026, 11, 27)->setTime(12, 0));
        $this->app->instance('waoo.availability.busy', fn () => []);
        ServiceVariant::create([
            'name' => 'Música y juegos', 'status' => 'active', 'duration_minutes' => 60,
            'setup_minutes' => 15, 'breakdown_minutes' => 15, 'requirements' => [],
        ]);

        $this->artisan('waoo:fechas-reporte')
            ->expectsOutput('2026-11-28 · 0 fiestas')
            ->expectsOutput('2026-12-05 · 0 fiestas')
            ->expectsOutput('2026-12-12 · 0 fiestas')
            ->expectsOutput('2026-12-19 · 0 fiestas')
            ->expectsOutput('2026-12-26 · 0 fiestas')
            ->expectsOutputToContain('se puede vender')
            ->assertSuccessful();

        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('resource_reservations', 0);
    }
}
