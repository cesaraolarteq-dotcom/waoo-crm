<?php
namespace Tests\Support;

use App\Models\{Customer,OutboundMessage,OutboundMessageState};
use App\Services\Finance\CreateFinancialRevision;
use Illuminate\Support\Facades\{Http,Mail};
use Illuminate\Support\Str;

trait ResendScenario
{
    use QuoteScenario;
    private OutboundMessage $mail;
    private OutboundMessageState $mailState;
    private string $resendId='11111111-2222-4333-8444-555555555555';
    private string $signingBytes='synthetic-webhook-secret-00000000';
    private function resendScenario(string $status='sent'): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-27T17:00:00Z'));
        $this->scenario();Http::preventStrayRequests();Mail::fake();
        config(['waoo.mail_delivery_enabled'=>false,'waoo.sandbox_mail'=>true,'waoo.mail_provider'=>'resend',
            'services.resend.key'=>str_repeat('synthetic-key-',4),'waoo.mail_from_address'=>'sender@example.test',
            'waoo.mail_from_name'=>'Waoo Party','waoo.mail_reply_to'=>'reply@example.test',
            'waoo.sandbox_resend_webhooks'=>true,'waoo.resend_webhook_secret'=>'whsec_'.base64_encode($this->signingBytes),
            'waoo.mail_allowlist'=>[$this->owner->email],'app.debug'=>true]);
        Customer::whereKey($this->event->customer_id)->update(['email'=>$this->owner->email]);
        $finance=app(CreateFinancialRevision::class)->execute($this->owner,$this->event->id,['expected_version'=>1],(string)Str::uuid());
        $this->mail=OutboundMessage::create(['event_id'=>$this->event->id,'customer_id'=>$this->event->customer_id,
            'financial_revision_id'=>$finance->id,'created_by'=>$this->owner->id,'event_version'=>1,'customer_version'=>1,
            'template_key'=>'payment_received','template_version'=>'synthetic','locale'=>'es','channel'=>'email','environment'=>'sandbox',
            'template_sha256'=>str_repeat('a',64),'recipient_sha256'=>hash('sha256',$this->owner->email),
            'intent_sha256'=>str_repeat('b',64),'source_sha256'=>str_repeat('c',64),'due_at'=>now(),
            'payload'=>['recipient'=>$this->owner->email,'subject'=>'Correo sintético de María','text'=>'PRIVATE_BODY exact text','html'=>'<p>PRIVATE_BODY exact HTML</p>']]);
        $this->mailState=OutboundMessageState::create(['outbound_message_id'=>$this->mail->id,'status'=>$status,
            ...($status==='sent'?['provider'=>'resend','provider_message_id'=>$this->resendId,'provider_message_sha256'=>hash('sha256',"resend\0".$this->resendId)]:[])]);
    }
}
