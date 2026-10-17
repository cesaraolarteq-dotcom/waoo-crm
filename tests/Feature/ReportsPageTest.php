<?php
namespace Tests\Feature;

use App\Filament\Pages\Reports;
use App\Filament\Support\ReportsSource;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

// REPORT-A owner screen. Until Codex's report services are merged, local/testing shows a labelled sample shaped like
// the contracts; these tests pin the wording rules (Pendiente never $0, separate cohorts, nothing sent).
class ReportsPageTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true]);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-14T17:00:00Z')); // a Wednesday in Houston
        $this->actingAs(User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]));
    }

    public function test_week_view_labels_the_period_cohorts_and_missing_costs(): void
    {
        $this->assertFalse(ReportsSource::live());
        Livewire::test(Reports::class)->assertSee('Datos de ejemplo')
            ->assertSee('Semana del lun. 12 oct. al dom. 18 oct. 2026')
            ->assertSee('Dinero que entró')->assertSee('$1,845')->assertSee('por la fecha en que llegaron')
            ->assertSee('Saldos por cobrar')->assertSee('1 sin montos fijados')
            ->assertSee('Costo del staff')->assertSee('Pendiente')->assertSee('Faltan los costos de 3 fiestas')->assertSee('Estimado: $810')
            ->assertSee('Reservas confirmadas')->assertSee('2 Waoo Package')->assertSee('1 sin paquete registrado')
            ->assertSee('Fin de semana')->assertSee('1 borrador sin confirmar')
            ->assertSee('no se restan entre sí')->assertDontSee('Ganancia');
    }

    public function test_month_and_navigation_change_the_period_only(): void
    {
        Livewire::test(Reports::class)->call('setPeriod', 'month')->assertSee('Octubre 2026')
            ->call('move', 1)->assertSee('Noviembre 2026')->assertSet('anchor', '2026-11-14')
            ->call('today')->assertSet('anchor', '2026-10-14')
            ->call('setPeriod', 'week')->call('move', -1)->assertSee('Semana del lun. 5 oct. al dom. 11 oct. 2026');
    }

    public function test_preparing_without_the_report_service_explains_and_sends_nothing(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        Livewire::test(Reports::class)->call('prepare', 'weekly')->assertNotified('Todavía no disponible')->assertSet('prepared', null);
        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    public function test_hidden_without_finance_sandbox_and_never_shows_samples_outside_local_testing(): void
    {
        config(['waoo.sandbox_finance' => false]);
        $this->get('/admin/reportes')->assertForbidden();
        config(['waoo.sandbox_finance' => true]);
        $this->app['env'] = 'staging';
        $this->assertFalse(ReportsSource::available());
    }
}
