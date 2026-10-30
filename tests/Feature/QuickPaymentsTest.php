<?php
namespace Tests\Feature;

use App\Models\{Character, Customer, Event, LedgerEntry, PriceVersion, QuickPaymentBooking, Resource, ServiceVariant, User};
use App\Services\Booking\{QuickBook, QuickPaymentProtocol, UnpaidQuickBooking};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class QuickPaymentsTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private array $input;
    protected function setUp():void
    {
        parent::setUp(); $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-21T12:00:00-05:00'));
        config(['waoo.sandbox_finance'=>true,'waoo.sandbox_documents'=>true,'waoo.sandbox_quick_stripe'=>true,'waoo.sandbox_quick_payments'=>true]);
        Http::preventStrayRequests();
        $this->owner=User::factory()->create(['name'=>'María González','role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);$this->actingAs($this->owner);
        $c=Character::create(['name'=>'Mickey','active'=>true,'outfits'=>['0']]);
        Resource::create(['code'=>'MICKEY-1','name'=>'Cabeza de Mickey','kind'=>'costume','status'=>'active','character_id'=>$c->id,'skills'=>[]]);
        $v=ServiceVariant::create(['name'=>'Música y juegos','status'=>'active','duration_minutes'=>120,'character_quantity'=>1,'requirements'=>[],
            'character_options'=>[['character_id'=>$c->id,'requirements'=>[['kind'=>'costume','character_id'=>$c->id,'quantity'=>1],['kind'=>'staff','quantity'=>1,'skill'=>'performer']]]]]);
        $p=PriceVersion::create(['service_variant_id'=>$v->id,'amount_cents'=>30001,'currency'=>'USD','created_by'=>$this->owner->id]);
        $this->input=['deposit_received'=>false,'new_customer'=>['name'=>null,'phone'=>null,'email'=>null,'locale'=>'es'],
            'title'=>'Cumpleaños de Lucía','event_type'=>'birthday','starts_at'=>now()->addDays(20)->toIso8601String(),'ends_at'=>now()->addDays(20)->addHours(2)->toIso8601String(),
            'timezone'=>'America/Chicago','postal_code'=>'77493','address'=>'123 Oak Street','travel_fee_cents'=>0,
            'services'=>[['price_version_id'=>$p->id,'characters'=>[['character_id'=>$c->id,'quantity'=>1,'outfit'=>'0']]]]];
    }
    protected function tearDown():void
    {
        // Only this disposable synthetic database. Keep production rollback guard intact.
        foreach(['quick_payment_notifications','quick_payment_receipts','quick_payment_attempts','quick_payment_events','quick_payment_bookings'] as $t)DB::table($t)->delete();
        DB::table('quick_stripe_checkout_requests')->delete();
        DB::table('ledger_entries')->whereNull('external_reference')->update(['external_reference'=>'SYNTHETIC-ROLLBACK-ONLY']);
        DB::table('customers')->whereNull('name')->update(['name'=>'María González']);
        parent::tearDown();
    }
    public function test_unpaid_quick_booking_preserves_company_without_creating_payment(): void
    {
        config(['waoo.sandbox_company'=>true]);$this->input['billing_company']=['name'=>'Celebraciones del Sol'];
        $booking=$this->create();
        $this->assertSame('Celebraciones del Sol',Event::findOrFail($booking->event->id)->billing_company['name']);
        $this->assertDatabaseCount('ledger_entries',0);
        DB::table('events')->update(['billing_company'=>null,'billing_company_version'=>0]);
    }

    private function create(?string $key=null):UnpaidQuickBooking{return app(QuickBook::class)->execute($this->owner,$this->input,$key??(string)Str::uuid());}
    private function client(UnpaidQuickBooking $r):void
    {
        parse_str(parse_url($r->portal_url,PHP_URL_FRAGMENT),$fragment);auth()->logout();
        $this->postJson('/portal/api/session',['token'=>$fragment['token']])->assertOk();
    }
    private function details():void
    {
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/details',[
            'expected_event_version'=>1,'expected_revision'=>0,'first_name'=>'Elena','last_name'=>'Martínez','phone'=>'+13465550101','email'=>'elena@example.test',
            'billing_address'=>'123 Oak Street','event_address'=>'123 Oak Street','event_postal_code'=>'77493','start_time_confirmed'=>true,
            'child_name'=>'Lucía','child_age'=>6,'event_language'=>'es','setup_area'=>'indoor','children_count'=>15])->assertCreated();
    }
    private function claim():array
    {
        $q=$this->getJson('/portal/api/quick-payment')->assertOk()->json();
        return ['expected_booking_version'=>$q['expected_booking_version'],'expected_payment_sha256'=>$q['expected_payment_sha256'],'amount_cents'=>$q['deposit_cents'],'reference'=>null];
    }
    public function test_details_then_zelle_notice_allows_signature_without_recording_money_and_keeps_expired_inventory():void
    {
        $r=$this->create();$this->client($r);$this->get('/portal')->assertOk()->assertSee('Tus datos');$this->details();
        $this->get('/portal')->assertOk()->assertSee('Cuánto pagar ahora')->assertSee('Klarna');
        $key=(string)Str::uuid();$claim=$this->claim();$this->withHeader('Idempotency-Key',$key)->postJson('/portal/api/quick-payment/zelle',$claim)->assertCreated()->assertJsonPath('receipt_recorded',false);
        $this->withHeader('Idempotency-Key',$key)->postJson('/portal/api/quick-payment/zelle',$claim)->assertCreated();
        $this->assertDatabaseCount('zelle_notices',1);$this->assertDatabaseCount('ledger_entries',0);
        $this->get('/portal')->assertOk()->assertSee('Zelle pendiente de aprobación')->assertSee('prepare-contract-form',false);
        $this->travel(49)->hours();$counts=app(\App\Services\Booking\TickQuickPayments::class)->execute();$this->assertSame(1,$counts['review_required']);
        $this->assertDatabaseCount('resource_reservations',1);$this->assertSame('review_required',QuickPaymentBooking::sole()->state);
        $counts=app(\App\Services\Booking\TickQuickPayments::class)->execute();$this->assertSame(0,$counts['review_required']);
    }
    public function test_reminder_and_expiry_are_idempotent_and_do_not_renew_the_hold():void
    {
        $this->create();$this->travel(24)->hours();$service=app(\App\Services\Booking\TickQuickPayments::class);
        $this->assertSame(1,$service->execute()['reminders']);$this->assertSame(0,$service->execute()['reminders']);
        $this->travel(24)->hours();$this->assertSame(1,$service->execute()['expired']);$this->assertSame(0,$service->execute()['expired']);
        $this->assertDatabaseCount('resource_reservations',0);$this->assertDatabaseCount('ledger_entries',0);
    }
    public static function zelleReviewTimes():array{return [[false],[true]];}
    #[\PHPUnit\Framework\Attributes\DataProvider('zelleReviewTimes')]
    public function test_owner_verification_records_actual_money_and_promotes_same_reservations(bool $afterExpiry):void
    {
        $r=$this->create();$this->client($r);$this->details();$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/quick-payment/zelle',$this->claim())->assertCreated();
        if($afterExpiry){$this->travel(49)->hours();app(\App\Services\Booking\TickQuickPayments::class)->execute();}
        $ids=DB::table('resource_reservations')->pluck('id')->all();$this->actingAs($this->owner);
        app(\App\Services\Finance\ReviewZelleNotice::class)->execute($this->owner,$r->event->id,\App\Models\ZelleNotice::sole()->id,[
            'decision'=>'verified','review_note'=>'Verifiqué el depósito en el banco.','financial_revision_id'=>$r->financial_revision->id,'amount_cents'=>15001,'currency'=>'USD',
            'reference'=>null,'received_at'=>now()->subMinute()->toIso8601String(),'verified_received'=>true],(string)Str::uuid());
        $this->assertSame('paid',QuickPaymentBooking::sole()->state);$this->assertSame($ids,DB::table('resource_reservations')->pluck('id')->all());
        $this->assertDatabaseCount('ledger_entries',1);$this->assertDatabaseCount('quick_bookings',1);
        \App\Services\Booking\QuickStripeLeases::assertNoActive($r->event->id);
    }
    private function checkout(int $percent=50,?string $key=null):array
    {
        $q=$this->claim();unset($q['amount_cents'],$q['reference']);$q['deposit_percent']=$percent;
        return $this->withHeader('Idempotency-Key',$key??(string)Str::uuid())->postJson('/portal/api/quick-payment/checkout',$q)->assertOk()->json();
    }
    private function webhook(\Tests\Support\QuickPaymentGateway $g,string $sid,?string $id=null):void
    {
        $id??='evt_'.str_replace('-','',Str::uuid());$s=$g->sessions[$sid];
        $payload=['id'=>$id,'type'=>'checkout.session.completed','livemode'=>false,'data'=>['object'=>['id'=>$sid,'object'=>'checkout.session','metadata'=>$s['metadata']]]];
        \App\Models\WebhookReceipt::create(['id'=>$id,'event_type'=>'checkout.session.completed','raw_sha256'=>hash('sha256',json_encode($payload)),
            'encrypted_payload'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode($payload)),'verified_at'=>now(),'state'=>'queued','attempts'=>0]);
        app(\App\Services\Finance\StripeReceiptRouter::class)->execute($id);
    }
    private function gateway():\Tests\Support\QuickPaymentGateway
    {
        config(['waoo.stripe_test_account'=>'acct_waoosandbox']);$g=new \Tests\Support\QuickPaymentGateway();app()->instance(\App\Services\Finance\StripeGateway::class,$g);return $g;
    }
    public function test_verified_stripe_separates_principal_fee_and_promotes_once_with_duplicate_webhooks():void
    {
        $r=$this->create();$this->client($r);$this->details();$g=$this->gateway();$key=(string)Str::uuid();$v=$this->checkout(50,$key);
        $this->assertSame($v,$this->checkout(50,$key));$this->assertDatabaseCount('quick_payment_attempts',1);
        $sid=\App\Models\PaymentIntent::sole()->stripe_checkout_session_id;$ids=DB::table('resource_reservations')->pluck('id')->all();$g->pay($sid);
        $this->webhook($g,$sid);$this->webhook($g,$sid);
        $this->assertSame('paid',QuickPaymentBooking::sole()->state);$this->assertSame($ids,DB::table('resource_reservations')->pluck('id')->all());
        $this->assertDatabaseHas('ledger_entries',['kind'=>'receipt','amount_cents'=>15001]);$this->assertDatabaseHas('ledger_entries',['kind'=>'customer_fee','amount_cents'=>450]);
        $this->assertDatabaseCount('quick_payment_receipts',1);$this->assertDatabaseCount('quick_payment_notifications',1);
        $f=app(\App\Services\Finance\FinanceSummary::class)->forEvent(Event::sole());$this->assertSame(15000,$f['balance_cents']);
        \App\Services\Booking\QuickStripeLeases::assertNoActive($r->event->id);
        $this->get('/portal')->assertOk()->assertSee('prepare-contract-form',false);
    }
    public function test_late_verified_payment_records_real_money_without_reacquiring_stock():void
    {
        $r=$this->create();$this->client($r);$this->details();$g=$this->gateway();$this->checkout(100);$sid=\App\Models\PaymentIntent::sole()->stripe_checkout_session_id;
        $this->travel(49)->hours();app(\App\Services\Booking\TickQuickPayments::class)->execute();$g->pay($sid);$this->webhook($g,$sid);
        $this->assertSame('paid_needs_review',QuickPaymentBooking::sole()->state);$this->assertDatabaseCount('resource_reservations',0);
        $this->assertDatabaseHas('ledger_entries',['kind'=>'receipt','amount_cents'=>30001]);$this->assertDatabaseHas('ledger_entries',['kind'=>'customer_fee','amount_cents'=>900]);
        $this->assertDatabaseCount('quick_bookings',0);
    }
    public function test_early_webhook_during_checkout_creation_preserves_paid_state():void
    {
        $r=$this->create();$this->client($r);$this->details();$g=$this->gateway();
        $g->onCreate=function($sid)use($g){$g->pay($sid);$this->webhook($g,$sid);};
        $v=$this->checkout();$this->assertSame('paid',$v['state']);$this->assertNull($v['checkout_url']);$this->assertSame('paid',\App\Models\PaymentIntent::sole()->status);
    }
    public function test_two_signed_webhooks_racing_allocate_the_payment_once():void
    {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB + pcntl required.');
        $r=$this->create();$this->client($r);$this->details();$g=$this->gateway();$this->checkout();$sid=\App\Models\PaymentIntent::sole()->stripe_checkout_session_id;$g->pay($sid);
        $workers=[];foreach([1,2] as $n)$workers[]=function()use($g,$sid,$n){$this->webhook($g,$sid,'evt_racing_'.$n);return 'processed';};
        $this->assertSame(['processed','processed'],\Tests\Support\ConcurrentWorkers::run($workers));
        $this->assertDatabaseCount('quick_payment_receipts',1);$this->assertDatabaseCount('ledger_entries',2);$this->assertDatabaseCount('quick_bookings',1);$this->assertDatabaseCount('resource_reservations',1);
    }
    public function test_wrong_account_or_amount_never_credits_money():void
    {
        $r=$this->create();$this->client($r);$this->details();$g=$this->gateway();$this->checkout();$sid=\App\Models\PaymentIntent::sole()->stripe_checkout_session_id;$g->pay($sid);$g->sessions[$sid]['amount_total']++;
        $this->webhook($g,$sid);$this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseHas('webhook_receipts',['state'=>'reconciliation_required']);
    }
    public function test_every_party_detail_is_required_and_no_payment_starts_before_details():void
    {
        $r=$this->create();$this->client($r);$this->gateway();$q=$this->claim();unset($q['amount_cents'],$q['reference']);$q['deposit_percent']=50;
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/quick-payment/checkout',$q)->assertConflict();
        $this->assertDatabaseCount('payment_intents',0);
    }
    public function test_pending_zelle_client_can_prepare_pdf_and_sign_before_bank_verification():void
    {
        \Illuminate\Support\Facades\Storage::fake('local');$r=$this->create();
        $t=app(\App\Services\Documents\CreateTemplateVersion::class)->execute($this->owner,['terms_html'=>'<p>Acuerdo sintético de María.</p>','change_note'=>'Plantilla sintética para la visita de Elena.'],(string)Str::uuid());
        app(\App\Services\Documents\ActivateTemplateVersion::class)->execute($this->owner,$t->id,['expected_source_sha256'=>$t->source_sha256,'approval_note'=>'Aprobación ficticia para prueba automatizada.']);
        $this->client($r);$this->details();$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/quick-payment/zelle',$this->claim())->assertCreated();
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/details/contract',['expected_event_version'=>1,'expected_revision'=>1])->assertOk();
        $c=\App\Models\Contract::sole();$this->get('/portal/api/contracts/'.$c->id.'/pdf')->assertOk();
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/contracts/'.$c->id.'/sign',['expected_version'=>$c->version,'presented_pdf_sha256'=>$c->presented_pdf_sha256,
            'signer_name'=>'Elena Martínez','consent'=>true,'intent_to_sign'=>true,'consent_version'=>'esign-consent-v2-2026-10-20-es'])->assertOk();
        $this->assertDatabaseCount('signature_evidence',1);$this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('client_details_reviews',0);
        $this->assertSame('zelle_pending',QuickPaymentBooking::sole()->state);
        // Owner 2026-10-26: the balance opens on the same link only after the deposit is received, never on a pending Zelle.
        $this->assertFalse(\App\Services\Portal\PortalContext::allows(\App\Models\PortalGrant::sole(),'checkout.create'));
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/checkout-intents',[
            'financial_revision_id'=>$r->financial_revision->id,'purpose'=>'balance'])->assertForbidden();
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/zelle-notices',[
            'financial_revision_id'=>$r->financial_revision->id,'amount_cents'=>15000])->assertForbidden();
        $this->assertDatabaseCount('payment_intents',0);$this->assertDatabaseCount('zelle_notices',1);
    }
    public function test_signed_quick_link_pays_the_balance_after_the_deposit_is_received():void
    {
        \Illuminate\Support\Facades\Storage::fake('local');$r=$this->create();
        $t=app(\App\Services\Documents\CreateTemplateVersion::class)->execute($this->owner,['terms_html'=>'<p>Acuerdo sintético de María.</p>','change_note'=>'Plantilla sintética para el saldo de Elena.'],(string)Str::uuid());
        app(\App\Services\Documents\ActivateTemplateVersion::class)->execute($this->owner,$t->id,['expected_source_sha256'=>$t->source_sha256,'approval_note'=>'Aprobación ficticia para prueba automatizada.']);
        $this->client($r);$this->details();$g=$this->gateway();$this->checkout();$sid=\App\Models\PaymentIntent::sole()->stripe_checkout_session_id;$g->pay($sid);$this->webhook($g,$sid);
        $this->assertSame('paid',QuickPaymentBooking::sole()->state);
        $grant=\App\Models\PortalGrant::sole();$this->assertFalse(\App\Services\Portal\PortalContext::allows($grant,'checkout.create'));
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/details/contract',['expected_event_version'=>\App\Models\Event::sole()->version,'expected_revision'=>\App\Models\FinancialRevision::where('event_id',\App\Models\Event::sole()->id)->max('revision')])->assertOk();
        $c=\App\Models\Contract::sole();$this->get('/portal/api/contracts/'.$c->id.'/pdf')->assertOk();
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/contracts/'.$c->id.'/sign',['expected_version'=>$c->version,'presented_pdf_sha256'=>$c->presented_pdf_sha256,
            'signer_name'=>'Elena Martínez','consent'=>true,'intent_to_sign'=>true,'consent_version'=>'esign-consent-v2-2026-10-20-es'])->assertOk();
        $this->assertTrue(\App\Services\Portal\PortalContext::allows($grant->fresh(),'checkout.create'));
        $this->assertSame($grant->scopes,$grant->fresh()->scopes);
        $html=$this->get('/portal?lang=es')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="pay-form"[^>]*>/',$html);$this->assertDoesNotMatchRegularExpression('/id="pay-form"[^>]*\shidden/',$html);
        $this->assertMatchesRegularExpression('/id="zelle-form"/',$html);$this->assertStringContainsString('download=1',$html);
        $this->get('/portal/api/contracts/'.\App\Models\Contract::sole()->id.'/pdf?artifact=final&download=1')->assertOk()->assertHeader('Content-Disposition','attachment; filename="waoo-contract-'.\App\Models\Contract::sole()->id.'.pdf"');
        $this->assertNotNull(\App\Models\Contract::sole()->final_pdf_path);$this->assertStringContainsString('artifact=final',$html);$this->assertStringContainsString('Ver mi contrato firmado',$html);
        // Exercise the authenticated write endpoints, not just the displayed buttons or the scope helper.
        $ledgerBefore=LedgerEntry::orderBy('id')->get()->toArray();
        $noticeKey=(string)Str::uuid();$notice=['financial_revision_id'=>$r->financial_revision->id,'amount_cents'=>15000];
        $noticeId=$this->withHeader('Idempotency-Key',$noticeKey)->postJson('/portal/api/zelle-notices',$notice)
            ->assertCreated()->assertJsonPath('notice.state','pending')->json('notice.id');
        $this->withHeader('Idempotency-Key',$noticeKey)->postJson('/portal/api/zelle-notices',$notice)
            ->assertCreated()->assertJsonPath('notice.id',$noticeId);
        $this->assertSame($ledgerBefore,LedgerEntry::orderBy('id')->get()->toArray());$this->assertDatabaseCount('zelle_notices',1);
        $payKey=(string)Str::uuid();$pay=['financial_revision_id'=>$r->financial_revision->id,'purpose'=>'balance'];
        $intentId=$this->withHeader('Idempotency-Key',$payKey)->postJson('/portal/api/checkout-intents',$pay)
            ->assertCreated()->assertJsonPath('principal_cents',15000)->assertJsonPath('status','open')->json('id');
        $this->withHeader('Idempotency-Key',$payKey)->postJson('/portal/api/checkout-intents',$pay)
            ->assertCreated()->assertJsonPath('id',$intentId);
        $this->assertSame(2,$g->creates);$this->assertDatabaseCount('payment_intents',2);$this->assertSame($ledgerBefore,LedgerEntry::orderBy('id')->get()->toArray());
        $grant->update(['revoked_at'=>now()]);
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/checkout-intents',$pay)->assertForbidden();
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/zelle-notices',$notice)->assertForbidden();
        $this->assertDatabaseCount('payment_intents',2);$this->assertDatabaseCount('zelle_notices',1);
    }
    public function test_automatic_confirmation_is_frozen_gated_and_never_retried_after_unknown_delivery():void
    {
        $this->owner->update(['email'=>'elena@example.test']);
        $mail=new \Tests\Support\QuickPaymentMail();app()->instance(\App\Services\Mail\MailTransport::class,$mail);
        $r=$this->create();$this->client($r);$this->details();$g=$this->gateway();$this->checkout();$sid=\App\Models\PaymentIntent::sole()->stripe_checkout_session_id;$g->pay($sid);$this->webhook($g,$sid);
        $n=\App\Models\QuickPaymentNotification::sole();$this->assertSame('pending',$n->status);$this->assertSame('elena@example.test',$n->payload['recipient']);
        app(\App\Services\Booking\DispatchQuickPaymentNotifications::class)->execute();$this->assertCount(0,$mail->sent);
        config(['waoo.sandbox_quick_payment_notifications'=>true,'waoo.sandbox_mail'=>true,'waoo.mail_allowlist'=>['elena@example.test']]);
        $mail->fail=true;$service=app(\App\Services\Booking\DispatchQuickPaymentNotifications::class);$service->execute();$service->execute();
        $this->assertCount(1,$mail->sent);$this->assertSame('delivery_unknown',$n->fresh()->status);$this->assertStringContainsString('tu pago de $154.51',$mail->sent[0]->text);$this->assertStringContainsString('Pagado hasta hoy: $150.01',$mail->sent[0]->text);
    }
    public function test_resend_never_claims_the_unmapped_parallel_notification_channel():void
    {
        $this->owner->update(['email'=>'elena@example.test']);
        $r=$this->create();$this->client($r);$this->details();$g=$this->gateway();$this->checkout();$sid=\App\Models\PaymentIntent::sole()->stripe_checkout_session_id;$g->pay($sid);$this->webhook($g,$sid);
        config(['waoo.mail_delivery_enabled'=>true,'waoo.sandbox_mail'=>true,'waoo.sandbox_quick_payment_notifications'=>true,
            'waoo.mail_provider'=>'resend','services.resend.key'=>str_repeat('synthetic-key-',4),'waoo.mail_from_address'=>'sender@example.test',
            'waoo.mail_from_name'=>'Waoo Party','waoo.mail_reply_to'=>'reply@example.test','waoo.mail_allowlist'=>['elena@example.test']]);
        $this->assertInstanceOf(\App\Services\Mail\ResendMailTransport::class,app(\App\Services\Mail\MailTransport::class));
        app(\App\Services\Booking\DispatchQuickPaymentNotifications::class)->execute();
        $this->assertSame('pending',\App\Models\QuickPaymentNotification::sole()->status);Http::assertNothingSent();
    }
    public function test_unknown_provider_response_retries_the_same_request_and_key():void
    {
        $r=$this->create();$this->client($r);$this->details();$g=$this->gateway();$key=(string)Str::uuid();
        $g->onCreate=function(){throw new \RuntimeException('Synthetic provider timeout');};
        $q=$this->claim();unset($q['amount_cents'],$q['reference']);$q['deposit_percent']=50;
        $this->withHeader('Idempotency-Key',$key)->postJson('/portal/api/quick-payment/checkout',$q)->assertStatus(503)->assertDontSee('Synthetic provider timeout');
        $replay=$this->getJson('/portal/api/quick-payment')->assertOk()->json('active_checkout.replay');$this->assertSame($key,$replay['idempotency_key']);$this->assertSame($q,$replay['payload']);
        $g->onCreate=null;$this->checkout(50,$key);$this->assertDatabaseCount('quick_payment_attempts',1);$this->assertCount(1,$g->sessions);$this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_owner_screen_hides_deposit_fields_and_saves_without_bank_attestation():void
    {
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $page=\Livewire\Livewire::test(\App\Filament\Pages\QuickBooking::class);
        $page->fillForm(['deposit_received'=>0,'price_version_ids'=>[PriceVersion::sole()->id],'package_id'=>PriceVersion::sole()->id,'title'=>'Cumpleaños de Lucía','event_type'=>'birthday',
            'date'=>'2026-11-10','start_time'=>'12:00','end_time'=>'14:00','address'=>'123 Oak Street','postal_code'=>'77493','travel_fee'=>'0',
            'customer_mode'=>'new','name'=>null,'phone'=>null,'email'=>null,'locale'=>'es','expires_days'=>14,
            'characters'=>[['price_version_id'=>PriceVersion::sole()->id,'character_id'=>Character::sole()->id,'quantity'=>1,'outfit'=>'0']]])
            ->call('save')->assertHasNoFormErrors()->assertSee('SIN PAGAR');
        $this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('resource_reservations',1);
    }
    public function test_signed_http_webhook_routes_the_quick_payment_without_a_worker():void
    {
        $r=$this->create();$this->client($r);$this->details();$g=$this->gateway();$this->checkout();$sid=\App\Models\PaymentIntent::sole()->stripe_checkout_session_id;$g->pay($sid);
        \Illuminate\Support\Facades\Queue::fake();config(['waoo.stripe_test_webhook_secret'=>'whsec_synthetic_quick_payment']);
        $payload=json_encode(['id'=>'evt_signed_quick','type'=>'checkout.session.completed','livemode'=>false,'data'=>['object'=>['id'=>$sid,'object'=>'checkout.session','metadata'=>$g->sessions[$sid]['metadata']]]]);
        $now=time();$sig='t='.$now.',v1='.hash_hmac('sha256',$now.'.'.$payload,'whsec_synthetic_quick_payment');
        $this->call('POST','/webhooks/stripe',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_STRIPE_SIGNATURE'=>$sig],$payload)->assertOk();
        // Owner 2026-10-21: confirmed right after the signed callback (no 5-minute wait for the cron); the cron then has nothing left to do.
        $this->assertDatabaseHas('webhook_receipts',['id'=>'evt_signed_quick','state'=>'processed']);$this->assertSame('paid',QuickPaymentBooking::sole()->state);
        $ledger=\Illuminate\Support\Facades\DB::table('ledger_entries')->count();app(\App\Services\Booking\TickQuickPayments::class)->execute();
        $this->assertSame($ledger,\Illuminate\Support\Facades\DB::table('ledger_entries')->count());$this->assertSame('paid',QuickPaymentBooking::sole()->state);
    }
    public static function raceRounds():array{return array_map(fn($n)=>[$n],range(1,5));}
    #[\PHPUnit\Framework\Attributes\DataProvider('raceRounds')]
    public function test_two_owners_race_for_last_costume_without_creating_a_second_customer_or_fake_receipt(int $round):void
    {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB + pcntl required.');
        $other=User::factory()->create(['name'=>'Andrés García','role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $input=$this->input;$workers=[];foreach([$this->owner->id,$other->id] as $id)$workers[]=function()use($input,$id){try{app(QuickBook::class)->execute(User::findOrFail($id),$input,(string)Str::uuid());return 'held';}catch(\Illuminate\Validation\ValidationException){return 'blocked';}};
        $results=\Tests\Support\ConcurrentWorkers::run($workers);sort($results);$this->assertSame(['blocked','held'],$results);
        $this->assertDatabaseCount('customers',1);$this->assertDatabaseCount('events',1);$this->assertDatabaseCount('resource_reservations',1);$this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_zelle_notice_racing_original_expiry_either_retains_for_review_or_rejects_the_late_claim():void
    {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB + pcntl required.');
        $r=$this->create();$this->client($r);$this->details();$claim=$this->claim();$deadline=QuickPaymentBooking::sole()->expires_at;
        $req=\Illuminate\Http\Request::create('https://crm.example.test/portal');$session=new \Illuminate\Session\Store('synthetic',new \Illuminate\Session\ArraySessionHandler(120));$session->put('portal_grant_id',\App\Models\PortalGrant::sole()->id);$req->setLaravelSession($session);
        $client=\App\Services\Portal\PortalContext::fromRequest($req);
        $out=\Tests\Support\ConcurrentWorkers::run([
            function()use($client,$claim,$deadline){\Carbon\CarbonImmutable::setTestNow($deadline->subSecond());try{app(\App\Services\Booking\SubmitQuickPaymentZelle::class)->execute($client,$claim,(string)Str::uuid());return 'pending';}catch(\App\Exceptions\RecordWriteRejected){return 'expired';}},
            function()use($deadline){\Carbon\CarbonImmutable::setTestNow($deadline);app(\App\Services\Booking\TickQuickPayments::class)->execute();return 'tick';}
        ]);
        $b=QuickPaymentBooking::sole();if($out[0]==='pending'){$this->assertSame('review_required',$b->state);$this->assertDatabaseCount('resource_reservations',1);}else{$this->assertSame('expired',$b->state);$this->assertDatabaseCount('resource_reservations',0);}
        $this->assertSame('tick',$out[1]);$this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_audit_failure_rolls_back_every_unpaid_creation_record():void
    {
        DB::listen(function($q){if(str_contains($q->sql,'audit_entries')&&in_array('quick_payment.awaiting_payment',$q->bindings,true))throw new \RuntimeException('Synthetic audit failure');});
        try{$this->create();$this->fail('Partial booking committed');}catch(\RuntimeException $e){$this->assertSame('Synthetic audit failure',$e->getMessage());}
        foreach(['customers','events','resource_reservations','quick_payment_bookings','portal_grants','ledger_entries'] as $t)$this->assertDatabaseCount($t,0);
    }
    public function test_client_may_switch_to_zelle_only_after_authenticated_remote_checkout_closure():void
    {
        $r=$this->create();$this->client($r);$this->details();$g=$this->gateway();$this->checkout();$q=$this->getJson('/portal/api/quick-payment')->assertOk()->json();
        $this->assertFalse($q['can_pay']);$this->get('/portal')->assertOk()->assertSee('Retomar Stripe');
        $body=['expected_booking_version'=>$q['expected_booking_version'],'expected_payment_sha256'=>$q['expected_payment_sha256'],'request_id'=>$q['active_checkout']['request_id']];$key=(string)Str::uuid();
        $this->withHeader('Idempotency-Key',$key)->postJson('/portal/api/quick-payment/checkout/close',$body)->assertOk()->assertJsonPath('state','expired');
        $this->withHeader('Idempotency-Key',$key)->postJson('/portal/api/quick-payment/checkout/close',$body)->assertOk();
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/quick-payment/zelle',$this->claim())->assertCreated();$this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_revocation_during_dispatch_preserves_and_closes_the_session_without_exposing_its_url():void
    {
        $r=$this->create();$this->client($r);$this->details();$g=$this->gateway();$g->onCreate=fn()=>\App\Models\PortalGrant::query()->update(['revoked_at'=>now()]);
        $q=$this->claim();unset($q['amount_cents'],$q['reference']);$q['deposit_percent']=50;
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/quick-payment/checkout',$q)->assertForbidden();
        $intent=\App\Models\PaymentIntent::sole();$this->assertSame('superseded',$intent->status);$this->assertNotNull($intent->stripe_checkout_session_id);
        $this->assertSame('expired',$g->sessions[$intent->stripe_checkout_session_id]['status']);$this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_later_owner_issued_balance_link_preserves_ordinary_read_scope_without_gaining_quick_payment_scope():void
    {
        $r=$this->create();$this->client($r);$this->details();$g=$this->gateway();$this->checkout();$sid=\App\Models\PaymentIntent::sole()->stripe_checkout_session_id;$g->pay($sid);$this->webhook($g,$sid);
        $this->actingAs($this->owner);$grant=app(\App\Services\Portal\IssuePortalGrant::class)->execute($this->owner,$r->event->id,[
            'customer_id'=>$r->customer->id,'expires_at'=>now()->addDay()->toIso8601String(),'scopes'=>['payments.read']]);
        auth()->logout();$this->postJson('/portal/api/session',['token'=>$grant->token])->assertOk();
        $this->get('/portal')->assertOk()->assertSee('Tu fiesta')->assertDontSee('id="quick-payment"',false);
        $this->getJson('/portal/api/quick-payment')->assertForbidden();$this->assertDatabaseCount('ledger_entries',2);
    }
    public function test_partial_verified_zelle_can_be_completed_with_another_real_bank_receipt_without_fake_notice_or_stock_reallocation():void
    {
        $r=$this->create();$this->client($r);$this->details();$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/quick-payment/zelle',$this->claim())->assertCreated();
        $this->actingAs($this->owner);$ids=DB::table('resource_reservations')->pluck('id')->all();
        $review=app(\App\Services\Finance\ReviewZelleNotice::class)->execute($this->owner,$r->event->id,\App\Models\ZelleNotice::sole()->id,[
            'decision'=>'verified','review_note'=>'El banco refleja un primer pago parcial.','financial_revision_id'=>$r->financial_revision->id,'amount_cents'=>10000,'currency'=>'USD',
            'reference'=>null,'received_at'=>now()->subMinute()->toIso8601String(),'verified_received'=>true],(string)Str::uuid());
        $this->assertSame('paid_needs_review',QuickPaymentBooking::sole()->state);$this->assertDatabaseCount('quick_bookings',0);
        $this->travel(49)->hours(); // The owner reviews an actual second bank receipt; no unpaid deadline is renewed.
        app(\App\Services\Finance\RecordZellePayment::class)->execute($this->owner,$r->event->id,['financial_revision_id'=>$r->financial_revision->id,'amount_cents'=>5001,'currency'=>'USD',
            'reference'=>null,'received_on'=>now('America/Chicago')->format('Y-m-d'),'verified_received'=>true],(string)Str::uuid());
        $b=QuickPaymentBooking::sole();$this->assertSame('paid',$b->state);$this->assertSame($review->ledger_entry_id,$b->receipt_id);
        $this->assertSame($ids,DB::table('resource_reservations')->pluck('id')->all());$this->assertDatabaseCount('ledger_entries',2);$this->assertDatabaseCount('zelle_notices',1);
        $this->assertDatabaseCount('quick_bookings',1);$this->assertDatabaseCount('client_details_reviews',0);$this->assertDatabaseMissing('payment_exceptions',['kind'=>'quick_payment_review','state'=>'open']);
    }
    public function test_blank_identity_unpaid_path_has_real_inventory_and_no_fabricated_payment():void
    {
        $r=$this->create();$this->assertSame('awaiting_payment',$r->payment_state);$this->assertNull(Customer::sole()->name);
        $this->assertSame(0,$r->finance['net_received_cents']);$this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('quick_bookings',0);
        $this->assertDatabaseCount('resource_reservations',1);$this->assertDatabaseCount('portal_grants',1);
        $this->assertSame('0',$r->costume_hold['rows'][0]['outfit']);$this->assertContains('timing_unknown',$r->costume_hold['issues']);
        $b=QuickPaymentBooking::sole();$this->assertSame(172800,$b->expires_at->timestamp-now()->timestamp);QuickPaymentProtocol::verify($b,Event::sole());
        $this->assertStringNotContainsString($r->portal_url,json_encode(DB::table('audit_entries')->get()));
    }
    public function test_retry_preserves_deadline_and_never_reveals_the_link_twice():void
    {
        $key=(string)Str::uuid();$a=$this->create($key);$this->travel(1)->hours();$b=$this->create($key);
        $this->assertSame($a->id,$b->id);$this->assertNull($b->portal_url);$this->assertTrue($b->portal_link_already_shown);
        $this->assertSame($a->costume_hold['terms'],$b->costume_hold['terms']);$this->assertDatabaseCount('events',1);
    }
    public function test_feature_off_refuses_unpaid_booking():void
    {
        config(['waoo.sandbox_quick_payments'=>false]);$this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);$this->create();
    }
    public function test_unpaid_path_rejects_deposit_evidence_instead_of_ignoring_it():void
    {
        $this->input['deposit']=['amount_cents'=>15001,'verified_received'=>true];
        $this->expectException(\Illuminate\Validation\ValidationException::class);$this->create();
    }
    public function test_audit_late_payment_and_signature_never_display_a_reserved_party_without_stock(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $r = $this->create();
        $t = app(\App\Services\Documents\CreateTemplateVersion::class)->execute($this->owner,
            ['terms_html'=>'<p>Synthetic audit terms.</p>', 'change_note'=>'Synthetic availability audit template.'], (string) Str::uuid());
        app(\App\Services\Documents\ActivateTemplateVersion::class)->execute($this->owner, $t->id,
            ['expected_source_sha256'=>$t->source_sha256, 'approval_note'=>'Synthetic audit approval only.']);
        $this->client($r); $this->details(); $g = $this->gateway(); $this->checkout(100);
        $sid = \App\Models\PaymentIntent::sole()->stripe_checkout_session_id;
        $this->travel(49)->hours();
        app(\App\Services\Booking\TickQuickPayments::class)->execute();
        $g->pay($sid); $this->webhook($g, $sid);
        $this->assertSame('paid_needs_review', QuickPaymentBooking::sole()->state);
        $this->assertDatabaseCount('resource_reservations', 0);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/portal/api/details/contract',
            ['expected_event_version'=>1, 'expected_revision'=>1])->assertOk();
        $c = \App\Models\Contract::sole();
        $this->get('/portal/api/contracts/'.$c->id.'/pdf')->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/portal/api/contracts/'.$c->id.'/sign',
            ['expected_version'=>$c->version, 'presented_pdf_sha256'=>$c->presented_pdf_sha256,
             'signer_name'=>'Elena Martínez', 'consent'=>true, 'intent_to_sign'=>true,
             'consent_version'=>'esign-consent-v2-2026-10-20-es'])->assertOk();
        $this->assertDatabaseCount('resource_reservations', 0);
        $this->get('/portal')->assertOk()->assertViewHas('vm', fn ($vm) => $vm['reserved'] === false);
    }

    public function test_deadline_keeps_the_stock_while_the_card_checkout_cannot_be_closed_and_releases_it_once_closed(): void
    {
        // Codex audit S2: never free the costume while the client's card checkout is still open or its outcome unknown.
        $r = $this->create(); $this->client($r); $this->details(); $g = $this->gateway(); $this->checkout(50);
        $held = \App\Models\ResourceReservation::count();
        $this->assertGreaterThan(0, $held);
        $this->travel(49)->hours();
        $g->failExpire = true;
        app(\App\Services\Booking\TickQuickPayments::class)->execute();
        $this->assertSame($held, \App\Models\ResourceReservation::count(), 'stock kept while the checkout is not closed');
        $this->assertNotSame('expired', QuickPaymentBooking::sole()->state);
        $this->assertSame('provider_close_pending', \App\Models\PaymentIntent::sole()->last_error);
        $g->failExpire = false;
        app(\App\Services\Booking\TickQuickPayments::class)->execute();
        $this->assertSame('expired', QuickPaymentBooking::sole()->state);
        $this->assertSame(0, \App\Models\ResourceReservation::count(), 'released once Stripe confirmed the unpaid expiry');
    }
}
