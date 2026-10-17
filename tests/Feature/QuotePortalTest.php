<?php
namespace Tests\Feature;

use App\Exceptions\{PortalLinkRejected, RecordWriteRejected};
use App\Models\{Event, PortalGrant, Quote, QuoteDecision, QuotePresentation, QuoteRevision, User};
use App\Services\Finance\MutationKey;
use App\Services\Portal\{IssuePortalGrant, PortalTokenVault, RevealPortalGrantLink, RevokePortalGrant};
use App\Services\Quotes\{DecideQuote, ExpireQuotes, QuoteArtifacts, QuoteProtocol, ReviseQuote, SendQuote};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{Crypt, DB, Storage, View};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\{ConcurrentWorkers, QuoteScenario};
use Tests\TestCase;

class QuotePortalTest extends TestCase
{
    use DatabaseMigrations, QuoteScenario;
    protected function setUp():void {parent::setUp();$this->scenario();}

    public function test_preparation_freezes_private_pdf_and_quote_scoped_encrypted_link_with_exact_recovery():void
    {
        $key=(string)Str::uuid();$prepared=$this->prepare($key);$g=PortalGrant::sole();$p=QuotePresentation::sole();
        $token=substr($prepared['url'],strpos($prepared['url'],'#token=')+7);
        $this->assertSame($token,PortalTokenVault::open($g));
        $sealed=json_decode(Crypt::decryptString($g->encrypted_token),true);
        $this->assertSame(2,$sealed['version']);$this->assertSame($this->offer->id,$sealed['quote_revision_id']);
        $this->assertSame($prepared['url'],$this->prepareReplay($key)['url']);
        $this->assertSame($prepared['url'],app(RevealPortalGrantLink::class)->execute($this->owner,$this->event->id,$g->id)->url);
        $this->assertSame(['quote.read','quote.accept'],$g->scopes);
        $this->assertStringStartsWith('%PDF-',app(QuoteArtifacts::class)->read($p));
        $html=View::make('pdf.quote',['p'=>$p->payload])->render();
        $this->assertStringContainsString('&lt;script&gt;fixture&lt;/script&gt;',$html);
        $this->assertStringNotContainsString('PRIVATE_',$html);$this->assertStringContainsString('Visible adjustment',$html);
        $this->assertSame(2,Quote::sole()->version);$this->assertDatabaseCount('portal_grants',1);$this->assertDatabaseCount('quote_presentations',1);
        foreach(['financial_revisions','ledger_entries','resource_reservations','quote_decisions'] as $t)$this->assertDatabaseCount($t,0);
    }
    private function prepareReplay(string $key):array
    {
        return app(SendQuote::class)->execute($this->owner,$this->offer->quote_id,['expected_version'=>1,'expected_revision'=>1],$key);
    }
    public function test_approved_wording_is_required_and_no_default_draft_copy_is_activated():void
    {
        config(['waoo.quote_acceptance_consent'=>[]]);
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/admin/api/quotes/'.$this->offer->quote_id.'/send',['expected_version'=>1,'expected_revision'=>1])
            ->assertUnprocessable()->assertJsonValidationErrors('consent')->assertHeader('Cache-Control','no-store, private');
        $this->assertDatabaseCount('portal_grants',0);$this->assertDatabaseCount('quote_presentations',0);$this->assertSame('draft',Quote::sole()->status);
    }
    public function test_real_session_projection_scope_isolation_pdf_view_and_client_acceptance_without_money():void
    {
        $prepared=$this->prepare();auth()->guard('web')->logout();
        $this->postJson('/portal/api/session',['token'=>substr($prepared['url'],strpos($prepared['url'],'#token=')+7)])->assertOk()->assertJsonPath('scopes.0','quote.read');
        $body=$this->getJson('/portal/api/quote')->assertOk()->assertJsonPath('can_decide',true)->assertJsonPath('quote.total_cents',10000)
            ->assertHeader('Cache-Control','no-store, private')->getContent();
        foreach(['PRIVATE_','approved_by','requirements_snapshot','pdf_path','client@example.test'] as $secret)$this->assertStringNotContainsString($secret,$body);
        $this->getJson('/portal/api/event')->assertForbidden();$this->getJson('/portal/api/zelle-notices')->assertForbidden();
        $input=$this->decisionInput($prepared);unset($input['outcome']);
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/quote/accept',$input)->assertUnprocessable()->assertJsonValidationErrors('quote');
        $this->get('/portal/api/quote/pdf')->assertOk()->assertHeader('Cache-Control','no-store, private');
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal/api/quote/accept',$input)->assertCreated()->assertJsonPath('decision.outcome','accepted');
        $this->assertSame('accepted',Quote::sole()->status);$this->assertDatabaseCount('quote_decisions',1);
        $this->assertSame('fixture-decision-v1',QuoteDecision::sole()->consent_version);
        foreach(['financial_revisions','ledger_entries','resource_reservations'] as $t)$this->assertDatabaseCount($t,0);
    }
    public function test_event_only_grant_cannot_read_or_decide_a_quote_and_legacy_codec_stays_v1():void
    {
        $eventGrant=app(IssuePortalGrant::class)->execute($this->owner,$this->event->id,['customer_id'=>$this->event->customer_id,'expires_at'=>now()->addDays(3)->toIso8601String()]);
        $g=PortalGrant::findOrFail($eventGrant->id);$this->assertSame(1,json_decode(Crypt::decryptString($g->encrypted_token),true)['version']);
        $this->assertSame($eventGrant->token,PortalTokenVault::open($g));
        $this->withSession(['portal_grant_id'=>$g->id])->getJson('/portal/api/quote')->assertForbidden();
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/admin/api/events/'.$this->event->id.'/portal-grants',[
            'customer_id'=>$this->event->customer_id,'expires_at'=>now()->addDays(3)->toIso8601String(),'scopes'=>['quote.read','quote.accept']])->assertUnprocessable();
    }
    public function test_quote_token_ciphertext_is_bound_to_its_revision_id():void
    {
        $prepared=$this->prepare();$g=PortalGrant::sole();
        $g->forceFill(['quote_revision_id'=>999]);
        $this->expectException(PortalLinkRejected::class);PortalTokenVault::open($g);
    }
    public function test_wrong_hash_consent_and_email_are_rejected_without_a_decision():void
    {
        $prepared=$this->prepare();$this->viewPdf($prepared);$client=$this->client($prepared['portal_grant_id']);
        foreach(['snapshot_sha256','pdf_sha256','consent_sha256'] as $field){
            try{app(DecideQuote::class)->execute($client,[...$this->decisionInput($prepared),$field=>str_repeat('0',64)],(string)Str::uuid());$this->fail('Expected mismatch');}
            catch(RecordWriteRejected $e){$this->assertSame(409,$e->status);}
        }
        try{app(DecideQuote::class)->execute($client,[...$this->decisionInput($prepared),'email'=>'other@example.test'],(string)Str::uuid());$this->fail('Expected identity mismatch');}
        catch(ValidationException $e){$this->assertArrayHasKey('email',$e->errors());}
        $this->assertDatabaseCount('quote_decisions',0);
    }
    public function test_idempotency_and_second_identical_intent_never_duplicate_a_decision_or_audit():void
    {
        $p=$this->prepare();$this->viewPdf($p);$c=$this->client($p['portal_grant_id']);$key=(string)Str::uuid();$input=$this->decisionInput($p);
        $d=app(DecideQuote::class)->execute($c,$input,$key);
        $this->assertSame($d->id,app(DecideQuote::class)->execute($c,$input,$key)->id);
        $this->assertSame($d->id,app(DecideQuote::class)->execute($c,$input,(string)Str::uuid())->id);
        $this->assertDatabaseCount('quote_decisions',1);$this->assertSame(1,DB::table('audit_entries')->where('action','quote.client_decided')->count());
        $this->assertSame('192.0.2.19',$d->ip);$this->assertSame('Fixture Quote Browser',$d->user_agent);
        $this->expectException(RecordWriteRejected::class);app(DecideQuote::class)->execute($c,$this->decisionInput($p,'rejected'),(string)Str::uuid());
    }
    public function test_revising_a_sent_quote_revokes_only_quote_grants_and_keeps_old_presentation_immutable():void
    {
        $eventGrant=app(IssuePortalGrant::class)->execute($this->owner,$this->event->id,['customer_id'=>$this->event->customer_id,'expires_at'=>now()->addDays(3)->toIso8601String()]);
        $p=$this->prepare();$before=QuotePresentation::sole()->getAttributes();
        $r=app(ReviseQuote::class)->execute($this->owner,$this->offer->quote_id,['expected_version'=>2,'expected_event_version'=>1,'discounts'=>[]],(string)Str::uuid());
        $this->assertSame(2,$r->revision);$this->assertSame('draft',Quote::sole()->status);
        $this->assertNotNull(PortalGrant::findOrFail($p['portal_grant_id'])->revoked_at);$this->assertNull(PortalGrant::findOrFail($p['portal_grant_id'])->encrypted_token);
        $this->assertNull(PortalGrant::findOrFail($eventGrant->id)->revoked_at);$this->assertSame($before,QuotePresentation::sole()->getAttributes());
        $this->withSession(['portal_grant_id'=>$p['portal_grant_id']])->getJson('/portal/api/quote')->assertForbidden();
    }
    public function test_admin_sessions_cannot_decide_for_clients():void
    {
        $p=$this->prepare();$this->viewPdf($p);
        $this->expectException(AuthorizationException::class);app(DecideQuote::class)->execute($this->client($p['portal_grant_id'],true),$this->decisionInput($p),(string)Str::uuid());
    }
    public function test_revoke_and_expiry_block_decisions_and_reveal_never_reissues():void
    {
        $p=$this->prepare();$client=$this->client($p['portal_grant_id']);$this->viewPdf($p);
        app(RevokePortalGrant::class)->execute($this->owner,$p['portal_grant_id']);
        try{app(DecideQuote::class)->execute($client,$this->decisionInput($p),(string)Str::uuid());$this->fail('Expected revoked denial');}catch(AuthorizationException){}
        try{app(RevealPortalGrantLink::class)->execute($this->owner,$this->event->id,$p['portal_grant_id']);$this->fail('Expected inactive');}catch(PortalLinkRejected $e){$this->assertSame(410,$e->status);}
        $this->assertDatabaseCount('portal_grants',1);$this->assertDatabaseCount('quote_decisions',0);
    }
    public function test_source_customer_or_draft_drift_denies_acceptance():void
    {
        $p=$this->prepare();$this->viewPdf($p);$client=$this->client($p['portal_grant_id']);
        $this->event->forceFill(['version'=>2])->save();
        $this->expectException(RecordWriteRejected::class);app(DecideQuote::class)->execute($client,$this->decisionInput($p),(string)Str::uuid());
    }
    public function test_corrupted_pdf_and_late_audit_failure_leave_no_acceptance():void
    {
        $p=$this->prepare();$this->viewPdf($p);$client=$this->client($p['portal_grant_id']);$fail=true;
        DB::connection()->beforeExecuting(function(string $sql,array $bindings)use(&$fail){if($fail&&str_contains($sql,'audit_entries')&&in_array('quote.client_decided',$bindings,true))throw new \RuntimeException('Fixture decision audit failure');});
        try{app(DecideQuote::class)->execute($client,$this->decisionInput($p),(string)Str::uuid());$this->fail('Expected audit failure');}
        catch(\RuntimeException $e){$this->assertSame('Fixture decision audit failure',$e->getMessage());}finally{$fail=false;}
        $this->assertDatabaseCount('quote_decisions',0);$this->assertSame('sent',Quote::sole()->status);
        $presentation=QuotePresentation::sole();Storage::disk('local')->put($presentation->pdf_path,'broken fixture');
        $this->expectException(\RuntimeException::class);app(DecideQuote::class)->execute($client,$this->decisionInput($p),(string)Str::uuid());
    }
    public function test_expired_links_deny_and_accepted_agreements_never_expire():void
    {
        $p=$this->prepare();$this->acceptOffer($p);$this->travel(8)->days();
        $this->assertSame(0,app(ExpireQuotes::class)->execute());$this->assertSame('accepted',Quote::sole()->status);
        $this->withSession(['portal_grant_id'=>$p['portal_grant_id']])->getJson('/portal/api/quote')->assertForbidden();
    }
    public function test_two_actual_opposite_decisions_have_one_winner():void
    {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB and pcntl required');
        $p=$this->prepare();$this->viewPdf($p);$client=$this->client($p['portal_grant_id']);
        $worker=fn($outcome)=>function()use($outcome,$p,$client){try{app(DecideQuote::class)->execute($client,$this->decisionInput($p,$outcome),(string)Str::uuid());return $outcome;}catch(RecordWriteRejected $e){return $e->status;}};
        $results=ConcurrentWorkers::run([$worker('accepted'),$worker('rejected')]);
        $this->assertContains(409,$results);$this->assertContains(QuoteDecision::sole()->outcome,$results);$this->assertDatabaseCount('quote_decisions',1);
    }
    public function test_real_revision_vs_decision_race_resolves_without_stale_acceptance_or_deadlock():void
    {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB and pcntl required');
        $p=$this->prepare();$this->viewPdf($p);$client=$this->client($p['portal_grant_id']);
        $decide=function()use($p,$client){try{app(DecideQuote::class)->execute($client,$this->decisionInput($p),(string)Str::uuid());return 'accepted';}catch(AuthorizationException){return 403;}};
        $revise=function(){try{app(ReviseQuote::class)->execute($this->owner,$this->offer->quote_id,['expected_version'=>2,'expected_event_version'=>1,'discounts'=>[]],(string)Str::uuid());return 'revised';}catch(RecordWriteRejected $e){return $e->status;}};
        $results=ConcurrentWorkers::run([$decide,$revise]);
        $this->assertTrue(in_array('accepted',$results,true)||in_array('revised',$results,true));
        $this->assertTrue(in_array(403,$results,true)||in_array(409,$results,true));
        $q=Quote::sole();$this->assertSame($q->status==='accepted'?1:0,QuoteDecision::count());
    }
    public function test_customer_reassignment_and_corrupted_consent_deny_the_old_link():void
    {
        $p=$this->prepare();
        $other=\App\Models\Customer::create(['name'=>'Other fictional customer','email'=>'other@example.test','locale'=>'en']);
        $original=$this->event->customer_id;
        $this->event->update(['customer_id'=>$other->id]);
        $this->withSession(['portal_grant_id'=>$p['portal_grant_id']])->getJson('/portal/api/quote')->assertConflict();
        $this->event->update(['customer_id'=>$original]);
        $presentation=QuotePresentation::sole();$consents=$presentation->consents;$consents['accepted']['text']='Changed fixture consent';
        DB::table('quote_presentations')->where('id',$presentation->id)->update(['consents'=>json_encode($consents)]);
        $this->getJson('/portal/api/quote')->assertConflict();$this->assertDatabaseCount('quote_decisions',0);
    }
    public function test_preparation_audit_and_encryption_failures_do_not_publish_partial_links():void
    {
        $receipts=DB::table('mutation_receipts')->count();$fail=true;
        DB::connection()->beforeExecuting(function(string $sql,array $bindings)use(&$fail){if($fail&&str_contains($sql,'audit_entries')&&in_array('portal.grant_issued',$bindings,true))throw new \RuntimeException('Fixture grant audit failure');});
        try{$this->prepare();$this->fail('Expected audit failure');}catch(\RuntimeException $e){$this->assertSame('Fixture grant audit failure',$e->getMessage());}finally{$fail=false;}
        $this->assertDatabaseCount('portal_grants',0);$this->assertDatabaseCount('quote_presentations',0);$this->assertSame(1,Quote::sole()->version);
        Crypt::shouldReceive('encryptString')->once()->andThrow(new \RuntimeException('Fixture sensitive encryption error'));
        try{$this->prepare();$this->fail('Expected safe encryption failure');}catch(PortalLinkRejected $e){$this->assertStringNotContainsString('sensitive',$e->getMessage());}
        $this->assertDatabaseCount('portal_grants',0);$this->assertDatabaseCount('quote_presentations',0);$this->assertSame($receipts,DB::table('mutation_receipts')->count());
    }
    public function test_artifact_write_failure_keeps_quote_draft_without_a_grant():void
    {
        Storage::shouldReceive('disk->put')->once()->andReturn(false);
        try{$this->prepare();$this->fail('Expected artifact failure');}catch(\RuntimeException $e){$this->assertSame('Private quote artifact unavailable.',$e->getMessage());}
        $this->assertDatabaseCount('portal_grants',0);$this->assertDatabaseCount('quote_presentations',0);$this->assertSame('draft',Quote::sole()->status);
    }
    public function test_actual_revoke_vs_acceptance_race_is_serialized_by_the_grant():void
    {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB and pcntl required');
        $p=$this->prepare();$this->viewPdf($p);$client=$this->client($p['portal_grant_id']);
        $decide=function()use($p,$client){try{app(DecideQuote::class)->execute($client,$this->decisionInput($p),(string)Str::uuid());return 'accepted';}catch(AuthorizationException){return 403;}};
        $revoke=function()use($p){app(RevokePortalGrant::class)->execute($this->owner,$p['portal_grant_id']);return 'revoked';};
        $results=ConcurrentWorkers::run([$decide,$revoke]);
        $this->assertContains('revoked',$results);$this->assertTrue(in_array('accepted',$results,true)||in_array(403,$results,true));
        $this->assertSame(in_array('accepted',$results,true)?1:0,QuoteDecision::count());
        $grant=PortalGrant::findOrFail($p['portal_grant_id']);$this->assertNotNull($grant->revoked_at);$this->assertNull($grant->encrypted_token);
    }

}
