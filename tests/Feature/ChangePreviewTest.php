<?php
namespace Tests\Feature;

use App\Filament\Pages\ChangePreview;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

// CHANGE-A design preview (local/testing only): reschedule side by side + money difference with waivable $100 fee,
// cancel outcome from contract rules; every action disabled.
class ChangePreviewTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]));
    }

    public function test_reschedule_shows_checks_and_fee_that_can_be_waived(): void
    {
        $html = $this->get('/admin/vista-previa-cambios')->assertOk()->assertSee('Hoy → Propuesta')->assertSee('no se libera hasta que el cliente acepta')
            ->assertSee('Cargo por cambio de fecha')->assertSee('$715')->getContent();
        $this->assertSame(substr_count($html, '<button type="button" class="wcal-btn on" disabled'), substr_count($html, 'Preparar adenda'));
        Livewire::test(ChangePreview::class)->set('waive', true)->assertSee('$615')->assertSee('Motivo (obligatorio)');
    }

    public function test_cancel_shows_contract_outcome_and_keeps_history(): void
    {
        Livewire::test(ChangePreview::class)->call('setMode', 'cancel')->assertSee('El depósito no es reembolsable')
            ->assertSee('No se devuelve nada automáticamente')->assertSee('Se conservan el contrato firmado');
    }

    public function test_closed_outside_local_and_testing(): void
    {
        $this->app['env'] = 'staging';
        $this->get('/admin/vista-previa-cambios')->assertForbidden();
    }
}
