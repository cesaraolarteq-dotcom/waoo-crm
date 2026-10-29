<?php
namespace Tests\Feature;

use App\Filament\Pages\{Dashboard, Reports};
use App\Filament\Support\ReportsSource;
use App\Models\{OwnerReportSnapshot, Resource};
use App\Services\Finance\{ConfirmPaidCapacity, CreateFinancialRevision, RecordZellePayment};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\QuoteScenario;
use Tests\TestCase;

// REPORT-A owner screen over Codex's consolidated panel (real services, fictitious data): last completed period,
// unknown costs as "Pendiente", frozen preparation with email preview and nothing sent.
class ReportsPageTest extends TestCase
{
    use RefreshDatabase, QuoteScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-14T17:00:00Z'));
        $this->scenario(); // draft at now + 20 days, 1 h
        $finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
        app(RecordZellePayment::class)->execute($this->owner, $this->event->id, ['financial_revision_id' => $finance->id, 'amount_cents' => $finance->deposit_target_cents, 'currency' => 'USD',
            'reference' => 'REPORT-QA-1', 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true], (string) Str::uuid());
        $units = [Resource::create(['code' => 'REP-PERF', 'name' => 'Fixture Performer', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer', 'driver']])->id,
            Resource::create(['code' => 'REP-SPK', 'name' => 'Fixture Speaker', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []])->id];
        app(ConfirmPaidCapacity::class)->execute($this->owner, $this->event->id, ['expected_version' => 1, 'financial_revision_id' => $finance->id, 'resource_ids' => $units,
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Fixture origin and return reviewed.', 'transfers' => []]]);
        $this->travelTo(now()->addDays(27)); // the party is now in the last completed week
    }

    public function test_panel_shows_the_last_completed_week_with_unknown_costs_as_pending(): void
    {
        $this->assertTrue(ReportsSource::live());
        Livewire::test(Reports::class)->assertDontSee('Datos de ejemplo')
            ->assertSee('Semana del lun. 2 nov. al dom. 8 nov. 2026')
            ->assertSee('De 1 fiesta de este periodo')
            ->assertSee('Pendiente')->assertSee('Faltan los costos de 1 fiesta')
            ->assertSee('no se restan entre sí')->assertDontSee('Enviado');
    }

    public function test_panorama_counts_the_crm_party_by_its_date_and_the_deposit_by_its_payment_date(): void
    {
        $page = Livewire::test(Reports::class)->assertSee('Panorama')->assertSee('Pulso del dinero');
        $p = $page->instance()->panorama();
        $this->assertSame(1, $p['kpis']['parties']['value']);
        $this->assertSame(1, $p['pulse']['count'], 'The deposit is the first payment of the party.');
        $page->call('setWindow', 'custom')->set('rangeFrom', '2026-10-01')->set('rangeTo', '2026-10-31');
        $october = $page->instance()->panorama();
        $this->assertSame(0, $october['kpis']['parties']['value'], 'The party is on Nov 3.');
        $this->assertGreaterThan(0, $october['kpis']['received']['value'], 'The deposit arrived on Oct 14.');
        $page->call('setTab', 'viene')->assertSee('Dinero por cobrar')->assertSee('Ventana de reserva por temporada')->assertSee('Un año después');
    }

    public function test_navigation_never_goes_past_the_latest_completed_period(): void
    {
        Livewire::test(Reports::class)->call('shift', 1)->assertSee('Semana del lun. 2 nov. al dom. 8 nov. 2026')
            ->call('shift', -1)->assertSee('Semana del lun. 26 oct. al dom. 1 nov. 2026')->assertSee('La más reciente')
            ->call('latest')->assertSee('Semana del lun. 2 nov.')
            ->call('setKind', 'monthly')->assertSee('Octubre 2026')->assertDontSee('Fin de semana siguiente');
    }

    public function test_preparing_freezes_one_company_report_and_shows_the_unsent_email_preview(): void
    {
        Mail::fake();
        $page = Livewire::test(Reports::class)->call('prepare')->assertNotified('Reporte preparado, sin enviar')
            ->assertSee('Preparado, sin enviar')->assertSee('sales@waooparty.com')->assertSee('envío desactivado');
        $page->call('prepare');
        $this->assertSame(1, OwnerReportSnapshot::count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_inicio_shows_the_week_summary_and_links_to_reports(): void
    {
        Livewire::test(Dashboard::class)->assertSee('Semana del lun. 2 nov. al dom. 8 nov. 2026')->assertSee('entraron')->assertSee('Ver reportes');
    }

    public function test_analytics_is_owner_only_aggregate_and_csv_download_rechecks_flag(): void
    {
        config(['waoo.sandbox_analytics'=>true, 'waoo.sandbox_history'=>true]);
        $r = app(\App\Services\Reports\ReadBookingAnalytics::class)->execute($this->owner, ['year'=>2026]);
        $this->assertSame(1, $r['booking_count']);
        $this->assertSame(['crm'=>1], $r['source_counts']);
        $this->assertSame(12501, $r['ticket']['total_cents']);
        $this->assertStringNotContainsString('client@example.test', json_encode($r));
        Livewire::test(Reports::class)->assertSee('Reservas · CRM e historial HoneyBook')
            ->call('exportAnalytics')->assertFileDownloaded('reservas-2026.csv');
        config(['waoo.sandbox_analytics'=>false]);
        try {
            app(\App\Services\Reports\ReadBookingAnalytics::class)->execute($this->owner, ['year'=>2026]);
            $this->fail('Disabled analytics must reject direct calls');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertTrue(true);
        }
        config(['waoo.sandbox_analytics'=>true]);
        $this->owner->forceFill(['role'=>'staff'])->save();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(\App\Services\Reports\ReadBookingAnalytics::class)->execute($this->owner, ['year'=>2026]);
    }

    public function test_hidden_without_the_finance_sandbox(): void
    {
        config(['waoo.sandbox_finance' => false]);
        $this->get('/admin/reportes')->assertForbidden();
    }
}
