<?php
namespace Tests\Feature;

use App\Models\{FinancialRevision, PriceVersion, QuickStripeLease, Resource, ServiceVariant, User};
use App\Services\Booking\QuickBook;
use App\Services\Records\{ConfirmCapacity, SaveVariant};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Queue, Schema};
use Illuminate\Support\Str;
use Tests\TestCase;

/** Cross-path regressions for Claude #200. Synthetic data only; no provider or deployment. */
class ExtraTimeAuditRegressionTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private PriceVersion $price;
    private CarbonImmutable $created;

    protected function setUp(): void
    {
        parent::setUp();
        $this->created = CarbonImmutable::parse('2026-11-04T10:00:00-06:00'); $this->travelTo($this->created);
        config(['waoo.sandbox_finance'=>true,'waoo.sandbox_documents'=>true,'waoo.sandbox_quick_stripe'=>true,
            'waoo.sandbox_quick_payments'=>true,'app.url'=>'https://crm.example.test']);
        Http::preventStrayRequests(); Queue::fake();
        $this->owner = User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $v=ServiceVariant::create(['name'=>'Música y Juegos','status'=>'active','duration_minutes'=>120,'setup_minutes'=>15,'breakdown_minutes'=>15,
            'requirements'=>[['kind'=>'staff','skill'=>'host','quantity'=>1]]]);
        $this->price=PriceVersion::create(['service_variant_id'=>$v->id,'amount_cents'=>52500,'currency'=>'USD','created_by'=>$this->owner->id]);
        Resource::create(['code'=>'HOST-1','name'=>'Sofía Méndez','kind'=>'staff','status'=>'active','skills'=>['host','driver']]);
    }

    protected function tearDown(): void
    {
        // This disposable synthetic database only; never weaken production rollback/evidence protections.
        if ($this->app && Schema::hasTable('quick_payment_bookings')) {
            foreach (['quick_payment_notifications','quick_payment_receipts','quick_payment_attempts','quick_payment_events','quick_payment_bookings'] as $t) DB::table($t)->delete();
            DB::table('quick_stripe_checkout_requests')->delete();
        }
        parent::tearDown();
    }

    private function input(CarbonImmutable $start): array
    {
        return ['new_customer'=>['name'=>'María González','phone'=>'+13465550100','email'=>'maria@example.test','locale'=>'es'],
            'title'=>'Cumpleaños de Sofía','event_type'=>'birthday','starts_at'=>$start->utc()->toIso8601String(),'ends_at'=>$start->addHours(2)->utc()->toIso8601String(),
            'timezone'=>'America/Chicago','postal_code'=>'77493','address'=>'123 Example St','travel_fee_cents'=>0,
            'services'=>[['price_version_id'=>$this->price->id]]];
    }

    public function test_time_extension_can_be_created_through_the_actual_catalog_service(): void
    {
        $variant=app(SaveVariant::class)->execute($this->owner,['name'=>'Hora extra','status'=>'active','duration_minutes'=>60,
            'setup_minutes'=>0,'breakdown_minutes'=>0,'extends_event'=>true,'requirements'=>[]]);
        $this->assertTrue($variant->extends_event);
        $this->assertTrue(\App\Services\Records\CharacterRecipe::compile($variant,[],'services.0.characters')['ready']);
    }

    public function test_new_tomorrow_booking_cannot_freeze_a_fifty_percent_payment_against_a_full_payment_target(): void
    {
        $start=CarbonImmutable::parse('2026-11-05T15:00:00-06:00'); // 29h notice: satisfies the new 24h rule
        $input=$this->input($start);$input['deposit_received']=false;
        app(QuickBook::class)->execute($this->owner,$input,(string)Str::uuid());
        $finance=FinancialRevision::sole();
        $this->assertSame(52500,$finance->deposit_target_cents);
        $this->assertSame($finance->deposit_target_cents,QuickStripeLease::sole()->payload['terms']['principal_cents']);
    }

    public function test_a_booking_paid_weeks_ago_keeps_its_frozen_deposit_when_owner_confirms_it_the_day_before(): void
    {
        $start=CarbonImmutable::parse('2026-11-24T15:00:00-06:00');
        $received=$this->created->subMinute()->toIso8601String();
        $input=$this->input($start);$input['deposit']=['method'=>'zelle','amount_cents'=>26250,'reference'=>'EXTRA-AUDIT-1','received_at'=>$received,'verified_received'=>true];
        $booking=app(QuickBook::class)->execute($this->owner,$input,(string)Str::uuid());
        $this->assertSame(26250,FinancialRevision::sole()->deposit_target_cents);
        $this->travelTo(CarbonImmutable::parse('2026-11-23T10:00:00-06:00')); // 29h before the existing party; balance is not due yet
        $event=$booking->event->fresh();
        $saved=app(ConfirmCapacity::class)->execute($this->owner,$event->id,['expected_version'=>$event->version,'staff_later'=>true,'resource_ids'=>[],
            'payment'=>['method'=>'external_zelle','amount_cents'=>26250,'reference'=>'EXTRA-AUDIT-CONFIRM','received_at'=>$received],
            'review'=>['short_notice_reason'=>'Confirmación administrativa de una reserva con depósito recibido al crearla.','transfers'=>[]]]);
        $this->assertSame('capacity_reserved',$saved->status);
        $this->assertSame(26250,FinancialRevision::sole()->deposit_target_cents);
    }

    private function extension(): PriceVersion
    {
        $v=app(SaveVariant::class)->execute($this->owner,['name'=>'Hora extra','status'=>'active','duration_minutes'=>60,
            'setup_minutes'=>0,'breakdown_minutes'=>0,'extends_event'=>true,'requirements'=>[]]);
        return PriceVersion::create(['service_variant_id'=>$v->id,'amount_cents'=>20000,'currency'=>'USD','created_by'=>$this->owner->id]);
    }

    public function test_extra_staff_must_cover_the_extension_as_well_as_the_base_package(): void
    {
        $hour=$this->extension();
        $v=ServiceVariant::create(['name'=>'Recreadora extra','status'=>'active','duration_minutes'=>120,
            'requirements'=>[['kind'=>'staff','skill'=>'host','quantity'=>1]]]);
        $p=PriceVersion::create(['service_variant_id'=>$v->id,'amount_cents'=>14000,'currency'=>'USD','created_by'=>$this->owner->id]);
        try {
            \App\Services\Records\SameLengthExtras::assertForQuick(array_map(fn($id)=>['price_version_id'=>$id],[$this->price->id,$hour->id,$p->id]),$this->price->id);
            $this->fail('Two-hour staff was accepted for a three-hour party.');
        } catch (\App\Exceptions\RecordWriteRejected $x) { $this->assertSame(422,$x->status); }
        $v->update(['duration_minutes'=>180]);
        \App\Services\Records\SameLengthExtras::assertForQuick(array_map(fn($id)=>['price_version_id'=>$id],[$this->price->id,$hour->id,$p->id]),null);
        $this->assertDatabaseCount('price_versions',3);
    }

    public function test_changing_the_live_extension_flag_cannot_reinterpret_a_saved_party(): void
    {
        $hour=$this->extension();$start=$this->created->addDays(20);
        $customer=\App\Models\Customer::create(['name'=>'María González','email'=>'maria@example.test','locale'=>'es']);
        $input=$this->input($start);unset($input['new_customer']);
        $event=app(\App\Services\Records\SaveEventDraft::class)->execute($this->owner,[...$input,'customer_id'=>$customer->id,
            'ends_at'=>$start->addHours(3)->toIso8601String(),'services'=>[['price_version_id'=>$this->price->id],['price_version_id'=>$hour->id]]]);
        ServiceVariant::whereKey($hour->service_variant_id)->update(['extends_event'=>false]);
        $saved=app(ConfirmCapacity::class)->execute($this->owner,$event->id,['expected_version'=>1,'staff_later'=>true,'resource_ids'=>[],
            'payment'=>['method'=>'external_zelle','amount_cents'=>36250,'reference'=>'SNAPSHOT-1','received_at'=>$this->created->subMinute()->toIso8601String()],
            'review'=>['transfers'=>[]]]);
        $this->assertSame('capacity_reserved',$saved->status);
    }

    public function test_an_extension_alone_is_not_a_complete_party(): void
    {
        $hour=$this->extension();$input=$this->input($this->created->addDays(20));
        $input['services']=[['price_version_id'=>$hour->id]];$input['deposit_received']=false;
        try { app(QuickBook::class)->execute($this->owner,$input,(string)Str::uuid());$this->fail('Sold time without a base service.'); }
        catch (\App\Exceptions\RecordWriteRejected $x) { $this->assertSame(422,$x->status); }
        $this->assertDatabaseCount('events',0);$this->assertDatabaseCount('financial_revisions',0);
    }

    private function openClient(object $booking): void
    {
        parse_str(parse_url($booking->portal_url,PHP_URL_FRAGMENT),$fragment);auth()->logout();
        $this->postJson('/portal/api/session',['token'=>$fragment['token']])->assertOk();
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/details',[
            'expected_event_version'=>1,'expected_revision'=>0,'first_name'=>'María','last_name'=>'González','phone'=>'+13465550100','email'=>'maria@example.test',
            'billing_address'=>'123 Example St','event_address'=>'123 Example St','event_postal_code'=>'77493','start_time_confirmed'=>true,
            'child_name'=>'Sofía','child_age'=>11,'event_language'=>'es','setup_area'=>'indoor','children_count'=>15])->assertCreated();
    }

    public function test_full_payment_has_no_half_payment_choice_and_checkout_stops_at_the_notice_cutoff(): void
    {
        $input=$this->input($this->created->addHours(29));$input['deposit_received']=false;
        $booking=app(QuickBook::class)->execute($this->owner,$input,(string)Str::uuid());$this->openClient($booking);
        config(['waoo.stripe_test_account'=>'acct_waoosandbox']);
        $g=new \Tests\Support\QuickPaymentGateway();$this->app->instance(\App\Services\Finance\StripeGateway::class,$g);
        $q=$this->getJson('/portal/api/quick-payment')->assertOk()->json();
        $this->assertSame([100],array_column($q['choices'],'deposit_percent'));
        $pin=array_intersect_key($q,array_flip(['expected_booking_version','expected_payment_sha256']));
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/quick-payment/checkout',[...$pin,'deposit_percent'=>50])->assertStatus(422);
        $this->assertSame(0,$g->creates);$this->assertDatabaseCount('payment_intents',0);
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/quick-payment/checkout',[...$pin,'deposit_percent'=>100])->assertOk();
        $request=\App\Models\QuickStripeCheckoutRequest::sole();$p=\App\Services\Booking\QuickPaymentCheckout::verify($request);
        $this->assertSame($this->created->addHours(5)->timestamp,$p['parameters']['expires_at']);
        $this->assertSame(52500,$p['terms']['principal_cents']);
    }

    public function test_a_new_unattended_hold_with_less_than_twenty_four_hours_is_refused_before_money(): void
    {
        $input=$this->input($this->created->addHours(20));$input['deposit_received']=false;
        try { app(QuickBook::class)->execute($this->owner,$input,(string)Str::uuid());$this->fail('Offered an unattended payment within 24 hours.'); }
        catch (\App\Exceptions\RecordWriteRejected $x) { $this->assertSame(409,$x->status); }
        $this->assertDatabaseCount('events',0);$this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('quick_stripe_leases',0);
    }

    public function test_new_checkout_is_refused_when_an_existing_hold_reaches_the_notice_cutoff(): void
    {
        $input=$this->input($this->created->addHours(29));$input['deposit_received']=false;
        $b=app(QuickBook::class)->execute($this->owner,$input,(string)Str::uuid());$this->openClient($b);
        config(['waoo.stripe_test_account'=>'acct_waoosandbox']);
        $g=new \Tests\Support\QuickPaymentGateway();$this->app->instance(\App\Services\Finance\StripeGateway::class,$g);
        $this->travelTo($this->created->addHours(6));
        $q=$this->getJson('/portal/api/quick-payment')->assertOk()->assertJsonPath('can_pay',false)->json();
        $pin=array_intersect_key($q,array_flip(['expected_booking_version','expected_payment_sha256']));
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/quick-payment/checkout',[...$pin,'deposit_percent'=>100])->assertStatus(409);
        $this->assertSame(0,$g->creates);$this->assertDatabaseCount('payment_intents',0);
    }

    public function test_actual_partial_zelle_is_recorded_but_does_not_promote_a_full_payment_booking(): void
    {
        $input=$this->input($this->created->addHours(29));$input['deposit_received']=false;
        $b=app(QuickBook::class)->execute($this->owner,$input,(string)Str::uuid());$this->openClient($b);
        $q=$this->getJson('/portal/api/quick-payment')->assertOk()->json();
        $pin=array_intersect_key($q,array_flip(['expected_booking_version','expected_payment_sha256']));
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/quick-payment/zelle',[...$pin,'amount_cents'=>26250,'reference'=>null])->assertCreated();
        $this->actingAs($this->owner);
        app(\App\Services\Finance\ReviewZelleNotice::class)->execute($this->owner,$b->event->id,\App\Models\ZelleNotice::sole()->id,[
            'decision'=>'verified','review_note'=>'Verifiqué el importe realmente recibido en el banco.','financial_revision_id'=>$b->financial_revision->id,
            'amount_cents'=>26250,'currency'=>'USD','reference'=>'EXTRA-PARTIAL-1','received_at'=>$this->created->toIso8601String(),'verified_received'=>true],(string)Str::uuid());
        $this->assertDatabaseHas('ledger_entries',['kind'=>'receipt','amount_cents'=>26250]);
        $this->assertSame('paid_needs_review',\App\Models\QuickPaymentBooking::sole()->state);
        $this->assertDatabaseCount('quick_bookings',0);
        $this->assertSame(26250,app(\App\Services\Finance\FinanceSummary::class)->forEvent($b->event)['deposit_shortfall_cents']);
    }

    public function test_valid_base_plus_extension_uses_real_compilation_and_preserves_money_and_replay(): void
    {
        $hour=$this->extension();$input=$this->input($this->created->addDays(20));
        $input['ends_at']=$this->created->addDays(20)->addHours(3)->toIso8601String();
        $input['services'][]=['price_version_id'=>$hour->id];$input['deposit_received']=false;$key=(string)Str::uuid();
        $b=app(QuickBook::class)->execute($this->owner,$input,$key);
        $lease=QuickStripeLease::sole();$expiry=$lease->expires_at->timestamp;
        $this->assertTrue($b->event->services()->where('price_version_id',$hour->id)->sole()->extends_event_snapshot);
        $this->assertSame(72500,$b->financial_revision->total_cents);
        $this->assertSame(36250,$lease->payload['terms']['principal_cents']);
        $this->assertSame(180,\App\Models\EventService::partyMinutes($b->event->services));
        $this->travel(5)->minutes();app(QuickBook::class)->execute($this->owner,$input,$key);
        $this->assertSame($expiry,QuickStripeLease::sole()->expires_at->timestamp);$this->assertDatabaseCount('events',1);
    }

    public function test_the_owner_request_also_limits_provider_expiry_without_rewriting_lease_v1(): void
    {
        $customer=\App\Models\Customer::create(['name'=>'María González','email'=>'maria@example.test','locale'=>'es']);
        $input=$this->input($this->created->addHours(29));unset($input['new_customer']);
        $e=app(\App\Services\Records\SaveEventDraft::class)->execute($this->owner,[...$input,'customer_id'=>$customer->id]);
        $f=app(\App\Services\Finance\CreateFinancialRevision::class)->execute($this->owner,$e->id,['expected_version'=>1],(string)Str::uuid());
        $l=app(\App\Services\Booking\PrepareQuickStripeLease::class)->execute($this->owner,$e->id,['expected_event_version'=>1,'financial_revision_id'=>$f->id],(string)Str::uuid());
        config(['waoo.stripe_test_account'=>'acct_waoosandbox']);
        $r=app(\App\Services\Booking\PrepareQuickStripeCheckoutRequest::class)->execute($this->owner,$e->id,[
            'expected_event_version'=>1,'expected_lease_version'=>$l['version'],'expected_terms_sha256'=>$l['terms']['sha256']],(string)Str::uuid());
        $p=\App\Services\Booking\QuickStripeCheckoutRequests::verify(\App\Models\QuickStripeCheckoutRequest::sole(),QuickStripeLease::sole())['envelope'];
        $this->assertSame($this->created->addHours(5)->timestamp,$p['parameters']['expires_at']);
        $this->assertSame(1,QuickStripeLease::sole()->payload['schema_version']);
        $this->assertSame($this->created->addHours(48)->timestamp,QuickStripeLease::sole()->expires_at->timestamp);
    }

    public function test_full_length_extra_staff_does_not_add_the_extension_a_second_time(): void
    {
        $hour=$this->extension();
        $v=ServiceVariant::create(['name'=>'Recreadora extra · 3 h','status'=>'active','duration_minutes'=>180,
            'requirements'=>[['kind'=>'staff','skill'=>'host','quantity'=>1]]]);
        $p=PriceVersion::create(['service_variant_id'=>$v->id,'amount_cents'=>21000,'currency'=>'USD','created_by'=>$this->owner->id]);
        Resource::create(['code'=>'HOST-2','name'=>'Ana López','kind'=>'staff','status'=>'active','skills'=>['host','driver']]);
        $input=$this->input($this->created->addDays(20));$input['ends_at']=$this->created->addDays(20)->addHours(3)->toIso8601String();
        $input['services'][]=['price_version_id'=>$hour->id];$input['services'][]=['price_version_id'=>$p->id];$input['deposit_received']=false;
        $b=app(QuickBook::class)->execute($this->owner,$input,(string)Str::uuid());
        $this->assertSame(180,\App\Models\EventService::partyMinutes($b->event->services));
        $pairs=array_map(fn($id)=>[$v=ServiceVariant::find(PriceVersion::find($id)->service_variant_id),$v->duration_minutes],[$this->price->id,$hour->id,$p->id]);
        $this->assertSame(180,ServiceVariant::partyMinutes($pairs));
        $this->assertSame(93500,$b->financial_revision->total_cents);
    }

    public function test_a_legacy_schema_two_request_can_still_record_its_actual_payment(): void
    {
        $input=$this->input($this->created->addDays(20));$input['deposit_received']=false;
        $b=app(QuickBook::class)->execute($this->owner,$input,(string)Str::uuid());$this->openClient($b);
        config(['waoo.stripe_test_account'=>'acct_waoosandbox']);
        $g=new \Tests\Support\QuickPaymentGateway();$this->app->instance(\App\Services\Finance\StripeGateway::class,$g);
        $q=$this->getJson('/portal/api/quick-payment')->assertOk()->json();$pin=array_intersect_key($q,array_flip(['expected_booking_version','expected_payment_sha256']));
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/quick-payment/checkout',[...$pin,'deposit_percent'=>50])->assertOk();
        // Emulate an immutable pre-upgrade schema2 fixture, including its original audit and attempt binding. Synthetic DB only.
        $r=\App\Models\QuickStripeCheckoutRequest::sole();$p=$r->envelope;$p['schema_version']=2;
        $hash=\App\Services\Finance\MutationKey::hash($p);
        $encoded=(new \App\Models\QuickStripeCheckoutRequest(['envelope'=>$p]))->getAttributes()['envelope'];
        DB::table('quick_stripe_checkout_requests')->where('id',$r->id)->update(['envelope'=>$encoded,'envelope_sha256'=>$hash]);
        DB::table('quick_payment_attempts')->where('id',$r->id)->update(['request_sha256'=>$hash]);
        DB::table('audit_entries')->where('action','quick_payment.request_prepared')->where('subject_id',$r->id)
            ->update(['after'=>json_encode(['event_id'=>$b->event->id,'sha256'=>$hash])]);
        $this->assertSame(2,\App\Services\Booking\QuickPaymentCheckout::verify($r->fresh())['schema_version']);
        $sid=\App\Models\PaymentIntent::sole()->stripe_checkout_session_id;$g->pay($sid);$id='evt_'.str_replace('-','',Str::uuid());
        $payload=['id'=>$id,'type'=>'checkout.session.completed','livemode'=>false,'data'=>['object'=>['id'=>$sid,'object'=>'checkout.session','metadata'=>$g->sessions[$sid]['metadata']]]];
        \App\Models\WebhookReceipt::create(['id'=>$id,'event_type'=>'checkout.session.completed','raw_sha256'=>hash('sha256',json_encode($payload)),
            'encrypted_payload'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode($payload)),'verified_at'=>now(),'state'=>'queued','attempts'=>0]);
        app(\App\Services\Finance\StripeReceiptRouter::class)->execute($id);
        $this->assertSame('paid',\App\Models\QuickPaymentBooking::sole()->state);
        $this->assertDatabaseHas('ledger_entries',['kind'=>'receipt','amount_cents'=>26250]);
        $this->assertDatabaseHas('ledger_entries',['kind'=>'customer_fee','amount_cents'=>788]);
    }

    public function test_a_new_quote_for_tomorrow_uses_the_same_full_payment_target(): void
    {
        $c=\App\Models\Customer::create(['name'=>'María González','email'=>'maria@example.test','locale'=>'es']);
        $input=$this->input($this->created->addHours(29));unset($input['new_customer']);
        $event=app(\App\Services\Records\SaveEventDraft::class)->execute($this->owner,[...$input,'customer_id'=>$c->id]);
        $q=\App\Services\Quotes\QuoteSnapshot::capture($event,$this->owner,['expected_event_version'=>1,'discounts'=>[]]);
        $this->assertSame(52500,$q['deposit_target_cents']);
    }
}
