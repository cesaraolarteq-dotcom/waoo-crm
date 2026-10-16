<?php
namespace Tests\Feature;

use App\Models\{Character, PriceVersion, Resource, ServiceVariant, User};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

// The owner's catalog export becomes CRM records through the audited services: exact prices, crew rules
// (head character = a host dresses, wig = one more person, Waoo face painter is one of the team), re-runnable.
class ImportOwnerCatalogTest extends TestCase
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

    public function test_imports_exact_prices_and_crew_rules_and_is_rerunnable(): void
    {
        $this->artisan('waoo:import-catalog', ['file' => $this->file, '--actor' => 'owner@example.test', '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, ServiceVariant::count(), 'Dry run writes nothing.');

        $this->artisan('waoo:import-catalog', ['file' => $this->file, '--actor' => 'owner@example.test'])->assertSuccessful();
        $this->assertSame(['Mickey', 'Rumi'], Character::orderBy('name')->pluck('name')->all());
        $this->assertSame(3, Resource::where('kind', 'costume')->count());
        $this->assertSame(['host', 'face_painter', 'performer'], Resource::where('code', 'STAFF-ANA-FICTICIA')->value('skills'));

        $star = ServiceVariant::where('name', 'Estrella (Star)')->sole();
        $this->assertSame('active', $star->status);
        $this->assertSame([['kind' => 'staff', 'quantity' => 1, 'skill' => 'host']], $star->requirements);
        $this->assertNull($star->long_description, 'Spanish text never becomes the English contract description.');
        $this->assertSame(27500, PriceVersion::where('service_variant_id', $star->id)->sole()->amount_cents);

        $waoo = ServiceVariant::where('name', 'Waoo')->sole();
        $this->assertSame([['kind' => 'staff', 'quantity' => 1, 'skill' => 'entertainer'], ['kind' => 'staff', 'quantity' => 1, 'skill' => 'face_painter']], $waoo->requirements);
        $rumi = Character::where('name', 'Rumi')->value('id');
        $byChar = collect($waoo->character_options)->keyBy('character_id');
        $this->assertCount(2, $byChar[Character::where('name', 'Mickey')->value('id')]['requirements'], 'Head character: a host dresses (3 people).');
        $this->assertCount(3, $byChar[$rumi]['requirements'], 'Wig character: one more person (4 people).');
        $this->assertStringStartsWith('This three-hour', $waoo->long_description);

        $robot = ServiceVariant::where('name', 'Pistola de humo + Robot alto')->sole();
        $this->assertSame('draft', $robot->status);
        $this->assertSame(0, $robot->character_quantity);
        $this->assertSame([['kind' => 'staff', 'quantity' => 2, 'skill' => 'operator']], $robot->requirements);

        // Re-run: nothing duplicated, no new price version when the price did not change.
        $this->artisan('waoo:import-catalog', ['file' => $this->file, '--actor' => 'owner@example.test'])->assertSuccessful();
        $this->assertSame(3, ServiceVariant::count());
        $this->assertSame(3, PriceVersion::count());
        $this->assertSame(3, Resource::where('kind', 'costume')->count());
    }

    public function test_requires_an_existing_owner(): void
    {
        $this->artisan('waoo:import-catalog', ['file' => $this->file, '--actor' => 'nobody@example.test'])->assertFailed();
        $this->assertSame(0, ServiceVariant::count());
    }
}
