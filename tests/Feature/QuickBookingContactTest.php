<?php
namespace Tests\Feature;

use App\Filament\Pages\QuickBooking;
use App\Models\{Customer, Event, PriceVersion, ServiceVariant, User};
use App\Services\Records\SaveCustomer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

// Real QuickBook service (QUICK-BOOK-CONTACT-API): a corrected contact commits with the booking or not at all,
// and the version comes from what the owner saw (Locked), so another editor's change is never overwritten.
class QuickBookingContactTest extends TestCase
{
    use DatabaseMigrations;

    private PriceVersion $price;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'app.url' => 'http://localhost']);
        Queue::fake();
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $variant = ServiceVariant::create(['name' => 'Fictitious Package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $this->price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $owner->id]);
    }

    private function form(Customer $c, array $over = []): array
    {
        return array_replace(['price_version_ids' => [$this->price->id], 'event_type' => 'birthday', 'title' => 'Fiesta ficticia', 'date' => '2026-12-19', 'start_time' => '15:00',
            'end_time' => '16:00', 'postal_code' => '00001', 'travel_fee' => '25', 'customer_mode' => 'existing', 'customer_id' => $c->id,
            'deposit' => ['amount' => '62.50', 'reference' => 'ZELLE-QBC-'.uniqid(), 'date' => now('America/Chicago')->subDay()->format('Y-m-d'), 'time' => '00:05', 'verified' => true],
            'expires_days' => 14], $over);
    }

    public function test_contact_correction_commits_with_the_booking(): void
    {
        $c = Customer::create(['name' => 'Claudia Ficticia', 'email' => 'old@example.test', 'phone' => '555-0100', 'locale' => 'es']);
        Livewire::test(QuickBooking::class)->fillForm(['customer_id' => $c->id])->fillForm($this->form($c, ['c_email' => 'new@example.test']))
            ->call('save')->assertHasNoFormErrors()->assertSet('result.event_id', Event::sole()->id);
        $this->assertSame('new@example.test', $c->fresh()->email);
        $this->assertSame(2, $c->fresh()->version);
    }

    public function test_stale_contact_rejects_the_whole_booking_and_keeps_the_form(): void
    {
        $c = Customer::create(['name' => 'Claudia Ficticia', 'email' => 'old@example.test', 'phone' => '555-0100', 'locale' => 'es']);
        $page = Livewire::test(QuickBooking::class)->fillForm(['customer_id' => $c->id])->fillForm($this->form($c, ['c_email' => 'mine@example.test']));
        // Maybeli corrects the phone in another tab after the page showed version 1.
        app(SaveCustomer::class)->execute(User::sole(), ['expected_version' => 1, 'name' => 'Claudia Ficticia', 'email' => 'old@example.test', 'phone' => '555-0199', 'locale' => 'es'], $c->id);
        $page->call('save')->assertNotified('No se guardó')->assertSet('result', null)->assertSchemaStateSet(['c_email' => 'mine@example.test']);
        $this->assertSame(0, Event::count(), 'No event, payment or booking residue.');
        $this->assertSame('old@example.test', $c->fresh()->email);
        $this->assertSame('555-0199', $c->fresh()->phone, 'The other editor change is kept.');
        $this->assertSame(2, $c->fresh()->version);
    }

    public function test_browser_cannot_set_the_displayed_customer_version(): void
    {
        $c = Customer::create(['name' => 'Claudia Ficticia', 'locale' => 'es']);
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(QuickBooking::class)->fillForm(['customer_id' => $c->id])->set('displayedCustomerVersion', 99);
    }
}
