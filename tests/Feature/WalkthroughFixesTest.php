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
        $this->assertSame('Personaje de peluca', Labels::SKILL['performer_wig']);
    }
}
