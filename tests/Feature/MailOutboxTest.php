<?php
namespace Tests\Feature;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer,EventMessagePolicy,FinancialRevision,OutboundMessage,OutboundMessageEvent,OutboundMessageState,PortalGrant,Resource,User};
use App\Services\Finance\{CreateFinancialRevision,RecordZellePayment,ConfirmPaidCapacity};
use App\Services\Mail\{ApprovedMail,DispatchOutboundMail,EventMailSource,MailTransport,PauseEventMail,PlanEventMail,ReadOutboundMail,RecordMailDelivery};
use App\Services\Portal\IssuePortalGrant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB,Queue};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\{ConcurrentWorkers,FakeMailTransport,QuoteScenario};
use Tests\TestCase;
class MailOutboxTest extends TestCase
{
    use DatabaseMigrations,QuoteScenario;
    private FinancialRevision $finance;private int $grantId;private FakeMailTransport $transport;
    protected function setUp():void
    {
        parent::setUp();$this->travelTo(CarbonImmutable::parse('2026-10-17T12:00:00Z'));$this->scenario();config(['waoo.sandbox_mail'=>true,'waoo.mail_allowlist'=>[$this->owner->email],'waoo.mail_review_url'=>'https://reviews.example.test/fixture']);
        $templates=[];foreach(EventMailSource::REMINDERS as $key)foreach(['es','en'] as $lang)$templates[$key][$lang]=['version'=>'fixture1','approval_reference'=>'SYNTHETIC_ONLY_NOT_OWNER_APPROVAL','subject'=>'Fixture '.$key,
            'paragraphs'=>['Fixture {first_name} <script>never executable</script>','{event_date} {address}','__totals__','{balance}{balance_due}'],
            'button'=>['label'=>'Fixture link','variable'=>$key==='thank_you_review'?'review_link':'link'],'closing'=>'Fixture closing','required_variables'=>['first_name','event_date','address','balance']];config(['waoo.mail_templates'=>$templates]);
        Customer::whereKey($this->event->customer_id)->update(['email'=>$this->owner->email]);
        $this->finance=app(CreateFinancialRevision::class)->execute($this->owner,$this->event->id,['expected_version'=>1],(string)Str::uuid());
        $this->payment($this->finance->deposit_target_cents,'MAIL-DEPOSIT');
        $resources=[Resource::create(['code'=>'MAIL-PERFORMER','name'=>'Fixture Performer','kind'=>'staff','status'=>'active','skills'=>['performer','driver']])->id,
            Resource::create(['code'=>'MAIL-SPEAKER','name'=>'Fixture Speaker','kind'=>'equipment','status'=>'active','equipment_kind'=>'speaker','skills'=>[]])->id];
        $this->event=app(ConfirmPaidCapacity::class)->execute($this->owner,$this->event->id,['expected_version'=>1,'financial_revision_id'=>$this->finance->id,'resource_ids'=>$resources,
            'review'=>['staff_confirmed'=>true,'cargo_checked'=>true,'origin_return_note'=>'Fixture origin and return reviewed.','transfers'=>[]]]);
        $grant=app(IssuePortalGrant::class)->execute($this->owner,$this->event->id,['customer_id'=>$this->event->customer_id,'expires_at'=>now()->addDays(29)->toIso8601String()]);$this->grantId=$grant->id;
        $this->transport=new FakeMailTransport();$this->app->instance(MailTransport::class,$this->transport);
    }
    private function payment(int $amount,string $ref):void
    {
        app(RecordZellePayment::class)->execute($this->owner,$this->event->id,['financial_revision_id'=>$this->finance->id,'amount_cents'=>$amount,'currency'=>'USD','reference'=>$ref,'received_at'=>now()->subMinute()->toIso8601String(),'verified_received'=>true],(string)Str::uuid());
    }
    private function input(array $templates=['balance_reminder','week_before','thank_you_review']):array{return ['expected_event_version'=>2,'expected_customer_version'=>1,'portal_grant_id'=>$this->grantId,'templates'=>$templates];}
    private function plan(array $templates=['balance_reminder'],?string $key=null):array{return app(PlanEventMail::class)->execute($this->owner,$this->event->id,$this->input($templates),$key??(string)Str::uuid());}
    private function due(OutboundMessage $m):void{$this->travelTo($m->due_at);}
    public function test_honeybook_blocks_planning_even_on_replay_and_never_dispatches_scheduled_mail():void
    {
        $key=(string)Str::uuid();$this->plan(key:$key);$m=OutboundMessage::sole();
        $this->event->forceFill(['responsible_system'=>'honeybook'])->save();
        try{$this->plan(key:$key);$this->fail('Replay must recheck responsibility');}
        catch(\App\Exceptions\ResponsibilityRejected $e){$this->assertSame(409,$e->getStatusCode());}
        $this->due($m);app(DispatchOutboundMail::class)->execute($m->id);
        $this->assertSame('blocked',OutboundMessageState::sole()->status);
        $this->assertSame('responsible_system_honeybook',OutboundMessageState::sole()->error_code);
        $this->assertSame(0,OutboundMessageState::sole()->attempt_count);$this->assertSame([],$this->transport->sent);
    }
    public function test_honeybook_late_delivery_evidence_is_preserved_without_resending():void
    {
        $this->plan();$m=OutboundMessage::sole();$this->due($m);app(DispatchOutboundMail::class)->execute($m->id);
        $this->event->forceFill(['responsible_system'=>'honeybook'])->save();
        app(RecordMailDelivery::class)->execute('fake-only','fake-'.$m->id,'fixture-late-bounce','bounced',CarbonImmutable::now());
        app(DispatchOutboundMail::class)->execute($m->id);
        $this->assertSame('bounced',OutboundMessageState::sole()->status);$this->assertSame([$m->id],$this->transport->sent);
    }
    public function test_immutable_encrypted_plan_exact_money_times_and_safe_owner_projection():void
    {
        $key=(string)Str::uuid();$a=$this->plan(EventMailSource::REMINDERS,$key);$this->assertCount(3,$a['message_ids']);$this->assertSame($a['message_ids'],$this->plan(EventMailSource::REMINDERS,$key)['message_ids']);$this->assertSame($a['message_ids'],$this->plan(EventMailSource::REMINDERS)['message_ids']);
        $m=OutboundMessage::where('template_key','balance_reminder')->sole();$this->assertTrue($this->finance->balance_due_at->subHours(24)->equalTo($m->due_at));$this->assertStringContainsString('$62.50',$m->payload['text']);$this->assertStringContainsString('&lt;script&gt;',$m->payload['html']);
        $raw=DB::table('outbound_messages')->where('id',$m->id)->value('payload');$this->assertStringNotContainsString($this->owner->email,$raw);$this->assertStringNotContainsString('#token=',$raw);$this->assertArrayNotHasKey('payload',$m->toArray());
        $body=$this->getJson('/admin/api/events/'.$this->event->id.'/messages')->assertOk()->assertJsonPath('total',3)->assertHeader('Cache-Control','no-store, private')->getContent();foreach(['#token=','payload','PRIVATE_',$this->owner->email] as $v)$this->assertStringNotContainsString($v,$body);
        $audit=json_encode(DB::table('audit_entries')->where('action','mail.planned')->get());$this->assertStringNotContainsString($this->owner->email,$audit);$this->assertStringNotContainsString('#token=',$audit);
        $this->expectException(\LogicException::class);$m->update(['locale'=>'en']);
    }
    public function test_fake_dispatch_is_sent_once_but_never_claimed_delivered_and_job_payload_has_only_id():void
    {
        $this->plan();$m=OutboundMessage::sole();$this->due($m);app(DispatchOutboundMail::class)->execute($m->id);app(DispatchOutboundMail::class)->execute($m->id);
        $this->assertSame([$m->id],$this->transport->sent);$this->assertSame('sent',OutboundMessageState::sole()->status);$this->assertNull(OutboundMessageState::sole()->delivered_at);$this->assertSame(1,OutboundMessageState::sole()->attempt_count);
        $job=serialize(new \App\Jobs\DispatchOutboundMailJob($m->id));$this->assertStringNotContainsString($this->owner->email,$job);$this->assertStringNotContainsString('#token=',$job);
    }
    public function test_unapproved_copy_fails_before_any_outbox_write():void
    {
        config(['waoo.mail_templates'=>[]]);try{$this->plan();$this->fail('Expected unapproved copy');}catch(ValidationException){}$this->assertDatabaseCount('outbound_messages',0);$this->assertCount(0,$this->transport->sent);
    }
    public function test_allowlist_excludes_nonowner_and_removed_recipient_blocks_dispatch():void
    {
        $this->plan();$m=OutboundMessage::sole();config(['waoo.mail_allowlist'=>['client-not-owner@example.test']]);$this->due($m);app(DispatchOutboundMail::class)->execute($m->id);
        $this->assertSame('blocked',OutboundMessageState::sole()->status);$this->assertSame('recipient_not_allowed',OutboundMessageState::sole()->error_code);$this->assertSame([],$this->transport->sent);
    }
    public function test_paid_balance_after_plan_is_skipped_without_losing_the_actual_receipt():void
    {
        $this->plan();$m=OutboundMessage::sole();$this->payment($this->finance->total_cents-$this->finance->deposit_target_cents,'MAIL-BALANCE');$this->due($m);app(DispatchOutboundMail::class)->execute($m->id);
        $this->assertSame('skipped',OutboundMessageState::sole()->status);$this->assertDatabaseCount('ledger_entries',2);$this->assertSame([],$this->transport->sent);
    }
    public function test_pause_is_versioned_idempotent_and_does_not_mark_due_mail_sent():void
    {
        $this->plan();$m=OutboundMessage::sole();$key=(string)Str::uuid();$d=['expected_version'=>0,'paused'=>true];$pause=app(PauseEventMail::class);$pause->execute($this->owner,$this->event->id,$d,$key);$pause->execute($this->owner,$this->event->id,$d,$key);
        $this->due($m);app(DispatchOutboundMail::class)->execute($m->id);$this->assertSame('scheduled',OutboundMessageState::sole()->status);$this->assertSame([],$this->transport->sent);
        $pause->execute($this->owner,$this->event->id,['expected_version'=>1,'paused'=>false],(string)Str::uuid());app(DispatchOutboundMail::class)->execute($m->id);$this->assertSame('sent',OutboundMessageState::sole()->status);
    }
    public function test_unknown_external_outcome_is_never_automatically_retried():void
    {
        $this->plan();$m=OutboundMessage::sole();$this->transport->uncertain=true;$this->due($m);app(DispatchOutboundMail::class)->execute($m->id);app(DispatchOutboundMail::class)->execute($m->id);$this->assertCount(1,$this->transport->sent);$this->assertSame('delivery_unknown',OutboundMessageState::sole()->status);$this->assertSame('transport_outcome_unknown',OutboundMessageState::sole()->error_code);
    }
    public function test_internal_adapter_seam_deduplicates_and_late_delivery_cannot_erase_bounce():void
    {
        $this->plan();$m=OutboundMessage::sole();$this->due($m);app(DispatchOutboundMail::class)->execute($m->id);$record=app(RecordMailDelivery::class);$at=CarbonImmutable::now();
        $record->execute('fake-only','fake-'.$m->id,'fixture-bounce','bounced',$at);$record->execute('fake-only','fake-'.$m->id,'fixture-bounce','bounced',$at);$record->execute('fake-only','fake-'.$m->id,'fixture-delivery','delivered',$at);
        $this->assertSame('bounced',OutboundMessageState::sole()->status);$this->assertSame(1,OutboundMessageEvent::where('kind','bounced')->count());$this->getJson('/admin/api/messages/warnings')->assertOk()->assertJsonPath('total',1);
        $this->expectException(RecordWriteRejected::class);$record->execute('fake-only','fake-'.$m->id,'fixture-bounce','complained',$at);
    }
    public function test_resend_key_and_provider_cannot_lift_owner_hold_or_claim_a_due_message():void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        config(['waoo.mail_delivery_enabled'=>false,'waoo.mail_provider'=>'resend','services.resend.key'=>str_repeat('synthetic-key-',4),
            'waoo.mail_from_address'=>'sender@example.test','waoo.mail_from_name'=>'Waoo Party','waoo.mail_reply_to'=>'reply@example.test']);
        $this->app->forgetInstance(MailTransport::class);
        $this->plan();$m=OutboundMessage::sole();$this->due($m);app(DispatchOutboundMail::class)->execute($m->id);
        $this->assertSame('blocked',OutboundMessageState::sole()->status);$this->assertSame(0,OutboundMessageState::sole()->attempt_count);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }
    public function test_mocked_resend_failure_crosses_the_outbox_boundary_once_without_automatic_retry():void
    {
        config(['waoo.mail_delivery_enabled'=>true,'waoo.mail_provider'=>'resend','services.resend.key'=>str_repeat('synthetic-key-',4),
            'waoo.mail_from_address'=>'sender@example.test','waoo.mail_from_name'=>'Waoo Party','waoo.mail_reply_to'=>'reply@example.test']);
        \Illuminate\Support\Facades\Http::fake(['https://api.resend.com/emails'=>\Illuminate\Support\Facades\Http::response(['message'=>'PRIVATE_RESPONSE'],429)]);
        $this->app->forgetInstance(MailTransport::class);
        $this->plan();$m=OutboundMessage::sole();$this->due($m);app(DispatchOutboundMail::class)->execute($m->id);app(DispatchOutboundMail::class)->execute($m->id);
        $this->assertSame('delivery_unknown',OutboundMessageState::sole()->status);$this->assertSame(1,OutboundMessageState::sole()->attempt_count);
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }
    public function test_default_disabled_transport_is_a_safe_explicit_block_without_native_mail_or_log_calls():void
    {
        $this->plan();$m=OutboundMessage::sole();$this->app->instance(MailTransport::class,new \App\Services\Mail\DisabledMailTransport());$this->due($m);app(DispatchOutboundMail::class)->execute($m->id);$this->assertSame('transport_unconfigured',OutboundMessageState::sole()->error_code);$this->assertSame(0,OutboundMessageState::sole()->attempt_count);
    }
    public function test_late_audit_failure_rolls_back_entire_plan_and_receipt():void
    {
        $n=DB::table('mutation_receipts')->count();$fail=true;DB::connection()->beforeExecuting(function($sql,$bindings)use(&$fail){if($fail&&str_contains($sql,'audit_entries')&&in_array('mail.planned',$bindings,true))throw new \RuntimeException('Fixture outbox audit failure');});
        try{$this->plan(EventMailSource::REMINDERS);$this->fail('Expected atomic rollback');}catch(\RuntimeException $e){$this->assertSame('Fixture outbox audit failure',$e->getMessage());}finally{$fail=false;}
        foreach(['outbound_messages','outbound_message_states','outbound_message_events'] as $table)$this->assertDatabaseCount($table,0);$this->assertSame($n,DB::table('mutation_receipts')->count());
    }
    public function test_real_two_owner_plans_are_one_set_and_real_two_workers_claim_one_attempt():void
    {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB pcntl required');
        $other=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);$input=$this->input(['balance_reminder']);
        $worker=fn($owner)=>fn()=>app(PlanEventMail::class)->execute($owner,$this->event->id,$input,(string)Str::uuid())['message_ids'];$results=ConcurrentWorkers::run([$worker($this->owner),$worker($other)]);$this->assertSame($results[0],$results[1]);$this->assertDatabaseCount('outbound_messages',1);
        $m=OutboundMessage::sole();$this->due($m);$worker=function()use($m){app(DispatchOutboundMail::class)->execute($m->id);return true;};$path=tempnam(sys_get_temp_dir(),'waoo-fake-mail-');$this->transport->counterPath=$path;try{$results=ConcurrentWorkers::run([$worker,$worker]);$this->assertSame([true,true],$results);$this->assertCount(1,file($path,FILE_IGNORE_NEW_LINES));}finally{unlink($path);}$this->assertSame(1,OutboundMessageState::sole()->attempt_count);$this->assertSame('sent',OutboundMessageState::sole()->status);
    }
    public function test_settings_flags_roles_and_closed_http_surfaces():void
    {
        $this->getJson('/admin/api/mail/settings')->assertOk()->assertJsonPath('activation_required',true)->assertJsonCount(14,'templates');config(['waoo.sandbox_mail'=>false]);$this->getJson('/admin/api/events/'.$this->event->id.'/messages')->assertForbidden()->assertHeader('Cache-Control','no-store, private');
        config(['waoo.sandbox_mail'=>true]);$this->owner->forceFill(['role'=>'staff'])->save();$this->getJson('/admin/api/mail/settings')->assertForbidden();$this->assertSame([],$this->transport->sent);
        $this->postJson('/webhooks/mail',[])->assertNotFound();
    }
    public function test_cross_row_encrypted_payload_swap_cannot_change_the_authorized_message():void
    {
        $this->plan(['balance_reminder','week_before']);$balance=OutboundMessage::where('template_key','balance_reminder')->sole();$week=OutboundMessage::where('template_key','week_before')->sole();
        DB::table('outbound_messages')->where('id',$balance->id)->update(['payload'=>DB::table('outbound_messages')->where('id',$week->id)->value('payload')]);$this->due($balance);app(DispatchOutboundMail::class)->execute($balance->id);
        $this->assertSame('payload_integrity_failed',OutboundMessageState::where('outbound_message_id',$balance->id)->value('error_code'));$this->assertSame([],$this->transport->sent);
    }
    public function test_revoked_link_source_cannot_dispatch_old_intent():void
    {
        $this->plan();$m=OutboundMessage::sole();$this->due($m);PortalGrant::whereKey($this->grantId)->update(['revoked_at'=>now()]);app(DispatchOutboundMail::class)->execute($m->id);$this->assertSame('skipped',OutboundMessageState::sole()->status);$this->assertSame([],$this->transport->sent);
    }
    public function test_due_command_is_bounded_and_only_enqueues_ids():void
    {
        $this->plan();$m=OutboundMessage::sole();$this->artisan('waoo:mail-due',['--limit'=>50])->assertSuccessful();Queue::assertNothingPushed();$this->due($m);$this->artisan('waoo:mail-due',['--limit'=>1])->assertSuccessful();Queue::assertPushed(\App\Jobs\DispatchOutboundMailJob::class,fn($job)=>$job->messageId===$m->id);$this->artisan('waoo:mail-due',['--limit'=>0])->assertFailed();$this->assertSame([],$this->transport->sent);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedSources')]
    public function test_changed_business_sources_and_approvals_never_send(string $kind):void
    {
        $this->plan();$m=OutboundMessage::sole();
        match($kind){
            'cancelled'=>$this->event->forceFill(['status'=>'cancelled'])->save(),
            'rescheduled'=>$this->event->forceFill(['starts_at'=>$this->event->starts_at->addHour(),'version'=>3])->save(),
            'recipient'=>Customer::whereKey($this->event->customer_id)->update(['email'=>'different-fixture@example.test']),
            'approval'=>config(['waoo.mail_templates'=>[]]),
            'account'=>$this->owner->forceFill(['active'=>false])->save(),
        };
        $this->due($m);app(DispatchOutboundMail::class)->execute($m->id);$this->assertContains(OutboundMessageState::sole()->status,['skipped','blocked']);$this->assertSame([],$this->transport->sent);
    }
    public static function changedSources():array{return array_map(fn($v)=>[$v],['cancelled','rescheduled','recipient','approval','account']);}
    public function test_stale_versions_and_browser_recipient_are_rejected_atomically():void
    {
        $d=$this->input();$d['expected_customer_version']=9;
        try{app(PlanEventMail::class)->execute($this->owner,$this->event->id,$d,(string)Str::uuid());$this->fail('Expected stale customer');}catch(RecordWriteRejected $e){$this->assertSame(409,$e->status);}
        $d=$this->input();$d['recipient']='browser@example.test';$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/admin/api/events/'.$this->event->id.'/mail/reminders',$d)->assertUnprocessable();
        $this->assertDatabaseCount('outbound_messages',0);$this->assertSame([],$this->transport->sent);
    }
    public function test_replay_remains_original_after_payment_and_late_job_does_not_catch_up():void
    {
        $key=(string)Str::uuid();$p=$this->plan(['balance_reminder'],$key);$m=OutboundMessage::sole();$this->payment($this->finance->total_cents,'FIXTURE-LATER-PAYMENT');
        $this->assertSame($p['message_ids'],$this->plan(['balance_reminder'],$key)['message_ids']);$this->travelTo($m->due_at->addDays(2));app(DispatchOutboundMail::class)->execute($m->id);$this->assertSame('skipped',OutboundMessageState::sole()->status);$this->assertSame([],$this->transport->sent);
    }
    public function test_pagination_reaches_oldest_and_future_templates_are_not_implicitly_enabled():void
    {
        $this->plan(EventMailSource::REMINDERS);$url='/admin/api/events/'.$this->event->id.'/messages?per_page=1&page=';
        $this->getJson($url.'3')->assertOk()->assertJsonPath('page',3)->assertJsonPath('data.0.id',OutboundMessage::min('id'));$this->getJson($url.'99')->assertOk()->assertJsonPath('page',3);$this->getJson($url.'0')->assertUnprocessable();
        $this->getJson('/admin/api/mail/settings')->assertOk()->assertJsonPath('templates.0.approved',false);
    }

    public function test_bounced_recipient_blocks_another_due_message_and_card_filters_only_bounces():void
    {
        $this->plan(['balance_reminder','week_before']);$week=OutboundMessage::where('template_key','week_before')->sole();$balance=OutboundMessage::where('template_key','balance_reminder')->sole();$this->due($week);app(DispatchOutboundMail::class)->execute($week->id);
        app(RecordMailDelivery::class)->execute('fake-only','fake-'.$week->id,'fixture-suppress','bounced',CarbonImmutable::now());$this->due($balance);app(DispatchOutboundMail::class)->execute($balance->id);
        $this->assertSame('recipient_suppressed',OutboundMessageState::where('outbound_message_id',$balance->id)->value('error_code'));$this->assertSame([$week->id],$this->transport->sent);
        $this->getJson('/admin/api/messages/warnings?status=bounced')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$week->id);
        $this->getJson('/admin/api/messages/warnings?status=blocked')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$balance->id);$this->getJson('/admin/api/messages/warnings?status=sent')->assertUnprocessable();
    }
    public function test_database_failure_after_fake_provider_acceptance_preserves_claim_and_never_sends_again():void
    {
        $this->plan();$m=OutboundMessage::sole();$this->due($m);$fail=true;
        DB::connection()->beforeExecuting(function($sql,$bindings)use(&$fail){if($fail&&str_contains($sql,'update')&&str_contains($sql,'outbound_message_states')&&in_array('sent',$bindings,true))throw new \Illuminate\Database\QueryException('mysql',$sql,$bindings,new \PDOException('SYNTHETIC_PRIVATE_PROVIDER_FAILURE'));});
        try{app(DispatchOutboundMail::class)->execute($m->id);$this->fail('Expected acknowledgment persistence failure');}catch(\RuntimeException $e){$this->assertSame('Private mail persistence unavailable.',$e->getMessage());$this->assertNull($e->getPrevious());}finally{$fail=false;}
        $this->assertSame('dispatching',OutboundMessageState::sole()->status);app(DispatchOutboundMail::class)->execute($m->id);$this->assertSame([$m->id],$this->transport->sent);$this->getJson('/admin/api/messages/warnings?status=dispatching')->assertOk()->assertJsonPath('total',1);
    }

    public static function retiredTemplates(): array { return [['contract_ready'], ['zelle_notice_pending']]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('retiredTemplates')]
    public function test_preexisting_retired_message_is_blocked_before_transport_even_when_every_other_gate_allows_it(string $key): void
    {
        $this->plan(); $message = OutboundMessage::where('template_key', 'balance_reminder')->sole();
        // Simulate a historical queued row; app code must not delete or rewrite its immutable payload.
        DB::table('outbound_messages')->where('id', $message->id)->update(['template_key' => $key]);
        $before = (array) DB::table('outbound_messages')->where('id', $message->id)->first();
        $this->travelTo($message->due_at);
        app(DispatchOutboundMail::class)->execute($message->id);
        $this->assertDatabaseHas('outbound_message_states', ['outbound_message_id' => $message->id,
            'status' => 'blocked', 'error_code' => 'template_retired_by_owner']);
        $this->assertSame($before, (array) DB::table('outbound_messages')->where('id', $message->id)->first());
        $this->assertSame([], $this->transport->sent);
    }
}
