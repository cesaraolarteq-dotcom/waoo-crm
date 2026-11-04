<?php

namespace Tests\Feature;

use App\Models\{Customer, Event, FinancialRevision, LedgerEntry, PaymentIntent, PriceVersion, ServiceVariant, User};
use App\Services\Finance\{CreateCheckoutIntent, CreateFinancialRevision, QuickStripeGateway, StripeGateway, StripeReceiptProcessor};
use App\Services\Records\SaveEventDraft;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{Http, Queue};
use Illuminate\Support\Str;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/** Owner 2026-11-03: the balance payment can also offer Klarna/Afterpay, only when Stripe says they are active. */
class BalancePayLaterTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner; private Event $event; private FinancialRevision $finance; private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'waoo.stripe_test_account' => 'acct_fixture',
            'waoo.stripe_test_webhook_secret' => 'whsec_fixture', 'waoo.portal_payment_methods' => ['klarna', 'card', 'afterpay_clearpay']]);
        Queue::fake(); Http::preventStrayRequests(); $this->stripe = new FakeStripeGateway();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        $customer = Customer::create(['name' => 'María González', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Servicio de ejemplo', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'equipment', 'equipment_kind' => 'speaker', 'quantity' => 1]]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = now()->addDays(20);
        $this->event = app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $customer->id, 'title' => 'Cumpleaños de Sofía',
            'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'timezone' => 'America/Chicago', 'travel_fee_cents' => 0,
            'services' => [['price_version_id' => $price->id]]]);
        $this->finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
    }

    private function gateway(bool $active): void
    {
        $this->app->instance(StripeGateway::class, new class($this->stripe, $active) implements QuickStripeGateway {
            public function __construct(private FakeStripeGateway $inner, private bool $active) {}
            public function verifyMethods(array $methods): array { if (!$this->active) throw new \RuntimeException('quick_payment_method_not_active'); return ['account' => 'acct_fixture', 'methods' => $methods]; }
            public function findCheckout(string $clientReferenceId, int $createdFrom, int $createdTo): ?array { return null; }
            public function createCheckout(array $parameters, string $key): array { return $this->inner->createCheckout($parameters, $key); }
            public function retrieveCheckout(string $id): array { return $this->inner->retrieveCheckout($id); }
            public function expireCheckout(string $id): void { $this->inner->expireCheckout($id); }
        });
    }

    private function create(): PaymentIntent
    {
        return app(CreateCheckoutIntent::class)->execute($this->owner, $this->event->id, ['financial_revision_id' => $this->finance->id, 'purpose' => 'balance'], (string) Str::uuid());
    }

    private function settle(PaymentIntent $intent, array $details): void
    {
        $this->stripe->paid($intent->stripe_checkout_session_id);
        $session = &$this->stripe->sessions[$intent->stripe_checkout_session_id];
        $session['payment_intent']['latest_charge']['payment_method_details'] = $details;
        $event = ['id' => 'evt_later_one', 'object' => 'event', 'type' => 'checkout.session.completed', 'livemode' => false, 'created' => time(),
            'data' => ['object' => ['id' => $session['id'], 'object' => 'checkout.session', 'metadata' => $session['metadata']]]];
        $raw = json_encode($event, JSON_THROW_ON_ERROR); $ts = time();
        $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$raw, 'whsec_fixture')], $raw)->assertOk();
        app(StripeReceiptProcessor::class)->execute('evt_later_one');
    }

    public function test_active_pay_later_methods_are_offered_and_a_klarna_payment_counts(): void
    {
        $this->gateway(true);
        $intent = $this->create();
        $this->assertSame(['afterpay_clearpay', 'card', 'klarna'], $this->stripe->calls[0]['parameters']['payment_method_types']);
        $this->settle($intent, ['type' => 'klarna', 'klarna' => []]);
        $this->assertSame(10000, LedgerEntry::where('kind', 'receipt')->sole()->amount_cents);
        $this->assertSame('paid', $intent->fresh()->status);
    }

    public function test_inactive_methods_fall_back_to_card_and_a_klarna_payment_there_needs_review(): void
    {
        $this->gateway(false);
        $intent = $this->create();
        $this->assertSame(['card'], $this->stripe->calls[0]['parameters']['payment_method_types']);
        $this->settle($intent, ['type' => 'klarna', 'klarna' => []]);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(['reconciliation_required', 'payment_method_not_offered'], [$intent->fresh()->status, $intent->fresh()->last_error]);
    }

    public function test_unknown_configuration_offers_card_only(): void
    {
        $this->gateway(true);
        config(['waoo.portal_payment_methods' => ['klarna', 'zip']]);
        $this->assertSame(['card'], CreateCheckoutIntent::methods());
        $this->create();
        $this->assertSame(['card'], $this->stripe->calls[0]['parameters']['payment_method_types']);
    }
}
