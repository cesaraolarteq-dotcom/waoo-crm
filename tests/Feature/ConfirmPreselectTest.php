<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\ConfirmCapacity;
use App\Models\{Character, Customer, Event, EventService, PriceVersion, Resource, ResourceReservation, ServiceVariant, User};
use App\Services\MatchEventResources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Owner 2026-11-04: the package's characters come preselected; a failed match says what is missing. */
class ConfirmPreselectTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_free_costume_per_character_is_preselected(): void
    {
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true]);
        $mickey = Character::create(['name' => 'Mickey', 'active' => true]);
        $busy = Resource::create(['code' => 'MK-1', 'name' => 'Mickey 1', 'kind' => 'costume', 'status' => 'active', 'character_id' => $mickey->id, 'skills' => []]);
        $free = Resource::create(['code' => 'MK-2', 'name' => 'Mickey 2', 'kind' => 'costume', 'status' => 'active', 'character_id' => $mickey->id, 'skills' => []]);
        $variant = ServiceVariant::create(['name' => 'Fiesta Mágica', 'status' => 'active', 'duration_minutes' => 60,
            'requirements' => [['kind' => 'costume', 'character_id' => $mickey->id, 'quantity' => 1], ['kind' => 'staff', 'skill' => 'performer', 'quantity' => 1]]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 40000, 'currency' => 'USD', 'created_by' => $owner->id]);
        $party = function () use ($variant, $price) {
            $c = Customer::create(['name' => 'María González', 'locale' => 'es']);
            $s = now()->addDays(20)->setTime(14, 0);
            $e = Event::create(['customer_id' => $c->id, 'title' => 'Cumple de Sofía', 'starts_at' => $s, 'ends_at' => $s->copy()->addHour(), 'timezone' => 'America/Chicago',
                'address' => '123 Oak Street', 'setup_minutes' => 15, 'breakdown_minutes' => 15, 'travel_fee_cents' => 0]);
            EventService::create(['event_id' => $e->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id,
                'requirements_snapshot' => $variant->requirements, 'duration_minutes' => 60, 'operational_ready' => true]);
            return $e;
        };
        $other = $party();
        ResourceReservation::create(['event_id' => $other->id, 'resource_id' => $busy->id, 'starts_at' => $other->starts_at, 'ends_at' => $other->ends_at]);
        $this->assertSame([$free->id], ConfirmCapacity::defaultCostumes($party()));
    }

    public function test_failed_match_names_the_missing_roles(): void
    {
        $mickey = Character::create(['name' => 'Mickey', 'active' => true]);
        try {
            app(MatchEventResources::class)->execute([['kind' => 'costume', 'character_id' => $mickey->id], ['kind' => 'equipment', 'equipment_kind' => 'speaker']], [], fn () => false);
            $this->fail('Empty team matched.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Falta: Traje de Mickey, Bocina.', $e->errors()['booking'][0]);
        }
    }
}
