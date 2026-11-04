<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\ConfirmCapacity;
use App\Models\{Character, Customer, Event, EventService, PriceVersion, Resource, ResourceBlock, ResourceReservation, ServiceVariant, User};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

class CostumePreselectionSafetyTest extends TestCase
{
    use DatabaseMigrations;
    private Event $event;
    private Character $character;
    private Resource $first;
    private Resource $second;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($owner);
        $this->character = Character::create(['name' => 'Minnie Mouse', 'active' => true, 'outfits' => ['Rosa', 'Azul']]);
        $v = ServiceVariant::create(['name' => 'Fiesta Mágica', 'status' => 'active', 'duration_minutes' => 60,
            'requirements' => [['kind' => 'costume', 'character_id' => $this->character->id, 'outfit' => 'Rosa', 'quantity' => 1]]]);
        $price = PriceVersion::create(['service_variant_id' => $v->id, 'amount_cents' => 40000, 'currency' => 'USD', 'created_by' => $owner->id]);
        $customer = Customer::create(['name' => 'María González', 'locale' => 'es']);
        $s = now()->addDays(20)->startOfHour();
        $this->event = Event::create(['customer_id' => $customer->id, 'title' => 'Cumpleaños de Sofía', 'starts_at' => $s, 'ends_at' => $s->copy()->addHour(),
            'timezone' => 'America/Chicago', 'setup_minutes' => 15, 'breakdown_minutes' => 15, 'travel_fee_cents' => 0]);
        EventService::create(['event_id' => $this->event->id, 'service_variant_id' => $v->id, 'price_version_id' => $price->id, 'duration_minutes' => 60,
            'operational_ready' => true, 'requirements_snapshot' => [['kind' => 'costume', 'character_id' => $this->character->id, 'outfit' => 'Rosa', 'quantity' => 1]]]);
        $this->first = Resource::create(['code' => 'MIN-1', 'name' => 'Minnie A', 'kind' => 'costume', 'status' => 'active', 'character_id' => $this->character->id, 'outfit' => 'Rosa']);
        $this->second = Resource::create(['code' => 'MIN-2', 'name' => 'Minnie Z', 'kind' => 'costume', 'status' => 'active', 'character_id' => $this->character->id, 'outfit' => 'Rosa']);
    }

    public function test_suggestion_respects_the_requested_outfit(): void
    {
        $this->first->update(['outfit' => 'Azul']);
        $this->assertSame([$this->second->id], ConfirmCapacity::defaultCostumes($this->event));
    }

    public function test_suggestion_excludes_active_blocks(): void
    {
        ResourceBlock::create(['resource_id' => $this->first->id, 'starts_at' => $this->event->starts_at, 'ends_at' => $this->event->ends_at, 'reason' => 'Mantenimiento']);
        $this->assertSame([$this->second->id], ConfirmCapacity::defaultCostumes($this->event));
    }

    public function test_suggestion_counts_neighbor_breakdown_and_candidate_setup(): void
    {
        $other = $this->event->replicate(); $other->starts_at = $this->event->starts_at->subHours(2); $other->ends_at = $this->event->starts_at->subMinutes(5); $other->save();
        ResourceReservation::create(['event_id' => $other->id, 'resource_id' => $this->first->id, 'starts_at' => $other->starts_at, 'ends_at' => $other->ends_at]);
        $this->assertSame([$this->second->id], ConfirmCapacity::defaultCostumes($this->event));
    }

    public function test_the_existing_own_hold_is_preferred_over_a_different_free_unit(): void
    {
        ResourceReservation::create(['event_id' => $this->event->id, 'resource_id' => $this->second->id, 'starts_at' => $this->event->starts_at, 'ends_at' => $this->event->ends_at]);
        $this->assertSame([$this->second->id], ConfirmCapacity::defaultCostumes($this->event));
    }

    public function test_the_generic_unit_gets_the_agreed_wardrobe_in_the_form(): void
    {
        $this->first->update(['outfit' => null]); $this->second->update(['status' => 'inactive']);
        Livewire::test(ConfirmCapacity::class, ['record' => $this->event->id])
            ->assertSet('data.units.costume', [$this->first->id])
            ->assertSet('data.wardrobe', fn ($rows) => count($rows) === 1 && array_values($rows)[0]['outfit'] === 'Rosa');
    }

    public function test_confirmation_defaults_to_planning_staff_the_week_of_the_party(): void
    {
        Livewire::test(ConfirmCapacity::class, ['record' => $this->event->id])->assertSet('data.staff_later', true);
    }
}
