<?php
namespace Tests\Feature;

use App\Jobs\{ExpireTestCheckout, ProcessStripeWebhook};
use App\Models\{Customer, Event, FinancialRevision, LedgerEntry, PaymentIntent, PriceVersion, ServiceVariant, User, WebhookReceipt};
use App\Services\Finance\{CreateFinancialRevision, Sandbox, StripeGateway, StripeReceiptProcessor, StripeTestGateway};
use App\Services\Records\SaveEventDraft;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{Crypt, Queue};
use Illuminate\Support\Str;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

class StripeSandboxTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner; private Event $event; private FinancialRevision $finance; private FakeStripeGateway $stripe;
    protected function setUp(): void
    {
        parent::setUp(); config(['waoo.sandbox_finance' => true, 'waoo.stripe_test_secret' => 'sk_test_fixture', 'waoo.stripe_test_account' => 'acct_fixture', 'waoo.stripe_test_webhook_secret' => 'whsec_fixture']); Queue::fake();
        $this->stripe = new FakeStripeGateway(); $this->app->instance(StripeGateway::class, $this->stripe);
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]); $this->actingAs($this->owner);
        $customer = Customer::create(['name' => 'Fixture client', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fixture service', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 40000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = now()->addDays(20)->startOfHour();
        $this->event = app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $customer->id, 'title' => 'Fixture party', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'timezone' => 'America/Chicago', 'travel_fee_cents' => 5500, 'services' => [['price_version_id' => $price->id]]]);
        $this->finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
    }
    private function create(?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/admin/api/events/'.$this->event->id.'/checkout-intents', ['financial_revision_id' => $this->finance->id, 'purpose' => 'deposit']);
    }
    private function webhook(PaymentIntent $intent, string $id = 'evt_fixture_one', array $overrides = [])
    {
        $session = $this->stripe->sessions[$intent->stripe_checkout_session_id];
        $event = [...['id' => $id, 'object' => 'event', 'type' => 'checkout.session.completed', 'livemode' => false, 'created' => time(),
            'data' => ['object' => ['id' => $session['id'], 'object' => 'checkout.session', 'metadata' => $session['metadata'],
                'payment_method_details' => ['card' => ['number' => 'fake-data-that-must-not-be-stored']]]]], ...$overrides];
        $raw = json_encode($event, JSON_THROW_ON_ERROR); $time = time(); $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$raw, 'whsec_fixture');
        return $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $raw);
    }
    public function test_honeybook_refuses_checkout_replay_and_old_owner_checkout_url():void
    {
        $key=(string)Str::uuid();$this->create($key)->assertCreated();$intent=PaymentIntent::sole();
        $this->event->forceFill(['responsible_system'=>'honeybook'])->save();
        $this->create($key)->assertConflict()->assertJsonPath('code','responsible_system_honeybook');
        $this->create()->assertConflict()->assertJsonPath('code','responsible_system_honeybook');
        $this->getJson('/admin/api/payment-intents/'.$intent->id)->assertConflict();
        $this->assertCount(1,$this->stripe->calls);$this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_responsibility_change_during_provider_creation_keeps_evidence_and_expires_external_checkout():void
    {
        $fake=$this->stripe;$eventId=$this->event->id;
        $adapter=new class($fake,$eventId) implements StripeGateway {
            public function __construct(private FakeStripeGateway $fake,private int $eventId){}
            public function createCheckout(array $p,string $key):array {$session=$this->fake->createCheckout($p,$key);Event::whereKey($this->eventId)->update(['responsible_system'=>'honeybook']);return $session;}
            public function retrieveCheckout(string $id):array{return $this->fake->retrieveCheckout($id);}
            public function expireCheckout(string $id):void{$this->fake->expireCheckout($id);}
        };
        $this->app->instance(StripeGateway::class,$adapter);$this->create()->assertConflict();$intent=PaymentIntent::sole();
        $this->assertSame('reconciliation_required',$intent->status);$this->assertNotNull($intent->stripe_checkout_session_id);$this->assertNull($intent->checkout_url);
        Queue::assertPushed(ExpireTestCheckout::class);(new ExpireTestCheckout($intent->id))->handle();$this->assertSame([$intent->stripe_checkout_session_id],$fake->expired);
        $this->assertDatabaseHas('payment_exceptions',['kind'=>'stripe_reconciliation','state'=>'open']);$this->assertDatabaseCount('ledger_entries',0);
    }
    public static function responsibilityPolicies():array {return ['crm'=>['crm'],'honeybook'=>['honeybook']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('responsibilityPolicies')]
    public function test_verified_receipt_before_checkout_response_keeps_paid_state_and_provider_identity(string $policy):void
    {
        config(['waoo.sandbox_mail'=>true]);$fake=$this->stripe;
        $settled=function(array $session)use($policy){
            Event::whereKey($this->event->id)->update(['responsible_system'=>$policy]);
            $this->stripe->paid($session['id']);$intent=PaymentIntent::sole();$intent->stripe_checkout_session_id=$session['id'];
            $this->webhook($intent)->assertOk();app(StripeReceiptProcessor::class)->execute('evt_fixture_one');
        };
        $this->app->instance(StripeGateway::class,new class($fake,$settled) implements StripeGateway {
            public function __construct(private FakeStripeGateway $fake,private \Closure $settled){}
            public function createCheckout(array $p,string $key):array {$stale=$this->fake->createCheckout($p,$key);($this->settled)($stale);return $stale;}
            public function retrieveCheckout(string $id):array{return $this->fake->retrieveCheckout($id);}
            public function expireCheckout(string $id):void{$this->fake->expireCheckout($id);}
        });
        $result=$this->create();if($policy==='crm')$result->assertCreated();else $result->assertConflict();
        $intent=PaymentIntent::sole();$this->assertSame('paid',$intent->status);
        $this->assertSame('pi_fixture_'.$intent->id,$intent->stripe_payment_intent_id);$this->assertNull($intent->collection_slot);
        $this->assertDatabaseCount('ledger_entries',1);$this->assertSame('processed',WebhookReceipt::sole()->state);
        (new ExpireTestCheckout($intent->id))->handle();$this->assertSame([],$fake->expired);
    }
    public function test_honeybook_late_verified_money_commits_but_skips_mail_and_flags_reconciliation():void
    {
        config(['waoo.sandbox_mail'=>true]);$this->create()->assertCreated();$intent=PaymentIntent::sole();
        $this->event->forceFill(['responsible_system'=>'honeybook'])->save();$this->stripe->paid($intent->stripe_checkout_session_id);
        $this->webhook($intent)->assertOk();app(StripeReceiptProcessor::class)->execute('evt_fixture_one');
        $this->webhook($intent,'evt_second_late')->assertOk();app(StripeReceiptProcessor::class)->execute('evt_second_late');
        $this->assertDatabaseCount('ledger_entries',1);$this->assertSame('paid',$intent->fresh()->status);
        $this->assertDatabaseCount('mail_occurrences',0);$this->assertDatabaseCount('outbound_messages',0);
        $this->assertDatabaseHas('payment_exceptions',['event_id'=>$this->event->id,'kind'=>'responsibility_reconciliation','state'=>'open']);
        $this->assertSame(1,\Illuminate\Support\Facades\DB::table('payment_exceptions')->where('kind','responsibility_reconciliation')->count());
    }
    public function test_checkout_uses_server_amount_card_allowlist_no_affirm_and_no_fee(): void
    {
        $key = (string) Str::uuid();
        $response = $this->create($key)->assertCreated()->assertJsonPath('payable_cents', 22750)->assertJsonPath('customer_fee_cents', 0)->assertJsonPath('status', 'open');
        $this->create($key)->assertCreated()->assertJsonPath('id', $response->json('id'));
        $this->create()->assertConflict();
        $parameters = $this->stripe->calls[0]['parameters'];
        $this->assertSame(['card'], $parameters['payment_method_types']);
        $this->assertArrayNotHasKey('automatic_payment_methods', $parameters);
        $this->assertSame(22750, $parameters['line_items'][0]['price_data']['unit_amount']);
        $this->assertCount(1, $this->stripe->calls);
        $this->assertDatabaseCount('ledger_entries', 0);
    }
    public function test_verified_webhooks_and_transaction_ids_deduplicate_and_discard_card_details(): void
    {
        $this->create()->assertCreated(); $intent = PaymentIntent::firstOrFail(); $this->stripe->paid($intent->stripe_checkout_session_id);
        $this->webhook($intent)->assertOk(); $this->webhook($intent)->assertOk();
        Queue::assertPushed(ProcessStripeWebhook::class, 1);
        $this->assertStringNotContainsString('fake-data-that-must-not-be-stored', Crypt::decryptString(WebhookReceipt::first()->encrypted_payload));
        app(StripeReceiptProcessor::class)->execute('evt_fixture_one');
        $this->webhook($intent, 'evt_fixture_second')->assertOk(); app(StripeReceiptProcessor::class)->execute('evt_fixture_second');
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame(22750, LedgerEntry::first()->amount_cents);
        $this->assertNull(LedgerEntry::first()->verified_by);
        $this->assertSame('paid', $intent->fresh()->status);
        $this->assertDatabaseCount('resource_reservations', 0);
        $this->getJson('/admin/api/events/'.$this->event->id.'/finance')->assertOk()->assertJsonPath('balance_cents', 22750);
    }
    public function test_verified_stripe_receipt_captures_one_mail_occurrence_without_sending_or_replaying(): void
    {
        config(['waoo.sandbox_mail' => true]);
        \Illuminate\Support\Facades\Mail::fake();
        $this->create()->assertCreated(); $intent = PaymentIntent::firstOrFail();
        $this->stripe->paid($intent->stripe_checkout_session_id);
        $this->webhook($intent)->assertOk(); app(StripeReceiptProcessor::class)->execute('evt_fixture_one');
        $this->webhook($intent, 'evt_fixture_second')->assertOk(); app(StripeReceiptProcessor::class)->execute('evt_fixture_second');
        $entry = LedgerEntry::sole(); $occurrence = \App\Models\MailOccurrence::sole();
        $this->assertSame('verified_stripe_webhook', $entry->source_type);
        $this->assertSame('payment_received', $occurrence->key);
        $this->assertSame($entry->id, $occurrence->locator['ledger_entry_id']);
        $this->assertNull($occurrence->actor_id);
        $this->assertDatabaseCount('outbound_messages', 0);
        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }
    public function test_invalid_signature_live_mode_and_wrong_account_are_refused(): void
    {
        $this->create()->assertCreated(); $intent = PaymentIntent::firstOrFail();
        $this->postJson('/webhooks/stripe', ['id' => 'evt_forged'])->assertStatus(400);
        $this->webhook($intent, 'evt_live', ['livemode' => true])->assertStatus(400);
        $this->webhook($intent, 'evt_wrong_account', ['account' => 'acct_other'])->assertStatus(400);
        $this->assertDatabaseCount('webhook_receipts', 0); $this->assertDatabaseCount('ledger_entries', 0);
    }
    public function test_provider_amount_mismatch_is_reconciliation_not_a_received_payment(): void
    {
        $this->create()->assertCreated(); $intent = PaymentIntent::firstOrFail(); $this->stripe->paid($intent->stripe_checkout_session_id);
        $this->stripe->sessions[$intent->stripe_checkout_session_id]['amount_total'] = 123;
        $this->webhook($intent)->assertOk(); app(StripeReceiptProcessor::class)->execute('evt_fixture_one');
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame('reconciliation_required', $intent->fresh()->status);
        $this->assertDatabaseHas('payment_exceptions', ['kind' => 'stripe_reconciliation', 'state' => 'open']);
    }
    public function test_unpaid_success_return_event_is_pending_until_authoritative_payment_succeeds(): void
    {
        $this->create()->assertCreated(); $intent = PaymentIntent::firstOrFail();
        $this->webhook($intent)->assertOk(); app(StripeReceiptProcessor::class)->execute('evt_fixture_one');
        $this->assertSame('processing', $intent->fresh()->status); $this->assertDatabaseCount('ledger_entries', 0);
        $this->stripe->paid($intent->stripe_checkout_session_id);
        $this->webhook($intent, 'evt_async_success', ['type' => 'checkout.session.async_payment_succeeded'])->assertOk(); app(StripeReceiptProcessor::class)->execute('evt_async_success');
        $this->webhook($intent, 'evt_old_expired', ['type' => 'checkout.session.expired'])->assertOk(); app(StripeReceiptProcessor::class)->execute('evt_old_expired');
        $this->assertSame('paid', $intent->fresh()->status); $this->assertDatabaseCount('ledger_entries', 1);
    }
    public function test_zelle_supersedes_checkout_but_late_provider_money_is_still_recorded_as_credit(): void
    {
        $this->create()->assertCreated(); $intent = PaymentIntent::firstOrFail();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/admin/api/events/'.$this->event->id.'/payments/zelle', [
            'financial_revision_id' => $this->finance->id, 'amount_cents' => 45500, 'currency' => 'USD', 'reference' => 'FIXTURE-ZELLE-FULL', 'received_at' => now()->subHour()->toIso8601String(), 'verified_received' => true])->assertCreated();
        $this->assertSame('superseded', $intent->fresh()->status); Queue::assertPushed(ExpireTestCheckout::class);
        $this->stripe->paid($intent->stripe_checkout_session_id); $this->webhook($intent)->assertOk(); app(StripeReceiptProcessor::class)->execute('evt_fixture_one');
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->getJson('/admin/api/events/'.$this->event->id.'/finance')->assertOk()->assertJsonPath('credit_cents', 22750)->assertJsonPath('balance_cents', 0);
        $this->assertDatabaseHas('payment_exceptions', ['kind' => 'overpayment', 'state' => 'open']);
    }
    public function test_provider_timeout_retries_the_same_remote_key_without_another_local_collection(): void
    {
        $this->stripe->failAfterCreate = true; $key = (string) Str::uuid();
        $this->create($key)->assertStatus(503); $this->create($key)->assertCreated();
        $this->assertDatabaseCount('payment_intents', 1); $this->assertCount(1, $this->stripe->sessions);
        $this->assertSame($this->stripe->calls[0]['key'], $this->stripe->calls[1]['key']);
        $this->assertDatabaseCount('ledger_entries', 0);
    }
    public function test_real_adapter_refuses_live_keys_and_production_even_when_flag_is_set(): void
    {
        config(['waoo.stripe_test_secret' => 'sk_live_fixture']);
        try { new StripeTestGateway(); $this->fail('Live key was accepted.'); }
        catch (\Illuminate\Auth\Access\AuthorizationException $e) { $this->assertNotEmpty($e->getMessage()); }
        $environment = $this->app->environment(); $this->app->instance('env', 'production');
        try { Sandbox::require(); $this->fail('Production was accepted.'); }
        catch (\Illuminate\Auth\Access\AuthorizationException $e) { $this->assertNotEmpty($e->getMessage()); }
        finally { $this->app->instance('env', $environment); }
    }
    public function test_simultaneous_zelle_and_stripe_receipts_keep_both_and_expose_overpayment(): void
    {
        $this->create()->assertCreated(); $intent = PaymentIntent::firstOrFail(); $this->stripe->paid($intent->stripe_checkout_session_id);
        $this->webhook($intent)->assertOk();
        $owner = $this->owner; $eventId = $this->event->id; $financeId = $this->finance->id;
        $results = \Tests\Support\ConcurrentWorkers::run([
            function () use ($owner, $eventId, $financeId) {
                app(\App\Services\Finance\RecordZellePayment::class)->execute($owner, $eventId, ['financial_revision_id' => $financeId, 'amount_cents' => 45500, 'currency' => 'USD', 'reference' => 'FIXTURE-RACE-ZELLE', 'received_at' => now()->subHour()->toIso8601String(), 'verified_received' => true], (string) Str::uuid());
                return 'zelle';
            },
            function () { app(StripeReceiptProcessor::class)->execute('evt_fixture_one'); return 'stripe'; },
        ]);
        $this->assertSame(['zelle','stripe'], $results);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->getJson('/admin/api/events/'.$eventId.'/finance')->assertOk()->assertJsonPath('credit_cents', 22750);
        $this->assertDatabaseHas('payment_exceptions', ['kind' => 'overpayment', 'state' => 'open']);
    }

    public function test_durable_webhook_can_recover_after_provider_failure_without_duplicate_money(): void
    {
        $this->create()->assertCreated(); $intent = PaymentIntent::firstOrFail(); $this->stripe->paid($intent->stripe_checkout_session_id);
        $this->webhook($intent)->assertOk(); $this->stripe->failRetrieve = true;
        $job = new ProcessStripeWebhook('evt_fixture_one');
        try { $job->handle(app(StripeReceiptProcessor::class)); $this->fail('Provider failure was hidden.'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture provider unavailable', $e->getMessage()); }
        $job->failed(new \RuntimeException('Fixture retries exhausted'));
        $this->assertSame('reconciliation_required', WebhookReceipt::first()->state);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->postJson('/admin/api/stripe-webhooks/evt_fixture_one/retry', ['reason' => 'Fixture connection recovered; recheck authoritative provider.'])->assertOk();
        $this->stripe->failRetrieve = false; $job->handle(app(StripeReceiptProcessor::class)); $job->handle(app(StripeReceiptProcessor::class));
        $this->assertSame('processed', WebhookReceipt::first()->state);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

}
