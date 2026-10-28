<?php

namespace Tests\Feature;

use App\Models\{Customer, Event, FinancialRevision, LedgerEntry, PaymentIntent, PriceVersion, ServiceVariant, User, WebhookReceipt};
use App\Services\Finance\{CardFees, CreateCheckoutIntent, CreateFinancialRevision, FinanceSummary, StripeGateway, StripeReceiptProcessor};
use App\Services\Portal\IssuePortalGrant;
use App\Services\Records\SaveEventDraft;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Queue};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

class StandardCardFeeTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;private Event $event;private FinancialRevision $finance;private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance'=>true,'waoo.sandbox_documents'=>true,'waoo.regular_card_fee_enabled'=>true,
            'waoo.stripe_test_account'=>'acct_fixture','waoo.stripe_credit_only_account'=>'acct_fixture','waoo.stripe_test_webhook_secret'=>'whsec_fixture']);
        Queue::fake();Http::preventStrayRequests();$this->stripe=new FakeStripeGateway();$this->app->instance(StripeGateway::class,$this->stripe);
        $this->owner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $this->actingAs($this->owner);
        $customer=Customer::create(['name'=>'María González','locale'=>'es']);
        $variant=ServiceVariant::create(['name'=>'Servicio de ejemplo','status'=>'active','duration_minutes'=>60,'requirements'=>[['kind'=>'equipment','equipment_kind'=>'speaker','quantity'=>1]]]);
        $price=PriceVersion::create(['service_variant_id'=>$variant->id,'amount_cents'=>10000,'currency'=>'USD','created_by'=>$this->owner->id]);
        $start=now()->addDays(20);
        $this->event=app(SaveEventDraft::class)->execute($this->owner,['customer_id'=>$customer->id,'title'=>'Cumpleaños de Sofía',
            'starts_at'=>$start->toIso8601String(),'ends_at'=>$start->copy()->addHour()->toIso8601String(),'timezone'=>'America/Chicago','travel_fee_cents'=>0,
            'services'=>[['price_version_id'=>$price->id]]]);
        $this->finance=app(CreateFinancialRevision::class)->execute($this->owner,$this->event->id,['expected_version'=>1],(string)Str::uuid());
    }

    protected function tearDown(): void
    {
        // Disposable test fixtures only: the down migration refuses to discard populated fee evidence.
        if($this->app && \Illuminate\Support\Facades\Schema::hasColumn('payment_intents','card_fee_policy'))DB::table('payment_intents')->update(['card_fee_policy'=>null,'card_fee_terms_sha256'=>null]);
        parent::tearDown();
    }

    private function create(?string $key=null): PaymentIntent
    {
        return app(CreateCheckoutIntent::class)->execute($this->owner,$this->event->id,
            ['financial_revision_id'=>$this->finance->id,'purpose'=>'deposit'],$key??(string)Str::uuid());
    }

    private function settle(PaymentIntent $intent,string $webhookId='evt_fee_one',?string $funding='credit'): void
    {
        $this->stripe->paid($intent->stripe_checkout_session_id);
        $session=&$this->stripe->sessions[$intent->stripe_checkout_session_id];
        $session['payment_intent']['latest_charge']['payment_method_details']=['card'=>['funding'=>$funding]];
        $event=['id'=>$webhookId,'object'=>'event','type'=>'checkout.session.completed','livemode'=>false,'created'=>time(),
            'data'=>['object'=>['id'=>$session['id'],'object'=>'checkout.session','metadata'=>$session['metadata']]]];
        $raw=json_encode($event,JSON_THROW_ON_ERROR);$ts=time();
        $this->call('POST','/webhooks/stripe',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json',
            'HTTP_STRIPE_SIGNATURE'=>'t='.$ts.',v1='.hash_hmac('sha256',$ts.'.'.$raw,'whsec_fixture')],$raw)->assertOk();
        app(StripeReceiptProcessor::class)->execute($webhookId);
    }

    public function test_gross_charge_is_frozen_and_fee_never_reduces_balance_or_duplicates_on_webhook_replay(): void
    {
        $key=(string)Str::uuid();$intent=$this->create($key);
        $this->assertSame([5000,150,5150],[$intent->principal_cents,$intent->customer_fee_cents,$intent->payable_cents]);
        $this->assertTrue(CardFees::valid($intent));$this->assertSame($intent->id,$this->create($key)->id);
        $this->assertCount(1,$this->stripe->calls);
        $this->settle($intent);$this->settle($intent,'evt_fee_two');
        $this->assertSame(5000,LedgerEntry::where('kind','receipt')->sole()->amount_cents);
        $this->assertSame(150,LedgerEntry::where('kind','customer_fee')->sole()->amount_cents);
        $this->assertDatabaseCount('ledger_entries',2);
        $summary=app(FinanceSummary::class)->forEvent($this->event);
        $this->assertSame(5000,$summary['balance_cents']);$this->assertSame(0,$summary['credit_cents']);
        $this->assertSame(5150,$summary['gross_received_cents']);$this->assertSame(150,$summary['customer_fee_received_cents']);
    }

    public function test_legacy_zero_fee_intent_keeps_its_terms_after_the_feature_is_enabled(): void
    {
        config(['waoo.regular_card_fee_enabled'=>false]);$intent=$this->create();
        $this->assertNull($intent->card_fee_policy);$this->assertSame(0,$intent->customer_fee_cents);
        config(['waoo.regular_card_fee_enabled'=>true]);$this->settle($intent);
        $this->assertDatabaseCount('ledger_entries',1);$this->assertSame(5000,LedgerEntry::sole()->amount_cents);
    }

    public function test_no_provider_request_is_created_until_credit_only_policy_is_verified_for_the_exact_account(): void
    {
        config(['waoo.stripe_credit_only_account'=>'acct_other']);
        try{$this->create();$this->fail('Unverified credit-only policy accepted.');}catch(ValidationException){$this->assertDatabaseCount('payment_intents',0);}
        $this->assertSame([],$this->stripe->calls);
    }

    public static function nonCreditFunding(): array { return [['debit'],['prepaid'],[null]]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('nonCreditFunding')]
    public function test_non_credit_or_unknown_funding_requires_reconciliation_not_a_fabricated_receipt(?string $funding): void
    {
        $intent=$this->create();$this->settle($intent,funding:$funding);
        $this->assertSame('reconciliation_required',$intent->fresh()->status);
        $this->assertSame('credit_card_policy_mismatch',WebhookReceipt::sole()->last_error);
        $this->assertDatabaseCount('ledger_entries',0);
    }

    public function test_portal_exposes_formatted_quotes_and_requires_the_current_quote_before_creating_checkout(): void
    {
        $grant=app(IssuePortalGrant::class)->execute($this->owner,$this->event->id,['customer_id'=>$this->event->customer_id,
            'expires_at'=>now()->addDay()->toIso8601String(),'scopes'=>['payments.read','checkout.create']]);
        auth()->logout();$this->postJson('/portal/api/session',['token'=>$grant->token])->assertOk();
        $v=$this->getJson('/portal/api/event')->assertOk()->json('finance.card_quotes');
        $this->assertSame('$1.50',$v['deposit']['card_fee']);$this->assertSame('$103.00',$v['balance']['card_total']);
        $body=['financial_revision_id'=>$this->finance->id,'purpose'=>'balance'];
        $this->postJson('/portal/api/checkout-intents',$body,['Idempotency-Key'=>(string)Str::uuid()])->assertUnprocessable();
        $this->postJson('/portal/api/checkout-intents',$body+['card_quote_sha256'=>str_repeat('0',64)],['Idempotency-Key'=>(string)Str::uuid()])->assertUnprocessable();
        $this->assertSame([],$this->stripe->calls);
        $this->postJson('/portal/api/checkout-intents',$body+['card_quote_sha256'=>$v['balance']['card_quote_sha256']],['Idempotency-Key'=>(string)Str::uuid()])->assertCreated();
        $this->assertSame(10300,PaymentIntent::sole()->payable_cents);
    }

    public function test_missing_fee_evidence_on_a_replay_is_quarantined_without_recreating_money(): void
    {
        $intent=$this->create();$this->settle($intent);
        DB::table('ledger_entries')->where('kind','customer_fee')->delete();
        $this->settle($intent,'evt_fee_corrupt');
        $this->assertSame('fee_allocation_mismatch',WebhookReceipt::findOrFail('evt_fee_corrupt')->last_error);
        $this->assertDatabaseCount('ledger_entries',1);
    }

    public function test_rounding_and_provider_gross_limit_use_integer_cents(): void
    {
        $this->assertSame(0,CardFees::quote(16)['customer_fee_cents']);
        $this->assertSame(1,CardFees::quote(17)['customer_fee_cents']);
        $this->assertSame(2,CardFees::quote(50)['customer_fee_cents']);
        $this->expectException(ValidationException::class);CardFees::quote(99_999_999);
    }
}
