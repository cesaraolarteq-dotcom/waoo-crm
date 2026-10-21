<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Phase 8 switch-over design preview (local/testing only): aggregates only, three steps, all actions disabled.
class CutoverPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_shows_aggregates_steps_and_disabled_actions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]));
        $html = $this->get('/admin/vista-previa-cambio-final')->assertOk()->assertSee('HoneyBook cobra y avisa estas fiestas')
            ->assertSee('Paso 1')->assertSee('Paso 2')->assertSee('Paso 3')->assertSee('Todavía no se puede pasar')
            ->assertSee('nada atrasado')->assertSee('Se puede deshacer solo si')->getContent();
        $this->assertSame(substr_count($html, '<button type="button" class="wcal-btn'), substr_count($html, ' disabled>'));
    }

    public function test_closed_outside_local_and_testing(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]));
        $this->app['env'] = 'staging';
        $this->get('/admin/vista-previa-cambio-final')->assertForbidden();
    }
}
