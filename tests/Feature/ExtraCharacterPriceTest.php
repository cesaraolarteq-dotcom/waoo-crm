<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\EventResource;
use App\Models\{Character, PriceVersion, ServiceVariant, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Owner 2026-11-03: Personajes sin show charges $200 per additional character; other packages keep the general $150. */
class ExtraCharacterPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_package_can_have_its_own_price_per_additional_character(): void
    {
        $owner = User::factory()->create(['role' => 'owner_admin']);
        $elsa = Character::create(['name' => 'Elsa', 'active' => true]); $anna = Character::create(['name' => 'Anna', 'active' => true]);
        $opts = [['character_id' => $elsa->id, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]], ['character_id' => $anna->id, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]];
        $show = ServiceVariant::create(['name' => 'Personajes sin show', 'status' => 'active', 'duration_minutes' => 45, 'requirements' => [], 'character_quantity' => 1, 'character_options' => $opts, 'extra_character_cents' => 20000]);
        $magic = ServiceVariant::create(['name' => 'Mágico', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [], 'character_quantity' => 1, 'character_options' => $opts]);
        $p1 = PriceVersion::create(['service_variant_id' => $show->id, 'amount_cents' => 25000, 'currency' => 'USD', 'created_by' => $owner->id]);
        $p2 = PriceVersion::create(['service_variant_id' => $magic->id, 'amount_cents' => 37500, 'currency' => 'USD', 'created_by' => $owner->id]);
        $two = [['character_id' => $elsa->id, 'quantity' => 1], ['character_id' => $anna->id, 'quantity' => 1]];
        $this->assertSame(20000, EventResource::extraCharacterCents(['price_version_ids' => [$p1->id], 'characters' => $two]));
        $this->assertSame(15000, EventResource::extraCharacterCents(['price_version_ids' => [$p2->id], 'characters' => $two]));
        $this->assertSame(15000, ServiceVariant::extraCharacterPrice($magic));
    }
}
