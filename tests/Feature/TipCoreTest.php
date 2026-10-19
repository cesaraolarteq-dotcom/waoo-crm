<?php
namespace Tests\Feature;
use App\Models\{Event,FinancialRevision,LedgerEntry,Resource,TipGrant,TipCheckoutIntent,TipReceipt,User,WebhookReceipt};
use App\Services\Finance\{CreateFinancialRevision,RecordZellePayment,ConfirmPaidCapacity,StripeGateway,StripeReceiptProcessor,FinanceSummary};
use App\Services\Tips\{IssueTipGrant,RevokeTipGrant,TipContext,TipAmounts};
use App\Jobs\ProcessStripeWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{Crypt,DB,Http,Mail,Queue};
use Illuminate\Support\Str;
use Tests\Support\{QuoteScenario,FakeStripeGateway,ConcurrentWorkers};
use Tests\TestCase;
class TipCoreTest extends TestCase {
    use DatabaseMigrations,QuoteScenario;
    private FinancialRevision $finance;private FakeStripeGateway $stripe;
    protected function setUp():void {
        parent::setUp();$this->travelTo(CarbonImmutable::parse('2026-10-19T15:00:00Z'));$this->scenario();$this->withCredentials();
        config(['waoo.sandbox_tips'=>true,'waoo.stripe_test_secret'=>'sk_test_fixture','waoo.stripe_test_account'=>'acct_fixture','waoo.stripe_test_webhook_secret'=>'whsec_fixture']);
        Http::preventStrayRequests();Mail::fake();$this->stripe=new FakeStripeGateway();$this->app->instance(StripeGateway::class,$this->stripe);
        $this->finance=app(CreateFinancialRevision::class)->execute($this->owner,$this->event->id,['expected_version'=>1],(string)Str::uuid());
        app(RecordZellePayment::class)->execute($this->owner,$this->event->id,['financial_revision_id'=>$this->finance->id,'amount_cents'=>$this->finance->deposit_target_cents,'currency'=>'USD','reference'=>'TIP-FIXTURE-DEPOSIT','received_at'=>now()->subMinute()->toIso8601String(),'verified_received'=>true],(string)Str::uuid());
        $staff=Resource::create(['code'=>'TIP-STAFF','name'=>"Lucia\u{00a0}PRIVATE_STAFF_SURNAME",'kind'=>'staff','status'=>'active','skills'=>['performer','driver']]);
        $speaker=Resource::create(['code'=>'TIP-SPEAKER','name'=>'PRIVATE_EQUIPMENT','kind'=>'equipment','status'=>'active','equipment_kind'=>'speaker','skills'=>[]]);
        $this->event=app(ConfirmPaidCapacity::class)->execute($this->owner,$this->event->id,['expected_version'=>1,'financial_revision_id'=>$this->finance->id,'resource_ids'=>[$staff->id,$speaker->id],
            'review'=>['staff_confirmed'=>true,'cargo_checked'=>true,'origin_return_note'=>'PRIVATE_OPERATIONAL_NOTES','transfers'=>[]]]);
        $this->travelTo($this->event->ends_at->addHour());
    }
    private function issue(?string $key=null):array {return app(IssueTipGrant::class)->execute($this->owner,$this->event->id,['expected_event_version'=>$this->event->version,'financial_revision_id'=>$this->finance->id,'locale'=>'es'],$key??(string)Str::uuid());}
    private function enter(?array $grant=null):string {$g=$grant??$this->issue();auth()->logout();$r=$this->postJson('/portal/tip/session',['token'=>parse_url($g['link'],PHP_URL_FRAGMENT)])->assertOk();return $r->getCookie(TipContext::COOKIE)->getValue();}
    private function input(string $option='pct_10'):array {$g=TipGrant::latest('id')->firstOrFail();return ['financial_revision_id'=>$g->financial_revision_id,'snapshot_sha256'=>$g->snapshot_sha256,'option'=>$option];}
    private function checkout(string $cookie,?array $input=null,?string $key=null){return $this->withCookie(TipContext::COOKIE,$cookie)->withHeader('Idempotency-Key',$key??(string)Str::uuid())->postJson('/portal/api/tip/checkout',$input??$this->input());}
    private function webhook(TipCheckoutIntent $i,string $id='evt_tip_fixture',?array $metadata=null,array $extra=[]){$s=$this->stripe->sessions[$i->provider_session_id];$e=['id'=>$id,'object'=>'event','type'=>'checkout.session.completed','livemode'=>false,'created'=>time(),'data'=>['object'=>['id'=>$s['id'],'object'=>'checkout.session','payment_intent'=>is_array($s['payment_intent'])?$s['payment_intent']['id']:$s['payment_intent'],'metadata'=>$metadata??$s['metadata'],'billing_details'=>['name'=>'PRIVATE_PROVIDER_NAME']]],...$extra];
        $raw=json_encode($e,JSON_THROW_ON_ERROR);$time=time();return $this->call('POST','/webhooks/stripe',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_STRIPE_SIGNATURE'=>'t='.$time.',v1='.hash_hmac('sha256',$time.'.'.$raw,'whsec_fixture')],$raw);}
    private function process(string $id='evt_tip_fixture'):void {(new ProcessStripeWebhook($id))->handle(app(StripeReceiptProcessor::class));}
    public function test_issuance_is_pinned_recoverable_only_while_current_and_does_not_log_the_token():void {
        $key=(string)Str::uuid();$g=$this->issue($key);$this->assertSame($g,$this->issue($key));$row=TipGrant::sole();
        $this->assertTrue($this->event->ends_at->utc()->addDays(14)->equalTo($row->expires_at));
        $token=parse_url($g['link'],PHP_URL_FRAGMENT);$this->assertStringNotContainsString($token,json_encode(DB::table('audit_entries')->get()));$this->assertArrayNotHasKey('encrypted_token',$row->toArray());
        app(RevokeTipGrant::class)->execute($this->owner,$row->id,['expected_version'=>1],(string)Str::uuid());
        $this->expectException(\App\Exceptions\RecordWriteRejected::class);$this->issue($key);
    }
    public function test_honeybook_future_cancelled_and_expired_events_cannot_issue():void {
        $url='/admin/api/events/'.$this->event->id.'/tip-grants';$input=['expected_event_version'=>$this->event->version,'financial_revision_id'=>$this->finance->id,'locale'=>'es'];
        $this->event->forceFill(['responsible_system'=>'honeybook'])->save();$this->postJson($url,$input,['Idempotency-Key'=>(string)Str::uuid()])->assertConflict()->assertJsonPath('code','responsible_system_honeybook');
        $this->event->forceFill(['responsible_system'=>'crm'])->save();$this->travelTo($this->event->starts_at->subHour());$this->postJson($url,$input,['Idempotency-Key'=>(string)Str::uuid()])->assertConflict();
        $this->travelTo($this->event->ends_at->addDays(14));$this->postJson($url,$input,['Idempotency-Key'=>(string)Str::uuid()])->assertConflict();
        $this->travelTo($this->event->ends_at->addHour());$this->event->forceFill(['status'=>'cancelled'])->save();$this->postJson($url,$input,['Idempotency-Key'=>(string)Str::uuid()])->assertConflict();$this->assertDatabaseCount('tip_grants',0);
    }
    public function test_page_and_projection_only_show_first_names_date_total_and_server_options_es_en():void {
        $g=$this->issue();$this->get('/portal/tip')->assertForbidden();$cookie=$this->enter($g);
        $r=$this->withCookie(TipContext::COOKIE,$cookie)->getJson('/portal/api/tip')->assertOk()->assertHeader('Cache-Control','no-store, private')->assertHeader('X-Robots-Tag','noindex, nofollow')
            ->assertJsonPath('preview',false)->assertJsonPath('first_name','Fictional')->assertJsonPath('tip.team','Lucia')->assertJsonPath('tip.total','$125.01')->assertJsonPath('tip.options.1.amount_cents',1300);
        foreach(['PRIVATE_','fee','deposit','ledger','email','phone','token_hash','encrypted_token','provider_'] as $secret)$this->assertStringNotContainsString($secret,$r->getContent());
        $this->withCookie(TipContext::COOKIE,$cookie)->get('/portal/tip?lang=en')->assertOk()->assertSee('Lucia')->assertSee('Tip')->assertDontSee('PRIVATE_');
        $this->assertDatabaseCount('tip_checkout_intents',0);Mail::assertNothingSent();
    }
    public function test_amounts_half_up_custom_limits_and_unknown_keys_are_enforced_server_side():void {
        $this->assertSame([3100,6200,9200],array_column(TipAmounts::options(61500),'amount_cents'));$this->assertSame([],TipAmounts::options(1));$cookie=$this->enter();
        foreach([0.01,'0.99','2000.01','-5','1e3','01','2,000',2000] as $amount)$this->checkout($cookie,[...$this->input('custom'),'custom_amount'=>$amount])->assertUnprocessable();
        $this->checkout($cookie,[...$this->input(),'amount_cents'=>1])->assertUnprocessable();$this->checkout($cookie,[...$this->input(),'custom_amount'=>'1'])->assertUnprocessable();
        $this->checkout($cookie,[...$this->input('custom'),'custom_amount'=>'2000.00'])->assertCreated();$this->assertSame(200000,TipCheckoutIntent::sole()->amount_cents);$p=$this->stripe->calls[0]['parameters'];$this->assertSame(['card'],$p['payment_method_types']);$this->assertCount(1,$p['line_items']);$this->assertSame(200000,$p['line_items'][0]['price_data']['unit_amount']);$this->assertSame('tip',$p['metadata']['kind']);$this->assertArrayNotHasKey('local_intent_id',$p['metadata']);
    }
    public function test_unknown_provider_result_only_retries_same_key_and_does_not_open_another_collection():void {
        $cookie=$this->enter();$key=(string)Str::uuid();$input=$this->input();$this->stripe->failAfterCreate=true;
        $this->checkout($cookie,$input,$key)->assertStatus(503);$this->checkout($cookie,$input)->assertConflict();$this->checkout($cookie,[...$input,'option'=>'pct_5'],$key)->assertConflict();
        $r=$this->checkout($cookie,$input,$key)->assertCreated();$this->checkout($cookie,$input,$key)->assertCreated()->assertJsonPath('intent_id',$r->json('intent_id'));
        $this->assertDatabaseCount('tip_checkout_intents',1);$this->assertCount(1,$this->stripe->sessions);$this->assertSame($this->stripe->calls[0]['key'],$this->stripe->calls[1]['key']);
    }
    public function test_verified_signed_receipt_dedupes_and_never_changes_booking_balance_reservations_or_money_reports():void {
        $before=json_encode(app(FinanceSummary::class)->forEvent($this->event));$period=['period'=>'month','anchor'=>now()->setTimezone('America/Chicago')->toDateString()];$report=app(\App\Services\Reports\OperationalReport::class)->execute($this->owner,$period);$ledger=LedgerEntry::all()->toJson();$reservations=DB::table('resource_reservations')->get()->toJson();
        $cookie=$this->enter();$key=(string)Str::uuid();$this->checkout($cookie,key:$key)->assertCreated();$i=TipCheckoutIntent::sole();$this->stripe->paid($i->provider_session_id);
        $this->webhook($i)->assertOk();$this->webhook($i)->assertOk();$this->process();$this->webhook($i,'evt_tip_second')->assertOk();$this->process('evt_tip_second');
        $this->assertSame('processed',WebhookReceipt::findOrFail('evt_tip_fixture')->state,json_encode(DB::table('tip_reconciliations')->get()));$tip=TipReceipt::sole();$this->assertSame(1300,$tip->gross_cents);$this->assertNull($tip->processing_fee_cents);$this->assertSame('paid',$i->fresh()->status);$this->assertNull($i->fresh()->active_grant_id);
        $this->assertSame($ledger,LedgerEntry::all()->toJson());$this->assertSame($before,json_encode(app(FinanceSummary::class)->forEvent($this->event)));$this->assertSame($report,app(\App\Services\Reports\OperationalReport::class)->execute($this->owner,$period));$this->assertSame($reservations,DB::table('resource_reservations')->get()->toJson());
        $this->assertDatabaseCount('mail_occurrences',0);$this->assertStringNotContainsString('PRIVATE_PROVIDER_NAME',Crypt::decryptString(WebhookReceipt::first()->encrypted_payload));
        $this->withCookie(TipContext::COOKIE,$cookie)->getJson('/portal/api/tip?checkout='.$i->id)->assertOk()->assertJsonPath('tip.state','paid');$this->withCookie(TipContext::COOKIE,$cookie)->getJson('/portal/api/tip')->assertJsonPath('tip.state','open');
        $this->checkout($cookie,key:$key)->assertCreated()->assertJsonPath('status','paid')->assertJsonPath('checkout_url',null);$this->checkout($cookie)->assertCreated();$this->assertDatabaseCount('tip_checkout_intents',2);
        $this->actingAs($this->owner)->getJson('/admin/api/events/'.$this->event->id.'/tips')->assertOk()->assertJsonPath('gross_cents',1300)->assertJsonPath('net_staff_cents',1300);
        $this->expectException(\LogicException::class);$tip->update(['gross_cents'=>0]);
    }
    public function test_revoked_or_honeybook_links_cannot_charge_but_real_late_tip_survives_as_reconciliation():void {
        $g=$this->issue();$cookie=$this->enter($g);$this->checkout($cookie)->assertCreated();$i=TipCheckoutIntent::sole();$this->actingAs($this->owner);
        app(RevokeTipGrant::class)->execute($this->owner,$g['id'],['expected_version'=>1],(string)Str::uuid());auth()->logout();
        $this->checkout($cookie)->assertConflict();$this->event->forceFill(['responsible_system'=>'honeybook'])->save();$this->stripe->paid($i->provider_session_id);
        $this->webhook($i)->assertOk();$this->process();$this->assertDatabaseCount('tip_receipts',1);$this->assertSame('paid',$i->fresh()->status);$this->assertDatabaseHas('tip_reconciliations',['reason'=>'late_tip_source_changed']);$this->assertDatabaseCount('ledger_entries',1);
    }
    public function test_wrong_provider_amount_and_namespace_never_become_booking_money_or_a_tip():void {
        $cookie=$this->enter();$this->checkout($cookie)->assertCreated();$i=TipCheckoutIntent::sole();$this->stripe->paid($i->provider_session_id);
        $m=$this->stripe->sessions[$i->provider_session_id]['metadata'];$this->webhook($i,'evt_tip_conflict',[...$m,'local_intent_id'=>(string)Str::uuid()])->assertOk();$this->process('evt_tip_conflict');
        $this->assertSame('tip_namespace_conflict',WebhookReceipt::findOrFail('evt_tip_conflict')->last_error);$this->assertDatabaseCount('tip_receipts',0);$this->assertDatabaseCount('ledger_entries',1);
        $this->stripe->sessions[$i->provider_session_id]['amount_total']=1;$this->webhook($i,'evt_tip_bad_amount')->assertOk();$this->process('evt_tip_bad_amount');$this->assertDatabaseCount('tip_receipts',0);
    }
    public function test_session_and_return_hint_do_not_allow_another_grant_or_prove_payment():void {
        $g=$this->issue();$other=$this->issue();$cookie=$this->enter($g);$this->checkout($cookie)->assertCreated();$i=TipCheckoutIntent::sole();
        $this->withCookie(TipContext::COOKIE,$cookie)->getJson('/portal/api/tip?checkout='.$i->id)->assertJsonPath('tip.state','open');
        $this->withCookie(TipContext::COOKIE,$cookie)->get('/portal/tip?checkout='.$i->id)->assertOk()->assertDontSee('Thank you');
        $cookie=$this->enter($other);$this->withCookie(TipContext::COOKIE,$cookie)->getJson('/portal/api/tip/checkouts/'.$i->id)->assertNotFound();$this->withCookie(TipContext::COOKIE,$cookie)->getJson('/portal/api/tip?checkout='.$i->id)->assertNotFound();
    }
    public function test_flags_roles_origin_and_staging_https_are_closed_and_cookie_cannot_authorize_admin():void {
        $g=$this->issue();$cookie=$this->enter($g);$this->withCookie(TipContext::COOKIE,$cookie)->getJson('/admin/api/events/'.$this->event->id.'/tips')->assertUnauthorized();
        $this->withHeader('Origin','https://evil.example.test')->postJson('/portal/tip/session',['token'=>parse_url($g['link'],PHP_URL_FRAGMENT)])->assertForbidden();$this->withHeader('Origin','https://localhost');
        config(['waoo.sandbox_tips'=>false]);$this->withCookie(TipContext::COOKIE,$cookie)->getJson('/portal/api/tip')->assertForbidden();config(['waoo.sandbox_tips'=>true]);
        $this->app->detectEnvironment(fn()=>'staging');config(['session.secure'=>true]);$this->getJson('http://localhost/portal/api/tip')->assertForbidden();$this->app->detectEnvironment(fn()=>'testing');
        $this->actingAs($this->owner);$this->withCookie(TipContext::COOKIE,$cookie)->getJson('/portal/api/tip')->assertForbidden();
    }
    public static function receiptPolicies():array{return ['crm'=>['crm'],'honeybook'=>['honeybook'],'revoked'=>['revoked']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('receiptPolicies')]
    public function test_receipt_before_create_response_remains_paid_and_retains_verified_identity(string $policy):void {
        $cookie=$this->enter();$fake=$this->stripe;
        $arrived=function(array $s)use($policy){
            if($policy==='honeybook')$this->event->forceFill(['responsible_system'=>'honeybook'])->save();
            if($policy==='revoked')TipGrant::sole()->forceFill(['revoked_at'=>now(),'encrypted_token'=>null])->save();
            $this->stripe->paid($s['id']);$i=TipCheckoutIntent::sole();$i->provider_session_id=$s['id'];$this->webhook($i)->assertOk();$this->process();
        };
        $this->app->instance(StripeGateway::class,new class($fake,$arrived) implements StripeGateway {
            public function __construct(private FakeStripeGateway $fake,private \Closure $arrived){}
            public function createCheckout(array $p,string $key):array{$s=$this->fake->createCheckout($p,$key);($this->arrived)($s);return $s;}
            public function retrieveCheckout(string $id):array{return $this->fake->retrieveCheckout($id);}
            public function expireCheckout(string $id):void{$this->fake->expireCheckout($id);}
        });
        $r=$this->checkout($cookie);if($policy==='crm')$r->assertCreated()->assertJsonPath('status','paid')->assertJsonPath('checkout_url',null);else $r->assertConflict();
        $i=TipCheckoutIntent::sole();$this->assertSame('paid',$i->status);$this->assertSame('pi_fixture_'.str_replace('-','',$i->id),$i->provider_payment_id);$this->assertNull($i->active_event_id);$this->assertDatabaseCount('tip_receipts',1);$this->assertDatabaseCount('ledger_entries',1);$this->assertSame([],$fake->expired);
    }
    public function test_a_new_grant_cannot_bypass_an_unknown_attempt_for_the_same_party():void {
        $first=$this->issue();$second=$this->issue();$cookie=$this->enter($first);$this->stripe->failAfterCreate=true;$this->checkout($cookie)->assertStatus(503);
        $cookie=$this->enter($second);$this->checkout($cookie)->assertConflict();$this->assertDatabaseCount('tip_checkout_intents',1);$this->assertCount(1,$this->stripe->sessions);
    }
    public function test_confirmed_external_expiry_releases_slots_without_deleting_evidence_or_inventing_a_refund():void {
        $cookie=$this->enter();$this->checkout($cookie)->assertCreated();$i=TipCheckoutIntent::sole();$this->actingAs($this->owner);
        app(RevokeTipGrant::class)->execute($this->owner,$i->tip_grant_id,['expected_version'=>1],(string)Str::uuid());
        (new \App\Jobs\ExpireTipCheckout($i->id))->handle();$this->assertSame('expired',$i->fresh()->status);$this->assertNull($i->fresh()->active_event_id);$this->assertDatabaseCount('tip_refunds',0);$this->assertDatabaseCount('tip_receipts',0);$this->assertDatabaseCount('tip_checkout_intents',1);
    }
    public function test_tips_ui_off_does_not_drop_a_verified_late_receipt_or_unsupported_refund_evidence():void {
        $cookie=$this->enter();$this->checkout($cookie)->assertCreated();$i=TipCheckoutIntent::sole();$this->stripe->paid($i->provider_session_id);config(['waoo.sandbox_tips'=>false]);
        $this->webhook($i)->assertOk();$this->process();$this->assertDatabaseCount('tip_receipts',1);
        $this->webhook($i,'evt_tip_refund',extra:['type'=>'charge.refunded'])->assertOk();$this->process('evt_tip_refund');$this->assertDatabaseHas('tip_reconciliations',['reason'=>'tip_refund_or_unsupported_event']);$this->assertDatabaseCount('tip_refunds',0);$this->assertSame('paid',$i->fresh()->status);
    }
    public function test_integrity_checks_session_expiry_and_source_change_never_charge_from_a_stale_page():void {
        $cookie=$this->enter();$this->assertSame(86400,(int)CarbonImmutable::parse(DB::table('tip_portal_sessions')->sole()->expires_at,'UTC')->diffInSeconds(CarbonImmutable::now('UTC'),true));
        $this->travelTo(CarbonImmutable::now('UTC')->addDay());$this->withCookie(TipContext::COOKIE,$cookie)->getJson('/portal/api/tip')->assertUnauthorized();
        $this->travelTo($this->event->ends_at->addHour());$this->actingAs($this->owner);$cookie=$this->enter();
        DB::table('financial_revisions')->where('id',$this->finance->id)->increment('total_cents',100);
        $this->checkout($cookie)->assertConflict();$this->assertSame([],$this->stripe->calls);
    }
    public function test_both_real_workers_share_one_checkout_and_two_signed_receipts_allocate_one_tip():void {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB+pcntl required');
        $cookie=$this->enter();$req=\Illuminate\Http\Request::create('https://localhost/portal/api/tip/checkout','POST',[],[TipContext::COOKIE=>$cookie]);$req->setLaravelSession(app('session')->driver());$req->setUserResolver(fn()=>null);$context=TipContext::fromRequest($req);$input=$this->input();$key=(string)Str::uuid();
        $run=fn()=>app(\App\Services\Tips\CreateTipCheckout::class)->execute($context,$input,$key);$results=ConcurrentWorkers::run([$run,$run]);
        $this->assertIsArray($results[0]);$this->assertSame($results[0],$results[1]);$this->assertDatabaseCount('tip_checkout_intents',1);
        // Children have independent fake provider memory; reconstruct the same idempotent remote session in the parent.
        $i=TipCheckoutIntent::sole();$m=['kind'=>'tip','local_tip_intent_id'=>$i->id,'event_id'=>(string)$i->event_id,'grant_id'=>(string)$i->tip_grant_id];
        $this->stripe->createCheckout(['client_reference_id'=>$i->id,'metadata'=>$m,'line_items'=>[['price_data'=>['unit_amount'=>$i->amount_cents]]]],'waoo-tip-test:'.$i->id);$this->stripe->paid($i->provider_session_id);
        $this->webhook($i,'evt_tip_race_a')->assertOk();$this->webhook($i,'evt_tip_race_b')->assertOk();
        $worker=fn($id)=>function()use($id){app(\App\Services\Finance\StripeReceiptRouter::class)->execute($id);return TipReceipt::count();};$r=ConcurrentWorkers::run([$worker('evt_tip_race_a'),$worker('evt_tip_race_b')]);$this->assertSame([1,1],$r);$this->assertDatabaseCount('tip_receipts',1);$this->assertDatabaseCount('ledger_entries',1);
    }
    public function test_uncertain_attempt_past_retry_window_does_not_assume_remote_expiry():void {
        $cookie=$this->enter();$key=(string)Str::uuid();$input=$this->input();$this->stripe->failAfterCreate=true;$this->checkout($cookie,$input,$key)->assertStatus(503);
        $this->travelTo(CarbonImmutable::now('UTC')->addHours(23)->addMinutes(30));$this->checkout($cookie,$input,$key)->assertConflict();$this->checkout($cookie)->assertConflict();
        $this->assertSame('reconciliation_required',TipCheckoutIntent::sole()->status);$this->assertNotNull(TipCheckoutIntent::sole()->active_event_id);$this->assertCount(1,$this->stripe->calls);$this->assertDatabaseCount('tip_receipts',0);
    }
    public function test_swapped_token_vault_and_unconfirmed_owner_never_reveal_a_link():void {
        $key=(string)Str::uuid();$a=$this->issue($key);$b=$this->issue();DB::table('tip_grants')->where('id',$a['id'])->update(['encrypted_token'=>TipGrant::findOrFail($b['id'])->encrypted_token]);
        try{$this->issue($key);$this->fail('Must not reveal swapped vault');}catch(\App\Exceptions\RecordWriteRejected $e){$this->assertSame(409,$e->status);}
        $this->owner->forceFill(['two_factor_confirmed_at'=>null])->save();$this->postJson('/admin/api/events/'.$this->event->id.'/tip-grants',['expected_event_version'=>$this->event->version,'financial_revision_id'=>$this->finance->id,'locale'=>'es'],['Idempotency-Key'=>(string)Str::uuid()])->assertForbidden();
    }
    public function test_staging_exchange_requires_csrf_and_sets_a_secure_lax_http_only_cookie():void {
        $g=$this->issue();auth()->logout();$token=parse_url($g['link'],PHP_URL_FRAGMENT);$this->app->detectEnvironment(fn()=>'staging');config(['session.secure'=>true]);
        $this->postJson('https://localhost/portal/tip/session',['token'=>$token])->assertStatus(419)->assertHeader('Cache-Control','no-store, private');
        $csrf='synthetic-csrf-token';$r=$this->withSession(['_token'=>$csrf])->withHeader('X-CSRF-TOKEN',$csrf)->postJson('https://localhost/portal/tip/session',['token'=>$token])->assertOk();
        $cookie=$r->getCookie(TipContext::COOKIE);$this->assertTrue($cookie->isSecure());$this->assertTrue($cookie->isHttpOnly());$this->assertSame('lax',$cookie->getSameSite());$this->assertSame('/portal',$cookie->getPath());
    }
    protected function tearDown():void {if($this->app)$this->app->detectEnvironment(fn()=>'testing');parent::tearDown();}
}
