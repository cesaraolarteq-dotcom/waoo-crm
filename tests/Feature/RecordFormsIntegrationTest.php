<?php

namespace Tests\Feature;

use App\Filament\Resources\Characters\Pages\CreateCharacter;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Resources\Pages\{CreateResource, EditResource};
use App\Filament\Resources\Variants\Pages\{CreateServiceVariant, EditServiceVariant};
use App\Models\{Character, Customer, PriceVersion, Resource, ServiceVariant, User};
use App\Services\Records\{SaveResource, SaveVariant};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

// Real services, persistence and audit: complements Claude's recording-fake adapter tests.
class RecordFormsIntegrationTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function unit(): array
    {
        return ['code' => 'FIXTURE', 'name' => 'Fixture staff', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer']];
    }

    public function test_forms_enable_when_real_services_exist_and_the_off_switch_still_works(): void
    {
        foreach (['clientas', 'personajes', 'staff-y-trajes', 'catalogo'] as $slug) {
            $this->get("/admin/{$slug}/nuevo")->assertOk();
        }
        config(['waoo.record_forms' => false]);
        foreach (['clientas', 'personajes', 'staff-y-trajes', 'catalogo'] as $slug) {
            $this->get("/admin/{$slug}/nuevo")->assertForbidden();
        }
    }

    public function test_customer_and_character_forms_persist_once_and_audit_the_owner(): void
    {
        Livewire::test(CreateCustomer::class)->fillForm(['name' => '  Fixture client  ', 'locale' => 'es', 'phone' => ' '])
            ->call('create')->assertHasNoFormErrors()->assertRedirect('/admin/clientas');
        Livewire::test(CreateCharacter::class)->fillForm(['name' => 'Fixture character', 'active' => true])
            ->call('create')->assertHasNoFormErrors()->assertRedirect('/admin/personajes');
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('characters', 1);
        $this->assertSame('Fixture client', Customer::first()->name);
        $this->assertNull(Customer::first()->phone);
        $this->assertDatabaseHas('audit_entries', ['actor_id' => $this->owner->id, 'action' => 'customers.created']);
        $this->assertDatabaseHas('audit_entries', ['actor_id' => $this->owner->id, 'action' => 'characters.created']);
        $this->assertDatabaseCount('audit_entries', 2);
    }

    public function test_resource_form_creates_a_costume_with_only_one_audited_write(): void
    {
        $character = Character::create(['name' => 'Fixture character', 'active' => true]);
        Livewire::test(CreateResource::class)->fillForm(['name' => 'Fixture costume', 'code' => 'COSTUME',
            'kind' => 'costume', 'status' => 'active', 'character_id' => $character->id])
            ->call('create')->assertHasNoFormErrors()->assertRedirect('/admin/staff-y-trajes');
        $this->assertDatabaseCount('resources', 1);
        $this->assertSame($character->id, Resource::first()->character_id);
        $this->assertSame(1, Resource::first()->version);
        $this->assertDatabaseCount('audit_entries', 1);
        $this->assertDatabaseHas('audit_entries', ['actor_id' => $this->owner->id, 'action' => 'resources.created']);
    }

    public function test_resource_edit_uses_displayed_version_and_rejects_a_real_concurrent_save(): void
    {
        $unit = app(SaveResource::class)->execute($this->owner, $this->unit());
        $page = Livewire::test(EditResource::class, ['record' => $unit->id]);
        app(SaveResource::class)->execute($this->owner, [...$this->unit(), 'name' => 'Other owner edit', 'expected_version' => 1], $unit->id);
        $page->fillForm(['name' => 'Stale edit'])->call('save')->assertNotified('No se guardó')->assertNoRedirect();
        $this->assertSame('Other owner edit', $unit->fresh()->name);
        $this->assertSame(2, $unit->fresh()->version);
        $this->assertDatabaseCount('audit_entries', 2);
        Livewire::test(EditResource::class, ['record' => $unit->id])->fillForm(['name' => 'Reloaded edit'])
            ->call('save')->assertHasNoFormErrors()->assertRedirect('/admin/staff-y-trajes');
        $this->assertSame('Reloaded edit', $unit->fresh()->name);
        $this->assertSame(3, $unit->fresh()->version);
        $this->assertDatabaseCount('audit_entries', 3);
    }

    public function test_catalog_form_and_price_action_preserve_integer_cents_and_price_history(): void
    {
        Livewire::test(CreateServiceVariant::class)->fillForm(['name' => 'Fixture service', 'status' => 'draft', 'duration_minutes' => 60, 'requirements' => []])
            ->call('create')->assertHasNoFormErrors()->assertRedirect('/admin/catalogo');
        $variant = ServiceVariant::firstOrFail();
        $page = Livewire::test(EditServiceVariant::class, ['record' => $variant->id]);
        $page->callAction('newPrice', ['amount' => '123.45'])->assertNotified('Precio guardado');
        $first = PriceVersion::firstOrFail();
        $page->callAction('newPrice', ['amount' => '150.05'])->assertNotified('Precio guardado');
        $this->assertSame(12345, $first->fresh()->amount_cents);
        $this->assertSame(15005, PriceVersion::orderByDesc('id')->first()->amount_cents);
        $this->assertSame($this->owner->id, $first->created_by);
        $this->assertDatabaseCount('price_versions', 2);
        $this->assertDatabaseCount('audit_entries', 3);
    }

    public function test_real_catalog_rejection_keeps_form_and_database_unchanged(): void
    {
        $variant = app(SaveVariant::class)->execute($this->owner, ['name' => 'Fixture draft', 'status' => 'draft', 'duration_minutes' => 60, 'requirements' => []]);
        Livewire::test(EditServiceVariant::class, ['record' => $variant->id])->fillForm(['status' => 'active'])
            ->call('save')->assertNotified('No se guardó')->assertNoRedirect();
        $this->assertSame('draft', $variant->fresh()->status);
        $this->assertSame(1, $variant->fresh()->version);
        $this->assertDatabaseCount('audit_entries', 1);
    }
}
