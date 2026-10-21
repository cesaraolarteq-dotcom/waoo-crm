<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\{CreateEvent, EditEvent};
use App\Filament\Resources\Resources\Pages\EditResource;
use App\Filament\Resources\Variants\Pages\CreateServiceVariant;
use App\Models\{Character, Customer, Event, PriceVersion, Resource, ServiceVariant, User};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

// Forms for Codex's record extensions (event type, characters per service, catalog character recipes, unlink).
class CharacterAndTypeFormsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Character $hero;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->hero = Character::create(['name' => 'Fictitious Hero', 'active' => true]);
    }

    private function configurableVariant(): PriceVersion
    {
        Livewire::test(CreateServiceVariant::class)->fillForm([
            'name' => 'Fictitious Character Package', 'duration_minutes' => 60, 'status' => 'active',
            'requirements' => [['kind' => 'equipment', 'quantity' => 1, 'equipment_kind' => 'speaker']],
            'character_quantity' => 1,
            'character_options' => [['character_id' => $this->hero->id, 'requirements' => [
                ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer'], ['kind' => 'costume', 'quantity' => 1, 'character_id' => $this->hero->id]]]],
        ])->call('create')->assertHasNoFormErrors();
        $variant = ServiceVariant::sole();

        return PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
    }

    public function test_catalog_saves_included_characters_and_their_recipe(): void
    {
        $this->configurableVariant();
        $variant = ServiceVariant::sole();
        $this->assertSame(1, $variant->character_quantity);
        $this->assertSame($this->hero->id, (int) $variant->character_options[0]['character_id']);
        $this->assertSame(['kind' => 'costume', 'quantity' => 1, 'character_id' => $this->hero->id], $variant->character_options[0]['requirements'][1]);
    }

    public function test_event_type_and_chosen_character_compile_into_the_snapshot(): void
    {
        $price = $this->configurableVariant();
        $customer = Customer::create(['name' => 'Fictitious Client', 'locale' => 'es']);
        Livewire::test(CreateEvent::class)->fillForm([
            'customer_id' => $customer->id, 'title' => 'Fiesta', 'event_type' => 'birthday', 'date' => '2026-12-19', 'start_time' => '15:00', 'end_time' => '16:00',
            'price_version_ids' => [$price->id], 'characters' => [['price_version_id' => (string) $price->id, 'character_id' => (string) $this->hero->id, 'quantity' => 1]],
        ])->call('create')->assertHasNoFormErrors();
        $event = Event::sole();
        $this->assertSame('birthday', $event->event_type);
        $service = $event->services()->with('characters')->sole();
        $this->assertTrue($service->operational_ready);
        $this->assertSame([$this->hero->id], $service->characters->pluck('character_id')->all());
        $this->assertContains(['kind' => 'costume', 'quantity' => 1, 'character_id' => $this->hero->id], $service->requirements_snapshot);

        Livewire::test(EditEvent::class, ['record' => $event->id])->assertSchemaStateSet(['event_type' => 'birthday'])
            ->fillForm(['characters' => []])->call('save')->assertHasNoFormErrors();
        $this->assertFalse($event->fresh()->services()->sole()->operational_ready, 'Missing character keeps the service incomplete.');
    }

    public function test_costume_can_be_unlinked_with_a_reason(): void
    {
        $costume = Resource::create(['code' => 'TRJ-1', 'name' => 'Hero #1', 'kind' => 'costume', 'status' => 'active', 'character_id' => $this->hero->id, 'skills' => []]);
        $performer = Resource::create(['code' => 'STF-1', 'name' => 'Fictitious Performer', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer']]);
        Livewire::test(EditResource::class, ['record' => $costume->id])->callAction('compatibility', ['staff_id' => $performer->id]);
        Livewire::test(EditResource::class, ['record' => $costume->id])->callAction('unlink', ['staff_id' => $performer->id, 'reason' => 'corto'])->assertHasActionErrors(['reason']);
        Livewire::test(EditResource::class, ['record' => $costume->id])
            ->callAction('unlink', ['staff_id' => $performer->id, 'reason' => 'Ya no trabaja con nosotros.'])->assertNotified('Persona quitada del traje');
        $this->assertFalse(DB::table('costume_compatibilities')->where(['staff_id' => $performer->id, 'costume_id' => $costume->id])->exists());
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'costume.compatibility_removed')->count());
    }
}
