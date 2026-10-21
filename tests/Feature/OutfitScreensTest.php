<?php
namespace Tests\Feature;

use App\Filament\Resources\Characters\Pages\{CreateCharacter, EditCharacter};
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Resources\Pages\CreateResource;
use App\Models\{Character, Customer, Event, PriceVersion, Resource, ServiceVariant, User};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Claude's OUTFIT-01 screens over Codex's services: versions on the character, version on a costume unit,
// optional exact version + private note per character row of an event; single-version characters unchanged.
class OutfitScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]);
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_character_versions_and_costume_unit_version(): void
    {
        Livewire::test(CreateCharacter::class)->fillForm(['name' => 'Mickey', 'active' => true, 'outfits' => ['Clásico', ' Disneyland ', 'disneyland']])->call('create')->assertHasNoFormErrors();
        $mickey = Character::sole();
        $this->assertSame(['Clásico', 'Disneyland'], $mickey->outfits);
        Livewire::test(CreateResource::class)->fillForm(['kind' => 'costume', 'status' => 'active', 'name' => 'Mickey Disneyland 1', 'code' => 'TRJ-MD-1', 'character_id' => $mickey->id, 'outfit' => 'Disneyland'])
            ->assertFormFieldIsVisible('outfit')->call('create')->assertHasNoFormErrors();
        $this->assertSame('Disneyland', Resource::where('code', 'TRJ-MD-1')->value('outfit'));
        Livewire::test(CreateCharacter::class)->fillForm(['name' => 'Robot', 'active' => true, 'outfits' => ['0', ' ', 'Alto']])->call('create')->assertHasNoFormErrors();
        $this->assertSame(['0', 'Alto'], Character::where('name', 'Robot')->value('outfits'), 'A version named "0" is kept.');
        Livewire::test(EditCharacter::class, ['record' => $mickey->id])->fillForm(['outfits' => ['Clásico']])->call('save')->assertNotified();
        $this->assertSame(['Clásico', 'Disneyland'], $mickey->fresh()->outfits, 'A version used by an active costume cannot be removed.');
    }

    public function test_event_row_takes_exact_version_and_private_note(): void
    {
        $mickey = Character::create(['name' => 'Mickey', 'active' => true, 'outfits' => ['Clásico', 'Disneyland']]);
        $minnie = Character::create(['name' => 'Minnie', 'active' => true]);
        $req = fn ($c) => ['character_id' => $c->id, 'requirements' => [['kind' => 'costume', 'quantity' => 1, 'character_id' => $c->id], ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]];
        $variant = ServiceVariant::create(['name' => 'Mágico', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [], 'character_quantity' => 1, 'character_options' => [$req($mickey), $req($minnie)]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 37500, 'currency' => 'USD', 'created_by' => User::sole()->id]);
        $client = Customer::create(['name' => 'Claudia Martínez', 'locale' => 'es']);

        $page = Livewire::test(CreateEvent::class)->fillForm(['customer_id' => $client->id, 'price_version_ids' => [$price->id], 'date' => '2026-12-19', 'start_time' => '15:00', 'end_time' => '16:00',
            'characters' => [['price_version_id' => (string) $price->id, 'character_id' => (string) $mickey->id, 'quantity' => 1, 'outfit' => 'Disneyland', 'note' => 'sin orejas grandes']]]);
        $page->call('create')->assertHasNoFormErrors();
        $row = Event::sole()->services()->first()->characters()->first();
        $this->assertSame(['Disneyland', 'sin orejas grandes'], [$row->outfit, $row->note]);
        $this->get('/admin/eventos/'.Event::sole()->id)->assertSee('1 × Mickey (Disneyland) — nota: sin orejas grandes');
        $this->assertSame([], \App\Filament\Resources\Events\EventResource::outfitOptions($minnie->id), 'Single-version characters show no selector.');
        // Owner 2026-10-20: the costume version is in the event details above, never in the title.
        $title = \App\Filament\Resources\Events\EventResource::suggestTitle([$price->id], [['character_id' => $mickey->id, 'quantity' => 1, 'outfit' => 'Disneyland']], '2026-12-19', 'Claudia');
        $this->assertStringContainsString('Mickey 12/19', $title);
        $this->assertStringNotContainsString('Disneyland', $title);
    }
}
