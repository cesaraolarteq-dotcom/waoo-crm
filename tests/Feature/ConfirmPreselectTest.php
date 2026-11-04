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

    public function test_held_costume_wins_and_page_starts_in_staff_later_mode(): void
    {
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true]);
        $mickey = Character::create(['name' => 'Mickey', 'active' => true]);
        Resource::create(['code' => 'MK-1', 'name' => 'Mickey 1', 'kind' => 'costume', 'status' => 'active', 'character_id' => $mickey->id, 'skills' => []]);
        $held = Resource::create(['code' => 'MK-2', 'name' => 'Mickey 2', 'kind' => 'costume', 'status' => 'active', 'character_id' => $mickey->id, 'skills' => []]);
        $variant = ServiceVariant::create(['name' => 'Fiesta Mágica', 'status' => 'active', 'duration_minutes' => 60,
            'requirements' => [['kind' => 'costume', 'character_id' => $mickey->id, 'quantity' => 1]]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 40000, 'currency' => 'USD', 'created_by' => $owner->id]);
        $c = Customer::create(['name' => 'Laura Jiménez', 'locale' => 'es']);
        $s = now()->addDays(20)->setTime(14, 0);
        $e = Event::create(['customer_id' => $c->id, 'title' => 'Cumple de Mateo', 'starts_at' => $s, 'ends_at' => $s->copy()->addHour(), 'timezone' => 'America/Chicago',
            'postal_code' => '77494', 'setup_minutes' => 15, 'breakdown_minutes' => 15, 'travel_fee_cents' => 0]);
        EventService::create(['event_id' => $e->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id,
            'requirements_snapshot' => $variant->requirements, 'duration_minutes' => 60, 'operational_ready' => true]);
        // Reserva rápida set Mickey 2 aside for this party: that unit is the one preselected, not the first by name.
        ResourceReservation::create(['event_id' => $e->id, 'resource_id' => $held->id, 'starts_at' => $e->starts_at, 'ends_at' => $e->ends_at]);
        $this->assertSame([$held->id], ConfirmCapacity::defaultCostumes($e));
        config(['waoo.record_forms' => true]);
        $this->actingAs($owner);
        \Livewire\Livewire::test(ConfirmCapacity::class, ['record' => $e->id])->assertSet('data.staff_later', true)->assertSet('data.units.costume', [$held->id]);
    }

    public function test_select_lists_are_not_cut_at_fifty(): void
    {
        $this->assertCount(96, \App\Filament\Support\TimeSelect::options());
        $this->assertGreaterThanOrEqual(96, \App\Filament\Support\TimeSelect::make('start_time')->getOptionsLimit());
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
