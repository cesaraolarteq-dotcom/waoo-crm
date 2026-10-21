<?php
namespace Tests\Feature;

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Resources\Pages\{CreateResource, EditResource};
use App\Filament\Resources\Variants\Pages\{CreateServiceVariant, EditServiceVariant};
use App\Models\{Character, Customer, Resource, ServiceVariant, User};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

// Forms against the agreed service contract, using recording fakes. Proves: forms write only through
// App\Services\Records\*, pass the signed-in actor and the displayed version, and surface service errors.
class RecordFormsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** Binds a fake service that records its calls and runs $behavior. */
    private function fake(string $service, \Closure $behavior): object
    {
        $fake = new class($behavior) {
            public array $calls = [];
            public function __construct(private \Closure $behavior) {}
            public function execute(...$args) { $this->calls[] = $args; return ($this->behavior)(...$args); }
        };
        $this->app->instance("App\\Services\\Records\\{$service}", $fake);

        return $fake;
    }

    public function test_forms_can_be_switched_off_and_then_answer_403(): void
    {
        config(['waoo.record_forms' => false]);
        foreach (['clientas', 'personajes', 'staff-y-trajes', 'catalogo'] as $slug) {
            $this->get("/admin/{$slug}")->assertOk()->assertDontSee("/admin/{$slug}/nuevo");
            $this->get("/admin/{$slug}/nuevo")->assertForbidden();
        }
        $customer = Customer::create(['name' => 'Fictitious Client', 'locale' => 'es']);
        $this->get("/admin/clientas/{$customer->id}/editar")->assertForbidden();
    }

    public function test_create_customer_goes_through_the_service_with_actor_and_clean_input(): void
    {
        $fake = $this->fake('SaveCustomer', fn ($actor, $input) => new Customer($input));
        $this->get('/admin/clientas')->assertSee('/admin/clientas/nuevo');

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => '  Fictitious Client  ', 'phone' => '   ', 'email' => 'client@example.test', 'locale' => 'en'])
            ->call('create')->assertHasNoFormErrors();

        $this->assertCount(1, $fake->calls);
        [$actor, $input] = $fake->calls[0];
        $this->assertTrue($actor->is($this->owner));
        $this->assertSame(['name' => 'Fictitious Client', 'email' => 'client@example.test', 'phone' => null, 'locale' => 'en'], $input);
        $this->assertSame(0, Customer::count(), 'Filament must not write the model itself');
    }

    public function test_service_validation_errors_show_on_the_fields(): void
    {
        $this->fake('SaveCustomer', fn () => throw ValidationException::withMessages(['email' => 'El correo no es válido.']));
        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Fictitious Client', 'email' => 'client@example.test', 'locale' => 'es'])
            ->call('create')->assertHasFormErrors(['email']);
    }

    public function test_business_rejection_is_shown_and_nothing_is_reported_as_saved(): void
    {
        $this->fake('SaveCustomer', fn () => throw new \App\Exceptions\RecordWriteRejected(409, 'La ficha cambió. Actualiza antes de guardar.'));
        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Fictitious Client', 'locale' => 'es'])
            ->call('create')->assertNotified('No se guardó')->assertNoRedirect();
    }

    public function test_resource_form_sends_only_fields_for_its_kind(): void
    {
        $hero = Character::create(['name' => 'Fictitious Hero', 'active' => true]);
        $fake = $this->fake('SaveResource', fn ($actor, $input) => new Resource($input));
        Livewire::test(CreateResource::class)
            ->fillForm(['kind' => 'costume', 'status' => 'active', 'name' => 'Hero #2', 'code' => 'TRJ-HERO-2', 'character_id' => $hero->id, 'location' => ''])
            ->call('create')->assertHasNoFormErrors();
        $input = $fake->calls[0][1];
        $this->assertSame('costume', $input['kind']);
        $this->assertSame([], $input['skills']);
        $this->assertSame($hero->id, (int) $input['character_id']);
        $this->assertNull($input['equipment_kind']);
        $this->assertNull($input['seats']);
        $this->assertNull($input['location']);
    }

    public function test_edit_sends_the_version_that_was_displayed_not_a_fresh_one(): void
    {
        $unit = Resource::create(['code' => 'STF-1', 'name' => 'Fictitious Person', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer']]);
        $fake = $this->fake('SaveResource', fn ($actor, $input, $id) => Resource::find($id));
        $page = Livewire::test(EditResource::class, ['record' => $unit->id]);
        Resource::whereKey($unit->id)->update(['version' => 2]); // someone else saved meanwhile
        $page->fillForm(['name' => 'Renamed'])->call('save')->assertHasNoFormErrors();
        [, $input, $id] = $fake->calls[0];
        $this->assertSame(1, $input['expected_version']);
        $this->assertSame('staff', $input['kind']);
        $this->assertSame($unit->id, $id);
        $this->assertSame('Fictitious Person', Resource::find($unit->id)->name, 'Filament must not write the model itself');
    }

    public function test_variant_requirements_are_normalized_for_the_contract(): void
    {
        $fake = $this->fake('SaveVariant', fn ($actor, $input) => new ServiceVariant($input));
        Livewire::test(CreateServiceVariant::class)
            ->fillForm(['name' => 'Fictitious Package', 'duration_minutes' => 60, 'status' => 'draft', 'requirements' => [
                ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer'],
                ['kind' => 'equipment', 'quantity' => 2, 'equipment_kind' => 'speaker'],
            ]])->call('create')->assertHasNoFormErrors();
        $this->assertSame([
            ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer'],
            ['kind' => 'equipment', 'quantity' => 2, 'equipment_kind' => 'speaker'],
        ], $fake->calls[0][1]['requirements']);
    }

    public function test_new_price_is_appended_in_exact_cents(): void
    {
        $this->assertSame(37500, EditServiceVariant::toCents('375'));
        $this->assertSame(37550, EditServiceVariant::toCents('375.5'));
        $this->assertSame(5, EditServiceVariant::toCents('0.05'));

        $variant = ServiceVariant::create(['name' => 'Fictitious Package', 'status' => 'draft', 'duration_minutes' => 60, 'requirements' => []]);
        $this->fake('SaveVariant', fn ($actor, $input, $id) => ServiceVariant::find($id));
        $prices = $this->fake('AddPriceVersion', fn () => new \App\Models\PriceVersion);
        Livewire::test(EditServiceVariant::class, ['record' => $variant->id])
            ->callAction('newPrice', ['amount' => '123.45'])->assertNotified('Precio guardado');
        [$actor, $variantId, $input] = $prices->calls[0];
        $this->assertTrue($actor->is($this->owner));
        $this->assertSame($variant->id, $variantId);
        $this->assertSame(['amount_cents' => 12345, 'currency' => 'USD'], $input);
    }
}
