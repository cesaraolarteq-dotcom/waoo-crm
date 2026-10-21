<?php
namespace Tests\Feature;

use App\Filament\Support\ReportsSource;
use App\Support\OwnerReportMail;
use Tests\TestCase;

// REPORT-A Monday email design (internal): last completed week + upcoming weekend, short subject, honest notes.
class OwnerReportMailPreviewTest extends TestCase
{
    public function test_monday_email_shows_last_week_and_the_upcoming_weekend_with_a_short_subject(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-19T14:00:00Z')); // Monday in Houston
        $this->get('/dev/mail-owner-report')->assertOk()->assertSee('Reporte del lunes')->assertSee('Semana del lunes 12 de octubre al domingo 18 de octubre')
            ->assertSee('Dinero que entró')->assertSee('Próximo fin de semana (sáb. 24 y dom. 25 oct.)')->assertSee('1 reserva sin paquete registrado')
            ->assertSee('No lo reenvíes a clientes')->assertDontSee('Recibes este correo por tu reserva');
        foreach (['2026-09-28', '2026-12-28'] as $long) { // longest Spanish month abbreviations
            $f = ReportsSource::fixture('week', $long);
            $this->assertLessThanOrEqual(60, mb_strlen(OwnerReportMail::weekly($f['operations'], $f['bookings'], $f['weekend'])['subject']));
        }
    }

    public function test_closed_outside_local_and_testing(): void
    {
        $this->app['env'] = 'staging';
        $this->get('/dev/mail-owner-report')->assertNotFound();
    }
}
