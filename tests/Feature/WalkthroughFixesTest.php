<?php
namespace Tests\Feature;

use App\Filament\Resources\Variants\ServiceVariantResource;
use App\Filament\Support\Labels;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

// Fixes from César's crm-test walkthrough (2026-10-20).
class WalkthroughFixesTest extends TestCase
{
    use DatabaseMigrations;

    public function test_stylesheets_carry_a_version_so_an_old_cached_copy_is_not_used_after_a_deploy(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#css/waoo-auth\.css\?v=\d+#', $html);
        $this->assertMatchesRegularExpression('#css/waoo-tokens\.css\?v=\d+#', $html);
        $this->assertStringContainsString('data-pw-toggle', $html);
    }

    public function test_catalog_needs_read_in_spanish_including_the_cloud_machine(): void
    {
        $this->assertSame('1 operador/a · 3 facilitadora de spa · 1 máquina de baile en las nubes', ServiceVariantResource::needs([
            ['kind' => 'staff', 'quantity' => 1, 'skill' => 'operator'], ['kind' => 'staff', 'quantity' => 3, 'skill' => 'spa'],
            ['kind' => 'equipment', 'quantity' => 1, 'equipment_kind' => 'cloud_machine']]));
        $this->assertContains('cloud_machine', \App\Console\Commands\ImportOwnerCatalog::EQUIPMENT);
        $this->assertSame('Personaje con peluca', Labels::SKILL['performer_wig']);
    }

    public function test_retired_units_are_hidden_from_staff_and_costumes_by_default(): void
    {
        $owner = \App\Models\User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $rumi = \App\Models\Character::create(['name' => 'Rumi', 'active' => true]);
        foreach ([['TRAJE-RUMI-1', 'inactive'], ['TRAJE-RUMI-GOLDEN-1', 'active'], ['TRAJE-RUMI-HOW-1', 'maintenance']] as [$code, $status]) {
            \App\Models\Resource::create(['code' => $code, 'name' => "Traje {$code}", 'kind' => 'costume', 'status' => $status, 'character_id' => $rumi->id, 'skills' => []]);
        }
        $this->actingAs($owner)->get('/admin/staff-y-trajes')->assertOk()
            ->assertSee('TRAJE-RUMI-GOLDEN-1')->assertSee('TRAJE-RUMI-HOW-1')->assertDontSee('TRAJE-RUMI-1');
    }
}
