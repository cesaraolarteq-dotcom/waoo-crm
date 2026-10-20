<?php
namespace Tests\Feature;

use App\Models\{Event, FinancialRevision, LedgerEntry, OpeningPortalBinding, PortalGrant, User};
use App\Services\Agreements\ReadConfirmedAgreement;
use App\Services\Finance\RecordZellePayment;
use App\Services\Portal\{IssueOpeningPortalGrant, PortalTokenVault, RevealPortalGrantLink, RevokePortalGrant};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail, Queue, Schema};
use Illuminate\Support\Str;
use Tests\Support\{ConcurrentWorkers, EffectiveOpeningScenario};
use Tests\TestCase;

class OpeningClientPortalTest extends TestCase
{
    use DatabaseMigrations, EffectiveOpeningScenario;
    protected function setUp():void
    {
        parent::setUp();$this->futureScenario();$this->withCredentials();
        config(['waoo.sandbox_cutover'=>true,'waoo.sandbox_cutover_bridge'=>true]);Http::preventStrayRequests();Mail::fake();
    }
    protected function tearDown():void
    {
        if($this->app){
            $this->app->detectEnvironment(fn ()=>'testing');
            foreach(['opening_portal_bindings','portal_grants','opening_receipt_allocations','cutover_active_openings','cutover_financial_bindings','cutover_transfers',
                'cutover_opening_agreements','cutover_opening_sets','cutover_reconciliations','honeybook_cutover_reviews','cutover_original_documents',
                'payment_exceptions','ledger_entries','payment_intents','financial_revisions'] as $t)if(Schema::hasTable($t))DB::table($t)->delete();
            if(Schema::hasTable('booking_evidence'))DB::table('booking_evidence')->where('method','honeybook_import')->delete();
        }
        parent::tearDown();
    }
    private function ready(array $source=[],array $review=[],array $commit=[]):int
    {
        $link=$this->openingMember(change:$source,review:$review,commit:$commit);$this->seedFutureActivation($this->prepareOpeningSet());return $link->event_id;
    }
    private function input(int $id,array $change=[]):array
    {
        $a=app(ReadConfirmedAgreement::class)->execute($this->owner,$id);
        return ['customer_id'=>Event::findOrFail($id)->customer_id,'expected_event_version'=>$a['event_version'],'financial_revision_id'=>$a['financial_revision_id'],
            'expected_snapshot_sha256'=>$a['snapshot_sha256'],'expires_at'=>now()->addDay()->toIso8601String(),...$change];
    }
    private function issue(int $id,?string $key=null,array $change=[]):array
    {return app(IssueOpeningPortalGrant::class)->execute($this->owner,$id,$this->input($id,$change),$key??(string)Str::uuid());}
    private function enter(array $issued):void
    {
        auth()->logout();$g=PortalGrant::findOrFail($issued['id']);
        $this->postJson('/portal/api/session',['token'=>parse_url($issued['link'],PHP_URL_FRAGMENT) ? substr(parse_url($issued['link'],PHP_URL_FRAGMENT),6):''])
            ->assertOk()->assertSessionHas('portal_opening_binding_id',(int)$g->cutover_financial_binding_id)->assertSessionHas('portal_opening_snapshot_sha256',$g->opening_snapshot_sha256);
    }
    public function test_issue_replay_and_owner_recopy_preserve_one_bound_readonly_token_without_sends():void
    {
        $id=$this->ready();$key=(string)Str::uuid();$input=$this->input($id);$issued=app(IssueOpeningPortalGrant::class)->execute($this->owner,$id,$input,$key);
        $this->assertSame($issued,app(IssueOpeningPortalGrant::class)->execute($this->owner,$id,$input,$key));
        $g=PortalGrant::sole();$this->assertSame(['agreement.read'],$g->scopes);$this->assertSame(3,json_decode(Crypt::decryptString($g->encrypted_token),true)['version']);
        $this->assertSame($issued['link'],app(RevealPortalGrantLink::class)->execute($this->owner,$id,$g->id)->url);
        $this->assertSame(1,DB::table('audit_entries')->where('action','portal.original_grant_issued')->count());
        $this->assertDatabaseCount('portal_grants',1);$this->assertDatabaseCount('opening_portal_bindings',1);$this->assertDatabaseCount('ledger_entries',0);
        foreach(['contracts','signature_evidence','mail_occurrences','payment_intents'] as $t)$this->assertSame(0,DB::table($t)->count());
        $token=substr(parse_url($issued['link'],PHP_URL_FRAGMENT),6);$this->assertStringNotContainsString($token,DB::table('audit_entries')->get()->toJson().DB::table('mutation_receipts')->get()->toJson());
        Mail::assertNothingSent();
    }
    public function test_client_projection_and_readonly_page_show_original_prices_without_internal_or_staff_data():void
    {
        $id=$this->ready();$issued=$this->issue($id);$this->enter($issued);
        $r=$this->getJson('/portal/api/original-agreement?lang=en')->assertOk()->assertHeader('Cache-Control','no-store, private')
            ->assertJsonPath('kind','imported_agreement')->assertJsonPath('agreement.package_description','Historical Package + Extra')->assertJsonPath('agreement.original_total_cents',12000)
            ->assertJsonPath('money.recorded_paid_cents',6000)->assertJsonPath('money.balance_cents',6000)->assertJsonPath('actions.can_sign',false)->assertJsonPath('actions.can_checkout',false);
        foreach(['PRIVATE_','fixture-source@example.test','+13465550100','Source Client','financial_revision_id','portal_grant_id','token_hash','encrypted_token','resource_id','staff_assignment','deposit_target','signer_name','source_snapshot'] as $secret)$r->assertDontSee($secret);
        $this->get('/portal?lang=es')->assertOk()->assertSee('Tu acuerdo original')->assertSee('Costo de transporte')->assertSee('Pagos registrados')->assertDontSee('Current synthetic catalog label');
        $this->get('/portal?lang=en')->assertOk()->assertSee('Your original agreement')->assertSee('Travel fee')->assertSee('Download original agreement')->assertDontSee('PRIVATE_');
        $this->assertDatabaseCount('contracts',0);$this->assertDatabaseCount('signature_evidence',0);
    }
    public function test_pdf_is_exact_private_attachment_not_a_regenerated_contract_or_signature():void
    {
        $id=$this->ready();$this->enter($this->issue($id));$expected="%PDF-1.4\n% FICTIONAL_ORIGINAL\n%%EOF\n";
        $r=$this->get('/portal/api/original-agreement/pdf')->assertOk()->assertHeader('Content-Type','application/pdf')
            ->assertHeader('Content-Disposition','attachment; filename="original-agreement.pdf"')->assertHeader('Cache-Control','no-store, private')
            ->assertHeader('Referrer-Policy','no-referrer')->assertHeader('X-Content-Type-Options','nosniff');
        $this->assertSame($expected,$r->getContent());$this->assertStringContainsString('sandbox',$r->headers->get('Content-Security-Policy'));
        $this->assertSame(hash('sha256',$expected),$this->getJson('/portal/api/original-agreement')->json('agreement.document.sha256'));
        $this->assertDatabaseCount('contract_presentations',0);$this->assertDatabaseCount('signature_evidence',0);
    }
    public function test_original_portal_cannot_use_native_read_write_or_quote_scopes():void
    {
        $id=$this->ready();$this->enter($this->issue($id));
        $this->getJson('/portal/api/event')->assertForbidden();
        $this->getJson('/portal/api/quote')->assertForbidden();
        $uuid=(string)Str::uuid();$this->getJson('/portal/api/contracts/'.$uuid)->assertForbidden();
        $this->postJson('/portal/api/checkout-intents',['financial_revision_id'=>FinancialRevision::sole()->id,'purpose'=>'balance'],['Idempotency-Key'=>(string)Str::uuid()])->assertForbidden();
        $this->postJson('/portal/api/zelle-notices',['financial_revision_id'=>FinancialRevision::sole()->id,'amount_cents'=>100],['Idempotency-Key'=>(string)Str::uuid()])->assertForbidden();
        $this->postJson('/portal/api/contracts/'.$uuid.'/sign',['expected_version'=>1,'presented_pdf_sha256'=>str_repeat('0',64),'signer_name'=>'Fictional Client',
            'consent'=>true,'intent_to_sign'=>true,'consent_version'=>\App\Services\Documents\InHouseSignatureProvider::CONSENT_VERSION],['Idempotency-Key'=>(string)Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('payment_intents',0);$this->assertDatabaseCount('zelle_notices',0);$this->assertDatabaseCount('signature_evidence',0);
    }
    public function test_new_verified_bank_money_updates_read_without_reissuing_or_new_deposit():void
    {
        $id=$this->ready();$issued=$this->issue($id);
        app(RecordZellePayment::class)->execute($this->owner,$id,$this->sourcePaymentInput($id),(string)Str::uuid());
        $this->enter($issued);$this->getJson('/portal/api/original-agreement')->assertOk()->assertJsonPath('money.recorded_paid_cents',8000)->assertJsonPath('money.balance_cents',4000);
        $this->assertDatabaseCount('portal_grants',1);$this->assertDatabaseCount('ledger_entries',1);
    }
    public function test_unknown_money_is_null_and_review_required_not_zero_or_payable():void
    {
        $id=$this->ready();$issued=$this->issue($id);
        LedgerEntry::create(['event_id'=>$id,'financial_revision_id'=>FinancialRevision::sole()->id,'environment'=>'test','kind'=>'receipt','method'=>'stripe','amount_cents'=>2000,'currency'=>'USD',
            'received_at'=>now(),'external_reference'=>'PRIVATE_UNALLOCATED','external_identity_key'=>'stripe:test:acct_fixture:pi_unknown','source_type'=>'verified_stripe_webhook']);
        $this->enter($issued);$this->getJson('/portal/api/original-agreement')->assertOk()->assertJsonPath('money.state','review_required')->assertJsonPath('money.balance_cents',null)
            ->assertJsonPath('money.recorded_paid_cents',null)->assertJsonPath('money.credit_cents',null)->assertJsonPath('actions.can_checkout',false)->assertDontSee('PRIVATE_');
        $this->get('/portal?lang=es')->assertOk()->assertSee('Por confirmar')->assertSee('Estamos revisando los pagos');$this->assertDatabaseCount('ledger_entries',1);
    }
    public function test_paid_full_missing_source_deadline_stays_null_without_inventing_a_due_time():void
    {
        $id=$this->ready(['amount_paid'=>'120.00','balance'=>'0.00','balance_due_date'=>'','payments'=>'2026-10-01|120.00|Zelle'],['crm_balance_due_at'=>null]);
        $this->enter($this->issue($id));$this->getJson('/portal/api/original-agreement')->assertOk()->assertJsonPath('money.balance_cents',0)
            ->assertJsonPath('money.reviewed_due_at',null)->assertJsonPath('money.source_due_date',null)->assertJsonPath('money.source_due_precision','unknown');
        $this->get('/portal?lang=en')->assertOk()->assertDontSee('Balance due:');
    }
    public function test_original_difference_is_visible_without_reclassifying_it_as_a_new_card_fee():void
    {
        $id=$this->ready(['total'=>'123.60','amount_paid'=>'70.00','balance'=>'53.60','payments'=>'2026-10-01|70.00|Zelle'],[],
            ['source_difference_accepted'=>true,'source_difference_note'=>'PRIVATE_OWNER_NOTE accepted source amount.']);
        $this->enter($this->issue($id));$this->getJson('/portal/api/original-agreement')->assertOk()->assertJsonPath('agreement.original_total_cents',12360)
            ->assertJsonPath('agreement.original_difference_cents',360)->assertJsonPath('money.balance_cents',5360);
        $this->get('/portal?lang=en')->assertOk()->assertSee('Original total difference')->assertSee('$3.60')->assertDontSee('processing fee')->assertDontSee('PRIVATE_OWNER_NOTE');
    }
    public function test_changed_contact_corrupt_original_and_honeybook_responsibility_block_current_client_and_owner_reveal():void
    {
        $id=$this->ready();$issued=$this->issue($id);$this->enter($issued);$customer=Event::findOrFail($id)->customer;$old=$customer->name;
        $customer->forceFill(['name'=>'Changed PRIVATE_CONTACT'])->save();$this->getJson('/portal/api/original-agreement')->assertConflict()->assertDontSee('PRIVATE_CONTACT');
        $this->get('/portal')->assertOk()->assertSee('Acuerdo no disponible')->assertDontSee('Changed');$customer->forceFill(['name'=>$old])->save();
        DB::table('cutover_original_documents')->update(['document_ciphertext'=>'CORRUPT_PRIVATE_PDF']);$this->get('/portal/api/original-agreement/pdf')->assertConflict()->assertDontSee('CORRUPT_PRIVATE_PDF');
        $this->actingAs($this->owner);$this->postJson('/admin/api/events/'.$id.'/portal-grants/'.$issued['id'].'/reveal-link')->assertConflict();
        Event::findOrFail($id)->forceFill(['responsible_system'=>'honeybook'])->save();$this->getJson('/portal/api/original-agreement')->assertConflict()->assertJsonPath('code','responsible_system_honeybook');
    }
    public function test_revoked_expired_and_unpinned_source_sessions_cannot_download_or_initialize_again():void
    {
        $id=$this->ready();$issued=$this->issue($id);$this->enter($issued);
        $this->withSession(['portal_opening_binding_id'=>null]);$this->getJson('/portal/api/original-agreement/pdf')->assertForbidden();
        $this->enter($issued);app(RevokePortalGrant::class)->execute($this->owner,$issued['id']);
        $this->getJson('/portal/api/original-agreement')->assertForbidden();$this->postJson('/portal/api/session',['token'=>substr(parse_url($issued['link'],PHP_URL_FRAGMENT),6)])->assertForbidden();
        $this->actingAs($this->owner);$new=$this->issue($id);$this->travelTo(\Carbon\CarbonImmutable::parse($new['expires_at']));
        $this->postJson('/portal/api/session',['token'=>substr(parse_url($new['link'],PHP_URL_FRAGMENT),6)])->assertForbidden();
    }
    public function test_source_markers_and_envelope_cannot_be_stripped_to_downgrade_a_token():void
    {
        $id=$this->ready();$issued=$this->issue($id);$this->enter($issued);$g=PortalGrant::sole();
        DB::table('portal_grants')->where('id',$g->id)->update(['cutover_financial_binding_id'=>null,'opening_snapshot_sha256'=>null,'encrypted_token'=>null,'scopes'=>json_encode(['payments.read','checkout.create'])]);
        $this->getJson('/portal/api/event')->assertForbidden();$this->getJson('/portal/api/original-agreement/pdf')->assertForbidden();
        $this->postJson('/portal/api/session',['token'=>substr(parse_url($issued['link'],PHP_URL_FRAGMENT),6)])->assertConflict();
        $this->assertDatabaseCount('opening_portal_bindings',1);$this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_registry_corruption_and_swapped_envelope_never_disclose_original_bytes():void
    {
        $id=$this->ready();$a=$this->issue($id);$b=$this->issue($id);$this->enter($a);$ga=PortalGrant::findOrFail($a['id']);$gb=PortalGrant::findOrFail($b['id']);
        DB::table('portal_grants')->where('id',$ga->id)->update(['encrypted_token'=>$gb->encrypted_token]);
        $this->get('/portal/api/original-agreement/pdf')->assertConflict()->assertDontSee('%PDF-');
        DB::table('portal_grants')->where('id',$ga->id)->update(['encrypted_token'=>$ga->encrypted_token]);DB::table('opening_portal_bindings')->where('portal_grant_id',$ga->id)->update(['payload'=>'CORRUPT_PRIVATE_REGISTRY']);
        $this->getJson('/portal/api/original-agreement')->assertConflict()->assertDontSee('CORRUPT_PRIVATE_REGISTRY');
    }
    public function test_other_event_ids_or_document_query_cannot_change_the_scoped_original():void
    {
        $one=$this->openingMember();$two=$this->openingMember('SECOND-PRIVATE-PROJECT',['project_name'=>'Second PRIVATE_PARTY'],['file'=>\Illuminate\Http\UploadedFile::fake()->createWithContent('other.pdf',"%PDF-1.4\n% SECOND_PRIVATE_ORIGINAL\n%%EOF\n")]);$this->seedFutureActivation($this->prepareOpeningSet());
        $a=$this->issue($one->event_id);$this->enter($a);
        $this->getJson('/portal/api/original-agreement?event_id='.$two->event_id)->assertOk()->assertDontSee('Second PRIVATE_PARTY');
        $otherDocument=DB::table('cutover_original_documents')->where('event_id',$two->event_id)->value('id');
        $this->get('/portal/api/original-agreement/pdf?document_id='.$otherDocument)->assertOk()->assertSee('FICTIONAL_ORIGINAL')->assertDontSee('SECOND_PRIVATE_ORIGINAL');
        $this->actingAs($this->owner);$this->postJson('/admin/api/events/'.$two->event_id.'/portal-grants/'.$a['id'].'/reveal-link')->assertNotFound();
    }
    public function test_retargeted_source_grant_never_exposes_another_customers_original():void
    {
        $one=$this->openingMember();$two=$this->openingMember('RETARGET-PRIVATE-PROJECT',['project_name'=>'PRIVATE_OTHER_PARTY']);$this->seedFutureActivation($this->prepareOpeningSet());
        $a=$this->issue($one->event_id);$b=$this->issue($two->event_id);$this->enter($a);$other=PortalGrant::findOrFail($b['id']);
        DB::table('portal_grants')->where('id',$a['id'])->update(['event_id'=>$two->event_id,'customer_id'=>$two->customer_id,
            'cutover_financial_binding_id'=>$other->cutover_financial_binding_id,'opening_snapshot_sha256'=>$other->opening_snapshot_sha256]);
        $this->getJson('/portal/api/original-agreement')->assertForbidden()->assertDontSee('PRIVATE_OTHER_PARTY');
        $this->postJson('/portal/api/session',['token'=>substr(parse_url($a['link'],PHP_URL_FRAGMENT),6)])->assertConflict()->assertDontSee('PRIVATE_OTHER_PARTY');
        $this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_preparation_enum_flip_wrong_customer_stale_snapshot_and_scope_overrides_cannot_issue():void
    {
        $link=$this->openingMember();$this->prepareOpeningSet();$e=Event::findOrFail($link->event_id);$e->forceFill(['responsible_system'=>'crm'])->save();
        $input=['customer_id'=>$e->customer_id,'expected_event_version'=>$e->version,'financial_revision_id'=>1,'expected_snapshot_sha256'=>str_repeat('0',64),'expires_at'=>now()->addDay()->toIso8601String()];
        $this->postJson('/admin/api/events/'.$e->id.'/opening-portal-grants',$input,['Idempotency-Key'=>(string)Str::uuid()])->assertConflict();
        $this->seedFutureActivation(\App\Models\CutoverOpeningSet::sole());$valid=$this->input($e->id);$url='/admin/api/events/'.$e->id.'/opening-portal-grants';
        $this->postJson($url,[...$valid,'customer_id'=>99999],['Idempotency-Key'=>(string)Str::uuid()])->assertConflict();
        $this->postJson($url,[...$valid,'expected_snapshot_sha256'=>str_repeat('0',64)],['Idempotency-Key'=>(string)Str::uuid()])->assertConflict();
        $this->postJson($url,[...$valid,'scopes'=>['checkout.create']],['Idempotency-Key'=>(string)Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('portal_grants',0);
    }
    public function test_private_owner_gates_source_flags_https_csrf_and_invalid_language():void
    {
        $id=$this->ready();$input=$this->input($id);$url='/admin/api/events/'.$id.'/opening-portal-grants';
        config(['waoo.sandbox_cutover_bridge'=>false]);$this->postJson($url,$input,['Idempotency-Key'=>(string)Str::uuid()])->assertForbidden();config(['waoo.sandbox_cutover_bridge'=>true]);
        $csrf='source-portal-production-fixture';$this->withSession(['_token'=>$csrf]);
        $this->app->detectEnvironment(fn ()=>'production');$this->postJson($url,$input,['Idempotency-Key'=>(string)Str::uuid(),'X-CSRF-TOKEN'=>$csrf])->assertForbidden();
        $this->app->detectEnvironment(fn ()=>'staging');config(['session.secure'=>true]);
        $this->postJson('https://localhost'.$url,$input,['Idempotency-Key'=>(string)Str::uuid()])->assertStatus(419);
        $this->app->detectEnvironment(fn ()=>'testing');$issued=$this->issue($id);$this->enter($issued);
        $this->getJson('/portal/api/original-agreement?lang[]=es')->assertUnprocessable();
        $this->app->detectEnvironment(fn ()=>'staging');$this->getJson('http://localhost/portal/api/original-agreement')->assertForbidden();
    }
    public function test_audit_failure_rolls_back_grant_registry_and_key_then_exact_retry_can_succeed():void
    {
        $id=$this->ready();$input=$this->input($id);$key=(string)Str::uuid();Schema::rename('audit_entries','original_portal_audit_off');
        try{app(IssueOpeningPortalGrant::class)->execute($this->owner,$id,$input,$key);$this->fail('Grant persisted without its audit.');}
        catch(\RuntimeException $e){$this->assertSame('Private import persistence unavailable.',$e->getMessage());$this->assertNull($e->getPrevious());}
        finally{Schema::rename('original_portal_audit_off','audit_entries');}
        $this->assertDatabaseCount('portal_grants',0);$this->assertDatabaseCount('opening_portal_bindings',0);$this->assertSame(0,DB::table('mutation_receipts')->where('request_key',$key)->count());
        app(IssueOpeningPortalGrant::class)->execute($this->owner,$id,$input,$key);$this->assertDatabaseCount('portal_grants',1);
    }
    public function test_two_parallel_identical_owner_requests_return_one_grant_without_rotating_the_token():void
    {
        if(DB::getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB/pcntl required.');
        $id=$this->ready();$input=$this->input($id);$key=(string)Str::uuid();$owner=$this->owner;
        $worker=fn () =>app(IssueOpeningPortalGrant::class)->execute($owner,$id,$input,$key);
        $results=ConcurrentWorkers::run([$worker,$worker]);$this->assertIsArray($results[0],json_encode($results));$this->assertSame($results[0],$results[1]);
        $this->assertDatabaseCount('portal_grants',1);$this->assertDatabaseCount('opening_portal_bindings',1);
    }
    public function test_untrusted_source_text_is_escaped_and_populated_migration_cannot_remove_evidence():void
    {
        $id=$this->ready(['project_name'=>'<script>window.PRIVATE_BAD=1</script>']);$issued=$this->issue($id);$this->enter($issued);
        $this->get('/portal')->assertOk()->assertDontSee('<script>window.PRIVATE_BAD',false)->assertSee('&lt;script&gt;window.PRIVATE_BAD',false);
        $m=require database_path('migrations/2026_10_19_480000_bind_original_agreement_portal_grants.php');
        try{$m->down();$this->fail('Source grant evidence erased.');}catch(\RuntimeException $e){$this->assertStringContainsString('forward migration',$e->getMessage());}
        $this->assertDatabaseCount('opening_portal_bindings',1);
        $this->expectException(\LogicException::class);OpeningPortalBinding::sole()->delete();
    }
}
