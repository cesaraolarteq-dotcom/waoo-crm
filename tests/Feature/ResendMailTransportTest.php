<?php
namespace Tests\Feature;

use App\Services\Mail\{MailTransport,OutboundEnvelope,ResendMailTransport};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ResendScenario;
use Tests\TestCase;

class ResendMailTransportTest extends TestCase
{
    use RefreshDatabase,ResendScenario;
    protected function setUp(): void{parent::setUp();$this->resendScenario('dispatching');}
    private function envelope(): OutboundEnvelope
    {
        $p=$this->mail->payload;return new OutboundEnvelope($this->mail->id,$p['recipient'],$p['subject'],$p['html'],$p['text']);
    }
    public function test_mocked_resend_preserves_exact_claimed_content_and_namespaced_idempotency(): void
    {
        config(['waoo.mail_delivery_enabled'=>true]);
        Http::fake(['https://api.resend.com/emails'=>Http::response(['id'=>$this->resendId],200)]);
        $this->assertInstanceOf(ResendMailTransport::class,app(MailTransport::class));
        $receipt=app(ResendMailTransport::class)->send($this->envelope());
        $this->assertSame('resend',$receipt->provider);$this->assertSame($this->resendId,$receipt->messageId);
        Http::assertSent(fn($request)=>$request->method()==='POST'&&$request->url()==='https://api.resend.com/emails'
            &&$request->hasHeader('Idempotency-Key','waoo-outbox:'.$this->mail->id)
            &&$request->data()===['from'=>'Waoo Party <sender@example.test>','reply_to'=>'reply@example.test','to'=>[$this->owner->email],
                'subject'=>$this->mail->payload['subject'],'html'=>$this->mail->payload['html'],'text'=>$this->mail->payload['text']]);
        Http::assertSentCount(1);$this->assertSame('dispatching',$this->mailState->fresh()->status); // Receipt persistence belongs to the outbox.
    }
    public static function badResponses(): array{return [[429,[]],[500,[]],[302,[]],[200,['id'=>'not-an-id']],[200,['data'=>['id'=>'11111111-2222-4333-8444-555555555555']]]];}
    #[\PHPUnit\Framework\Attributes\DataProvider('badResponses')]
    public function test_errors_redirects_and_untrusted_receipts_are_never_retried_or_echoed(int $status,array $payload): void
    {
        config(['waoo.mail_delivery_enabled'=>true]);Http::fake(['*'=>Http::response([...$payload,'message'=>'PRIVATE_PROVIDER_ERROR'],$status,['Location'=>'https://untrusted.example.test'])]);
        try{app(ResendMailTransport::class)->send($this->envelope());$this->fail('Expected generic unknown outcome');}
        catch(\RuntimeException $e){$this->assertSame('Resend transport outcome unknown.',$e->getMessage());$this->assertNull($e->getPrevious());}
        Http::assertSentCount(1);
    }
    public function test_hold_and_source_allowlist_or_claim_changes_fail_before_any_http(): void
    {
        $transport=app(ResendMailTransport::class);
        try{$transport->send($this->envelope());$this->fail('Hold must block');}catch(\RuntimeException){}
        config(['waoo.mail_delivery_enabled'=>true,'waoo.mail_allowlist'=>[]]);
        try{$transport->send($this->envelope());$this->fail('Allowlist must block');}catch(\RuntimeException){}
        config(['waoo.mail_allowlist'=>[$this->owner->email]]);$this->mailState->update(['status'=>'scheduled']);
        try{$transport->send($this->envelope());$this->fail('Unclaimed message must block');}catch(\RuntimeException){}
        Http::assertNothingSent();
    }
}
