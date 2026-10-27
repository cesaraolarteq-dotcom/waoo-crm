<?php
namespace Tests\Feature;

use App\Services\Mail\{DisabledMailTransport,MailTransport,OutboundEnvelope,ResendMailTransport};
use Illuminate\Support\Facades\{Http,Mail};
use Tests\TestCase;

class MailDeliveryHoldTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();Http::preventStrayRequests();
        config(['waoo.mail_delivery_enabled'=>false,'waoo.sandbox_mail'=>true,'waoo.mail_provider'=>'resend',
            'services.resend.key'=>str_repeat('synthetic-key-',4),'waoo.mail_from_address'=>'sender@example.test',
            'waoo.mail_from_name'=>'Waoo Party','waoo.mail_reply_to'=>'reply@example.test']);
    }
    public function test_master_hold_blocks_framework_mail_without_smtp_resend_or_message_logging(): void
    {
        $mailer=Mail::mailer('array');
        $mailer->raw('Synthetic private message',fn($m)=>$m->from('sender@example.test')->to('owner@example.test')->subject('Synthetic'));
        $this->assertCount(0,$mailer->getSymfonyTransport()->messages());Http::assertNothingSent();
    }
    public function test_provider_and_key_alone_do_not_bind_a_sending_transport(): void
    {
        $this->assertInstanceOf(DisabledMailTransport::class,app(MailTransport::class));
        $transport=app(ResendMailTransport::class);$this->assertFalse($transport->configured());
        try{$transport->send(new OutboundEnvelope(1,'owner@example.test','Synthetic','<p>Synthetic</p>','Synthetic'));$this->fail('Held send must fail before HTTP');}
        catch(\RuntimeException $e){$this->assertSame('Mail delivery is held or unconfigured.',$e->getMessage());}
        Http::assertNothingSent();
    }
    public function test_mock_only_master_lift_does_not_bypass_sandbox_or_missing_configuration(): void
    {
        config(['waoo.mail_delivery_enabled'=>true,'waoo.sandbox_mail'=>false]);
        $this->assertInstanceOf(DisabledMailTransport::class,app(MailTransport::class));
        config(['waoo.sandbox_mail'=>true]);$this->app['env']='production';
        $this->assertInstanceOf(DisabledMailTransport::class,app(MailTransport::class));Http::assertNothingSent();
    }
}
