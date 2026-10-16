<?php
namespace Tests\Feature;

use App\Filament\Pages\QuickBooking;
use App\Models\{Customer, Event, FinancialRevision, LedgerEntry, PriceVersion, ServiceVariant, User};
use App\Services\Finance\{CreateFinancialRevision, FinanceSummary, RecordZellePayment};
use App\Services\Records\{SaveCustomer, SaveEventDraft};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

// Reserva rápida against the proposed QuickBook service. The stand-in chains Codex's real services
// (customer, draft, financial revision, verified Zelle) and returns a fictitious one-time link.
class QuickBookingPageTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private PriceVersion $price;
    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true]);
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $variant = ServiceVariant::create(['name' => 'Fictitious Package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $this->price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
    }

    private function bind(): void
    {
        $test = $this;
        $this->app->instance(QuickBooking::SERVICE, new class($test) {
            public function __construct(private $test) {}
            public function execute(User $actor, array $input, string $key): object
            {
                $this->test->calls[] = [$actor, $input, $key];
                $customer = isset($input['customer_id']) ? Customer::findOrFail($input['customer_id']) : app(SaveCustomer::class)->execute($actor, $input['new_customer']);
                $event = app(SaveEventDraft::class)->execute($actor, ['customer_id' => $customer->id, 'title' => $input['title'], 'event_type' => $input['event_type'],
                    'starts_at' => $input['starts_at'], 'ends_at' => $input['ends_at'], 'timezone' => $input['timezone'], 'postal_code' => $input['postal_code'],
                    'travel_fee_cents' => $input['travel_fee_cents'], 'services' => $input['services']]);
                $revision = app(CreateFinancialRevision::class)->execute($actor, $event->id, ['expected_version' => $event->version], (string) Str::uuid());
                $receipt = app(RecordZellePayment::class)->execute($actor, $event->id, ['financial_revision_id' => $revision->id, 'currency' => 'USD', ...array_diff_key($input['deposit'], ['method' => 1])], (string) Str::uuid());

                return (object) ['event' => $event, 'customer' => $customer, 'financial_revision' => $revision, 'receipt' => $receipt,
                    'finance' => app(FinanceSummary::class)->forEvent($event->fresh()), 'portal_url' => 'https://crm.example.test/p/fixture-secret', 'portal_expires_at' => $input['portal']['expires_at']];
            }
        });
    }

    private function form(array $over = []): array
    {
        return array_replace_recursive(['price_version_ids' => [$this->price->id], 'event_type' => 'birthday', 'title' => 'Cumpleaños de Sofía (ficticio)',
            'date' => '2026-12-19', 'start_time' => '15:00', 'postal_code' => '00001', 'travel_fee' => '25',
            'customer_mode' => 'new', 'name' => 'Ana Ficticia', 'phone' => '555-0100', 'locale' => 'es',
            'deposit' => ['amount' => '62.50', 'reference' => 'ZELLE-QB-1', 'date' => now('America/Chicago')->format('Y-m-d'), 'time' => '00:05', 'verified' => true],
            'expires_days' => 14], $over);
    }

    public function test_page_is_off_until_the_service_exists(): void
    {
        $this->get('/admin/reserva-rapida')->assertForbidden();
        $this->bind();
        $this->get('/admin/reserva-rapida')->assertOk()->assertSee('Reserva rápida');
    }

    public function test_one_form_creates_everything_and_shows_the_link_once_with_messages(): void
    {
        $this->bind();
        $page = Livewire::test(QuickBooking::class)->fillForm($this->form())
            ->assertSet('data.end_time', '16:00') // filled from the package duration
            ->assertSee('Total $125 · depósito 50 % $62.50 · recibido $62.50 · saldo $62.50')
            ->call('save')->assertHasNoFormErrors()
            ->assertSee('https://crm.example.test/p/fixture-secret')->assertSee('solo se muestra esta vez')
            ->assertSee('¡Hola Ana! Gracias por tu depósito de $62.50')->assertSee('Hi Ana! Thank you for your deposit of $62.50')
            ->assertSee('¡Estamos emocionados de ser parte de tu celebración!');

        [$actor, $input, $key] = $this->calls[0];
        $this->assertTrue($actor->is($this->owner));
        $this->assertTrue(Str::isUuid($key));
        $this->assertSame(2500, $input['travel_fee_cents']);
        $this->assertSame(6250, $input['deposit']['amount_cents']);
        $this->assertSame('birthday', $input['event_type']);
        $this->assertSame('2026-12-19T15:00:00-06:00', $input['starts_at']);
        $event = Event::sole();
        $this->assertSame('birthday', $event->event_type);
        $this->assertSame(12500, FinancialRevision::sole()->total_cents);
        $this->assertSame(6250, LedgerEntry::sole()->amount_cents);
        $this->assertSame('draft', $event->status, 'Staff and costumes are assigned in the separate step.');

        $page->call('startOver')->assertSet('result', null)->assertDontSee('fixture-secret');
    }

    public function test_travel_fee_and_deposit_verification_are_required(): void
    {
        $this->bind();
        Livewire::test(QuickBooking::class)->fillForm($this->form(['travel_fee' => '', 'deposit' => ['verified' => false]]))
            ->call('save')->assertHasFormErrors(['travel_fee', 'deposit.verified']);
        $this->assertSame([], $this->calls);
        $this->assertSame(0, Event::count());
    }

    public function test_existing_client_and_service_errors_map_to_fields(): void
    {
        $this->bind();
        $existing = Customer::create(['name' => 'Clienta Ficticia', 'locale' => 'en']);
        Livewire::test(QuickBooking::class)->fillForm($this->form(['customer_mode' => 'existing', 'customer_id' => $existing->id]))->call('save')->assertHasNoFormErrors()
            ->assertSee('Hi Clienta!');
        $this->assertSame($existing->id, Event::sole()->customer_id);
        // Same Zelle reference again → the service refuses; nothing new is recorded.
        Livewire::test(QuickBooking::class)->fillForm($this->form(['title' => 'Otra fiesta', 'deposit' => ['amount' => '10']]))->call('save')->assertNotified('No se guardó');
        $this->assertSame(1, LedgerEntry::count());
    }
}
