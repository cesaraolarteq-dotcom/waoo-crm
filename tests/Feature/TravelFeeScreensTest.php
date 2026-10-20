<?php
namespace Tests\Feature;

use App\Filament\Pages\QuickBooking;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Models\{Customer, Event, PriceVersion, ServiceVariant, User};
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

// ROUTE-01 screens over Codex's TravelFeeQuote: "Calcular" fills the fee for the owner to accept; unknown is never $0;
// a changed fee needs a reason; no Google call in tests (special ZIP or no key).
class TravelFeeScreensTest extends TestCase
{
    use DatabaseMigrations;

    private PriceVersion $price;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'waoo.sandbox_routes' => true, 'waoo.google_routes_key' => null]);
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $variant = ServiceVariant::create(['name' => 'Fictitious Package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $this->price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $owner->id]);
    }

    private function quote(): TestAction
    {
        return TestAction::make('quoteTravel')->schemaComponent('travel_fee');
    }

    public function test_calculated_fee_is_filled_and_accepted_with_evidence(): void
    {
        $client = Customer::create(['name' => 'Ana Ficticia', 'locale' => 'es']);
        Livewire::test(CreateEvent::class)
            ->fillForm(['customer_id' => $client->id, 'title' => 'Fiesta', 'date' => '2026-12-19', 'start_time' => '15:00', 'end_time' => '16:00',
                'price_version_ids' => [$this->price->id], 'address' => '123 Fixture St, Hockley, TX 77433', 'postal_code' => '77433'])
            ->assertSee('data-places="quote"', false) // the Google address picker presses this button after filling the address
            ->callAction($this->quote())
            ->assertSchemaStateSet(['travel_fee' => '40', 'travel_quote_status' => 'ok'])
            ->assertSee('Calculado: $40 (precio fijo de ese ZIP).')
            ->call('create')->assertHasNoFormErrors();
        $event = Event::sole();
        $this->assertSame(4000, $event->travel_fee_cents);
        $this->assertNotEmpty($event->travel_fee_evidence);
        $this->get('/admin/eventos/'.$event->id)->assertOk()->assertSee('$40')->assertSee('calculado el');
    }

    public function test_changed_fee_needs_a_reason(): void
    {
        $client = Customer::create(['name' => 'Ana Ficticia', 'locale' => 'es']);
        $page = Livewire::test(CreateEvent::class)
            ->fillForm(['customer_id' => $client->id, 'title' => 'Fiesta', 'date' => '2026-12-19', 'start_time' => '15:00', 'end_time' => '16:00',
                'price_version_ids' => [$this->price->id], 'address' => '123 Fixture St, Hockley, TX 77433', 'postal_code' => '77433'])
            ->callAction($this->quote())->fillForm(['travel_fee' => '55'])
            ->assertFormFieldIsVisible('travel_fee_override_note')
            ->call('create')->assertHasFormErrors(['travel_fee_override_note' => 'required']);
        $page->fillForm(['travel_fee_override_note' => 'Calle privada con caseta extra.'])->call('create')->assertHasNoFormErrors();
        $this->assertSame(5500, Event::sole()->travel_fee_cents);
    }

    public function test_unavailable_is_pending_never_zero(): void
    {
        Livewire::test(QuickBooking::class)
            ->fillForm(['address' => '500 Fixture Ave, Katy, TX 77494', 'postal_code' => '77494'])
            ->callAction($this->quote())
            ->assertSchemaStateSet(['travel_quote_status' => 'unavailable', 'travel_fee' => null])
            ->assertSee('Pendiente: no se pudo calcular ahora');
    }

    public function test_hidden_when_routes_sandbox_is_off(): void
    {
        config(['waoo.sandbox_routes' => false]);
        Livewire::test(CreateEvent::class)->assertActionDoesNotExist($this->quote());
    }

    public function test_failed_recalculation_requires_a_manual_reason_before_reusing_the_old_fee(): void
    {
        $client = Customer::create(['name' => 'Fixture Recalculation Client', 'locale' => 'es']);
        Livewire::test(CreateEvent::class)
            ->fillForm(['customer_id' => $client->id, 'title' => 'Fixture party', 'date' => '2026-12-19',
                'start_time' => '15:00', 'end_time' => '16:00', 'price_version_ids' => [$this->price->id],
                'address' => '123 Fictional Venue, TX 77433', 'postal_code' => '77433'])
            ->callAction($this->quote())->assertSchemaStateSet(['travel_fee' => '40'])
            ->fillForm(['address' => '456 Other Fictional Venue, TX 77494', 'postal_code' => '77494'])
            ->callAction($this->quote())
            ->assertSchemaStateSet(['travel_quote_status' => 'unavailable', 'travel_fee' => '40'])
            ->assertFormFieldIsVisible('travel_fee_override_note')
            ->call('create')->assertHasFormErrors(['travel_fee_override_note' => 'required']);
        $this->assertDatabaseCount('events', 0);
    }
}
