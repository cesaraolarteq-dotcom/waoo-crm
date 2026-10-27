<?php
namespace Tests\Feature;

use App\Models\{OutboundMessageEvent,OutboundMessageState};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http,Mail};
use Tests\Support\ResendScenario;
use Tests\TestCase;

class ResendWebhookTest extends TestCase
{
    use RefreshDatabase,ResendScenario;
    protected function setUp(): void{parent::setUp();$this->resendScenario();}
    private function receive(string $type='email.bounced',string $id='msg_synthetic_01',array $over=[],array $headers=[],?string $raw=null,string $url='https://localhost/webhooks/resend')
    {
        $body=$raw??json_encode(array_replace_recursive(['type'=>$type,'created_at'=>now()->subMinute()->toIso8601String(),
            'data'=>['email_id'=>$this->resendId,'to'=>[$this->owner->email],'subject'=>'PRIVATE_PROVIDER_SUBJECT','bounce'=>['type'=>'Permanent','message'=>'PRIVATE_PROVIDER_BODY']]],$over));
        $timestamp=(string)now()->timestamp;
        return $this->call('POST',$url,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json',
            'HTTP_SVIX_ID'=>$id,'HTTP_SVIX_TIMESTAMP'=>$timestamp,'HTTP_SVIX_SIGNATURE'=>'v1,'.base64_encode(hash_hmac('sha256',$id.'.'.$timestamp.'.'.$body,$this->signingBytes,true)),...$headers],$body);
    }
    public function test_signed_bounce_replay_is_private_and_retained_with_all_sending_held(): void
    {
        config(['waoo.sandbox_mail'=>false]);
        $this->receive()->assertOk()->assertExactJson(['received'=>true])->assertHeader('Cache-Control','no-store, private');
        $this->receive()->assertOk();$this->assertSame('bounced',$this->mailState->fresh()->status);$this->assertDatabaseCount('outbound_message_events',1);
        $evidence=json_encode(DB::table('outbound_message_events')->get());
        foreach(['PRIVATE_',$this->owner->email,'svix','whsec_'] as $private)$this->assertStringNotContainsString($private,$evidence);
        Http::assertNothingSent();Mail::assertNothingSent();$this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_complaint_outranks_bounce_and_late_delivery_never_unsuppresses(): void
    {
        $this->receive()->assertOk();$this->receive('email.complained','msg_synthetic_02')->assertOk();
        $this->receive('email.delivered','msg_synthetic_03')->assertOk();
        $this->assertSame('complained',$this->mailState->fresh()->status);$this->assertDatabaseCount('outbound_message_events',3);
        $this->receive('email.delivered')->assertConflict();$this->assertDatabaseCount('outbound_message_events',3);
    }
    public function test_verified_delivery_is_distinct_from_send_and_unknown_delay_never_suppresses(): void
    {
        $this->receive('email.delivery_delayed')->assertOk();$this->assertSame('sent',$this->mailState->fresh()->status);$this->assertDatabaseCount('outbound_message_events',0);
        $this->receive('email.delivered','msg_delivery')->assertOk();$this->assertSame('delivered',$this->mailState->fresh()->status);
        $this->assertNotNull($this->mailState->fresh()->delivered_at);
    }
    public function test_tampering_and_clock_are_rejected_before_json_and_privacy_survives_debug(): void
    {
        $this->receive(headers:['HTTP_SVIX_SIGNATURE'=>'v1,wrong'],raw:'PRIVATE_BROKEN_JSON')->assertUnauthorized()->assertExactJson(['error'=>'resend_webhook_rejected']);
        $this->receive(headers:['HTTP_SVIX_ID'=>'msg_another'])->assertUnauthorized();
        $this->receive(headers:['HTTP_SVIX_TIMESTAMP'=>(string)(now()->timestamp-301)])->assertUnauthorized();
        $this->receive(raw:'broken JSON')->assertUnprocessable();
        $this->receive(url:'http://localhost/webhooks/resend')->assertForbidden();
        config(['waoo.sandbox_resend_webhooks'=>false]);$this->receive()->assertNotFound();
        $this->assertDatabaseCount('outbound_message_events',0);
    }
    public function test_recipient_unknown_send_source_and_invalid_time_never_create_delivery_evidence(): void
    {
        $this->receive(over:['data'=>['to'=>['another@example.test']]])->assertUnprocessable();
        $this->receive(over:['data'=>['email_id'=>'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']])->assertStatus(503);
        $this->receive(over:['created_at'=>now()->addMinutes(6)->toIso8601String()])->assertUnprocessable();
        $this->receive(over:['created_at'=>'2026-10-27 16:30:00'])->assertUnprocessable();
        $this->mailState->update(['provider'=>'other']);$this->receive()->assertStatus(503);
        $this->assertDatabaseCount('outbound_message_events',0);
    }
    public function test_raw_whitespace_and_rotation_signatures_are_supported(): void
    {
        $body="{\n  \"type\": \"email.delivered\", \"created_at\": \"2026-10-27T16:59:00+00:00\", \"data\": {\"email_id\": \"{$this->resendId}\", \"to\": [\"{$this->owner->email}\"]}\n}";
        $timestamp=(string)now()->timestamp;$signature=base64_encode(hash_hmac('sha256','msg_rotation.'.$timestamp.'.'.$body,$this->signingBytes,true));
        $this->receive(id:'msg_rotation',raw:$body,headers:['HTTP_SVIX_SIGNATURE'=>'v2,unknown v1,invalid v1,'.$signature])->assertOk();
        $this->assertSame('delivered',$this->mailState->fresh()->status);
    }
    public function test_provider_fractional_timestamps_are_accepted_without_losing_replay_identity(): void
    {
        foreach(['123','123456','123456789'] as $index=>$fraction){
            $created='2026-10-27T16:59:00.'.$fraction.'Z';$id='msg_fraction_'.$index;
            $changed='2026-10-27T16:59:00.'.substr($fraction,0,-1).'0Z';
            $this->receive('email.delivered',$id,['created_at'=>$created])->assertOk();
            $this->receive('email.delivered',$id,['created_at'=>$created])->assertOk();
            $this->receive('email.delivered',$id,['created_at'=>$changed])->assertConflict();
        }
        $this->assertDatabaseCount('outbound_message_events',3);
    }
    public function test_oversized_payload_or_invalid_secret_is_private_and_has_no_mutations(): void
    {
        $this->receive(raw:str_repeat('x',65537))->assertStatus(413)->assertHeader('Cache-Control','no-store, private');
        config(['waoo.resend_webhook_secret'=>'whsec_invalid!']);$this->receive()->assertStatus(503);
        $this->assertDatabaseCount('outbound_message_events',0);Http::assertNothingSent();
    }
}
