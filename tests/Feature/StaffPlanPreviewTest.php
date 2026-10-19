<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

// Owner-side STAFF-01/STAFFPAY-01 design preview: local/testing only, labelled sample, actions disabled.
// Owner 2026-10-18: no invitations/answers; one fixed personal link per person.
class StaffPlanPreviewTest extends TestCase
{
    use DatabaseMigrations;

    public function test_preview_shows_team_fixed_links_driver_rule_and_pay_statement_with_disabled_actions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]));
        $html = $this->get('/admin/vista-previa-staff')->assertOk()->assertSee('Vista previa de diseño')->assertSee('Enlaces del staff')->assertSee('un solo enlace que no cambia')->assertDontSee('Sin responder')->assertDontSee('Invitar')
            ->assertSee('Maneja')->assertSee('al menos una persona que maneje')->assertSee('Pagos al staff')->assertSee('Falta la tarifa')->getContent();
        $this->assertSame(substr_count($html, '<button type="button" class="wcal-btn'), substr_count($html, 'disabled>'), 'every preview action is disabled');
    }

    public function test_preview_is_closed_outside_local_and_testing(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]));
        $this->app['env'] = 'staging';
        $this->get('/admin/vista-previa-staff')->assertForbidden();
    }
}
