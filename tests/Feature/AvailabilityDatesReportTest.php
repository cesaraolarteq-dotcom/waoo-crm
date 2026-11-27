<?php

namespace Tests\Feature;

use App\Models\{GoogleCalendarConnection, ServiceVariant, User};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
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

    public function test_report_does_not_refresh_expired_google_credentials(): void
    {
        $this->travelTo(now('America/Chicago')->setDate(2026, 11, 27)->setTime(12, 0));
        config(['waoo.availability_crm_only' => false, 'waoo.honeybook_ical_url' => null]);
        Http::fake();
        $owner = User::factory()->create(['name' => 'María González']);
        $connection = GoogleCalendarConnection::create([
            'user_id' => $owner->id, 'google_email' => 'maria@example.test', 'access_token' => 'fixture-expired',
            'refresh_token' => 'fixture-refresh', 'access_expires_at' => now()->subHour(),
        ]);

        $this->artisan('waoo:fechas-reporte', ['dates' => ['2026-12-05']])
            ->expectsOutput('2026-12-05 · 0 fiestas registradas en el CRM (calendario externo sin confirmar)')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('fixture-expired', $connection->fresh()->access_token);
        $this->assertNull($connection->fresh()->last_error);
    }
}
