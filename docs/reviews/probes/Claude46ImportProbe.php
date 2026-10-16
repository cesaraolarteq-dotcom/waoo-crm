<?php
namespace Tests\Review;

use App\Models\{Character, PriceVersion, Resource, ServiceVariant, User};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

// The owner's catalog export becomes CRM records through the audited services: exact prices, crew rules
// (head character = a host dresses, wig = one more person, Waoo face painter is one of the team), re-runnable.
class Claude46ImportProbe extends TestCase
{
    use DatabaseMigrations;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['email' => 'owner@example.test', 'role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->file = tempnam(sys_get_temp_dir(), 'cat');
        file_put_contents($this->file, json_encode([
            'characters' => ['mickey' => ['name' => 'Mickey', 'costumes' => 2, 'wig' => false], 'rumi' => ['name' => 'Rumi', 'costumes' => 1, 'wig' => true]],
            'staff' => ['s1' => ['name' => 'Ana Ficticia', 'roles' => ['host', 'face_painter', 'character'], 'rate' => 25]],
            'packages' => [
                'star' => ['name' => 'Estrella (Star)', 'group' => 'Paquetes con personaje', 'price' => 275, 'duration_min' => 30, 'characters_included' => 1,
                    'crew' => ['character' => 1, 'host' => 1, 'driver' => 0], 'gear' => ['bubbles' => 1], 'description' => 'Entrada y bienvenida', 'ready' => true],
                'waoo' => ['name' => 'Waoo', 'group' => 'Paquetes con personaje', 'price' => 800, 'duration_min' => 180, 'characters_included' => 1,
                    'crew' => ['character' => 0, 'entertainer' => 1, 'host' => 2], 'gear' => ['face_kit' => 1, 'speaker' => 1],
                    'description' => 'This three-hour experience starts with face painting and the games, then the character and the show for each guest.', 'ready' => true],
                'robot' => ['name' => 'Pistola de humo + Robot alto', 'group' => 'Hora loca', 'price' => 700, 'duration_min' => 60, 'characters_included' => 1,
                    'crew' => ['character' => 1, 'operator' => 1], 'gear' => ['robot_tall' => 1], 'description' => '', 'ready' => false],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_review_import_activates_package_without_declared_speaker_requirement(): void
    {
        $this->artisan('waoo:import-catalog', ['file' => $this->file, '--actor' => 'owner@example.test'])->assertSuccessful();
        $variant = ServiceVariant::where('name', 'Waoo')->sole();
        $this->assertSame('active', $variant->status);
        $this->assertFalse(collect($variant->requirements)->contains(fn ($r) => ($r['equipment_kind'] ?? null) === 'speaker'), 'REPRODUCED: declared speaker not reserved by an active recipe.');
    }
    public function test_review_identical_reimport_changes_versions_and_audit(): void
    {
        $args = ['file' => $this->file, '--actor' => 'owner@example.test'];
        $this->artisan('waoo:import-catalog', $args)->assertSuccessful();
        $version = ServiceVariant::where('name', 'Waoo')->value('version');
        $this->artisan('waoo:import-catalog', $args)->assertSuccessful();
        $this->assertGreaterThan($version, ServiceVariant::where('name', 'Waoo')->value('version'));
    }
}
