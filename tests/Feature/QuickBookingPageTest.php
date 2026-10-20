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
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]);
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
            'deposit' => ['amount' => '62.50', 'reference' => 'ZELLE-QB-1', 'date' => now('America/Chicago')->subDay()->format('Y-m-d'), 'time' => '00:05', 'verified' => true],
            'expires_days' => 14], $over);
    }

    public function test_page_requires_both_sandbox_flags_even_after_service_exists(): void
    {
        config(['waoo.sandbox_documents' => false]);
        $this->get('/admin/reserva-rapida')->assertForbidden();
        $this->bind();
        $this->get('/admin/reserva-rapida')->assertForbidden();
        config(['waoo.sandbox_documents' => true]);
        $this->get('/admin/reserva-rapida')->assertOk()->assertSee('Reserva rápida');
    }

    public function test_one_form_creates_everything_and_shows_the_link_once_with_messages(): void
    {
        $this->bind();
        $page = Livewire::test(QuickBooking::class)->fillForm($this->form());
        $this->assertSame('16:00', QuickBooking::hm($page->get('data.end_time'))); // filled from the package duration
        $page->assertSee('Total $125 · depósito 50 % $62.50 · recibido $62.50 · saldo $62.50')
            ->call('save')->assertHasNoFormErrors()
            ->assertSee('https://crm.example.test/p/fixture-secret')->assertSee('Puedes volver a copiarlo desde el evento')
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

    // Owner request: find a registered client by name/phone/email, verify her details; corrections travel INSIDE the QuickBook call.
    public function test_search_select_verify_sends_customer_update_in_the_same_call(): void
    {
        $this->bind();
        $claudia = Customer::create(['name' => 'Claudia Martínez', 'phone' => '(346) 555-0199', 'email' => 'old@example.test', 'locale' => 'es']);
        Customer::create(['name' => 'Claudia Pérez', 'phone' => '281-555-0000', 'locale' => 'en']);
        $this->assertSame([$claudia->id], array_keys(QuickBooking::searchCustomers('5550199')), 'Phone digits match regardless of format.');
        $this->assertCount(2, QuickBooking::searchCustomers('claudia'));
        $this->assertSame([], QuickBooking::searchCustomers('c'));

        $page = Livewire::test(QuickBooking::class)->assertSchemaStateSet(['customer_mode' => 'existing'])
            ->fillForm(['customer_id' => $claudia->id])
            ->assertSchemaStateSet(['c_name' => 'Claudia Martínez', 'c_phone' => '(346) 555-0199', 'c_email' => 'old@example.test', 'c_locale' => 'es'])
            ->assertSet('displayedCustomerId', $claudia->id)->assertSet('displayedCustomerVersion', 1);
        $page->fillForm($this->form(['customer_mode' => 'existing', 'customer_id' => $claudia->id, 'c_email' => 'new@example.test']))->call('save')->assertHasNoFormErrors();
        $sent = $this->calls[0][1];
        $this->assertSame($claudia->id, $sent['customer_id']);
        $this->assertSame(['expected_version' => 1, 'name' => 'Claudia Martínez', 'email' => 'new@example.test', 'phone' => '(346) 555-0199', 'locale' => 'es'], $sent['customer_update']);
        $this->assertSame('old@example.test', $claudia->fresh()->email, 'The page never saves the contact on its own.');
        $this->assertSame(1, $claudia->fresh()->version);
    }

    public function test_unchanged_client_is_not_rewritten_and_new_client_duplicate_is_flagged(): void
    {
        $this->bind();
        $claudia = Customer::create(['name' => 'Claudia Martínez', 'phone' => '346-555-0199', 'locale' => 'es']);
        Livewire::test(QuickBooking::class)->fillForm(['customer_id' => $claudia->id])
            ->fillForm($this->form(['customer_mode' => 'existing', 'customer_id' => $claudia->id]))->call('save')->assertHasNoFormErrors();
        $this->assertArrayNotHasKey('customer_update', $this->calls[0][1], 'Same details: no contact correction is sent.');
        $this->assertSame(1, $claudia->fresh()->version);
        $this->assertStringContainsString('Ya tienes registrada a Claudia Martínez', QuickBooking::duplicateHint('Otra persona', '(346) 555 0199'));
        $this->assertStringContainsString('Ya tienes registrada', QuickBooking::duplicateHint('claudia martínez', null));
        $this->assertNull(QuickBooking::duplicateHint('Nadie', '111'));
    }

    public function test_result_offers_whatsapp_and_sms_with_the_message_ready(): void
    {
        $this->bind();
        Livewire::test(QuickBooking::class)->fillForm($this->form(['phone' => '(346) 555-0100']))->call('save')->assertHasNoFormErrors()
            ->assertSee('wa.me\\/13465550100?text=', false)->assertSee('sms:+13465550100?\u0026body=', false)
            ->assertSee('Abrir WhatsApp con el mensaje')->assertSee('Abrir SMS con el mensaje')->assertDontSee('beforeunload', false);
        $this->assertSame('13465550100', \App\Filament\Support\ContactLinks::digits('346-555-0100'));
        // Codex review #54: never guess a recipient for a message that carries the client's link.
        $d = fn ($p) => \App\Filament\Support\ContactLinks::digits($p);
        $this->assertSame('5215512345678', $d('+52 1 55 1234 5678'));
        $this->assertSame('13465550100', $d('+1 (346) 555-0100'));
        $this->assertSame('13465550100', $d('1-346-555-0100'));
        foreach (['(346) 555-0100 ext 123', '346-555-0100 x12', 'DO NOT TEXT 346-555-0100', '00000000000', '555-0100', '911-555-0100', '(346) 055-0100',
            '3465550100123', '+0 123 4567 890', '++1 346 555 0100', '', null] as $bad) {
            $this->assertNull($d($bad), 'rejected: '.var_export($bad, true));
        }
        $this->assertNull(\App\Filament\Support\ContactLinks::whatsapp('DO NOT TEXT 346-555-0100', 'x'));
    }
}
