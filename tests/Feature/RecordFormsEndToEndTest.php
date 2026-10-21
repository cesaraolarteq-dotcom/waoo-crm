<?php
namespace Tests\Feature;

use App\Filament\Resources\Customers\Pages\{CreateCustomer, EditCustomer};
use App\Filament\Resources\Resources\Pages\EditResource;
use App\Filament\Resources\Variants\Pages\{CreateServiceVariant, EditServiceVariant};
use App\Models\{Customer, PriceVersion, Resource, ServiceVariant, User};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

// Forms wired to Codex's real audited services (no fakes): rows and audit entries come from the services.
class RecordFormsEndToEndTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_create_and_edit_customer_are_saved_and_audited_with_the_signed_in_owner(): void
    {
        $this->get('/admin/clientas')->assertSee('Nuevo cliente');
        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => '  Fictitious Client ', 'phone' => '', 'email' => 'client@example.test', 'locale' => 'en'])
            ->call('create')->assertHasNoFormErrors();
        $customer = Customer::sole();
        $this->assertSame('Fictitious Client', $customer->name);
        $this->assertNull($customer->phone);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])->fillForm(['name' => 'Renamed Client'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Renamed Client', $customer->fresh()->name);

        $audit = DB::table('audit_entries')->where('subject_type', 'customers')->orderBy('id')->get();
        $this->assertSame(['customers.created', 'customers.updated'], $audit->pluck('action')->all());
        $this->assertSame([$this->owner->id, $this->owner->id], $audit->pluck('actor_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_service_validation_shows_spanish_field_errors(): void
    {
        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Fictitious Client', 'email' => 'not-an-email', 'locale' => 'es'])
            ->call('create')->assertHasFormErrors(['email']);
        $this->assertSame(0, Customer::count());
    }

    public function test_stale_edit_is_refused_and_nothing_changes(): void
    {
        $unit = Resource::create(['code' => 'STF-1', 'name' => 'Fictitious Person', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer']]);
        $page = Livewire::test(EditResource::class, ['record' => $unit->id]);
        Resource::whereKey($unit->id)->update(['version' => 2, 'name' => 'Changed elsewhere']);
        $page->fillForm(['name' => 'My change'])->call('save')->assertNotified('No se guardó');
        $this->assertSame('Changed elsewhere', $unit->fresh()->name);
        $this->assertSame(0, DB::table('audit_entries')->where('action', 'resources.updated')->count());
    }

    public function test_variant_with_requirements_and_an_appended_price(): void
    {
        Livewire::test(CreateServiceVariant::class)
            ->fillForm(['name' => 'Fictitious Package', 'duration_minutes' => 60, 'status' => 'active', 'requirements' => [
                ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer'],
                ['kind' => 'equipment', 'quantity' => 1, 'equipment_kind' => 'speaker'],
            ]])->call('create')->assertHasNoFormErrors();
        $variant = ServiceVariant::sole();
        $this->assertSame('active', $variant->status);
        $this->assertCount(2, $variant->requirements);

        Livewire::test(EditServiceVariant::class, ['record' => $variant->id])->callAction('newPrice', ['amount' => '123.45'])->assertNotified('Precio guardado');
        Livewire::test(EditServiceVariant::class, ['record' => $variant->id])->callAction('newPrice', ['amount' => '130'])->assertNotified('Precio guardado');
        $this->assertSame([12345, 13000], PriceVersion::where('service_variant_id', $variant->id)->orderBy('id')->pluck('amount_cents')->all());
        $this->assertSame($this->owner->id, (int) PriceVersion::latest('id')->first()->created_by);
        $this->get('/admin/catalogo')->assertSee('$130')->assertDontSee('$123.45');
    }

    public function test_active_service_without_requirements_is_refused_with_a_clear_message(): void
    {
        Livewire::test(CreateServiceVariant::class)
            ->fillForm(['name' => 'Fictitious Package', 'duration_minutes' => 60, 'status' => 'active', 'requirements' => []])
            ->call('create')->assertNotified('No se guardó');
        $this->assertSame(0, ServiceVariant::count());
    }
}
