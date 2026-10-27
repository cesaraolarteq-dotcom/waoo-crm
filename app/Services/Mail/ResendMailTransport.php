<?php
namespace App\Services\Mail;

use App\Models\{OutboundMessage,OutboundMessageState};
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class ResendMailTransport implements MailTransport
{
    public function configured(): bool
    {
        $key=config('services.resend.key');$name=config('waoo.mail_from_name');
        return MailDeliveryGate::enabled() && app()->environment(['local','testing','staging'])
            && config('waoo.sandbox_mail')===true && config('waoo.mail_provider')==='resend'
            && is_string($key) && strlen($key)>=16 && strlen($key)<=512 && !preg_match('/[\x00-\x20\x7f]/',$key)
            && is_string($name) && strlen($name)>0 && strlen($name)<=120 && !preg_match('/[\x00-\x1f\x7f<>]/',$name)
            && is_string(config('waoo.mail_from_address')) && filter_var(config('waoo.mail_from_address'),FILTER_VALIDATE_EMAIL)!==false
            && is_string(config('waoo.mail_reply_to')) && filter_var(config('waoo.mail_reply_to'),FILTER_VALIDATE_EMAIL)!==false;
    }
    public function send(#[\SensitiveParameter] OutboundEnvelope $message): TransportReceipt
    {
        if(!$this->configured())throw new \RuntimeException('Mail delivery is held or unconfigured.');
        try {
            // A transport is not an arbitrary send API. Require the already-claimed immutable outbox envelope.
            $record=OutboundMessage::findOrFail($message->id);$payload=$record->payload;
            $state=OutboundMessageState::where('outbound_message_id',$message->id)->firstOrFail();
            if($state->status!=='dispatching'||$record->environment!=='sandbox'||$record->channel!=='email'
                ||!hash_equals($record->recipient_sha256,hash('sha256',$message->recipient))
                ||!in_array($message->recipient,MailGuard::allowlist(),true)
                ||($payload['recipient']??null)!==$message->recipient||($payload['subject']??null)!==$message->subject
                ||($payload['html']??null)!==$message->html||($payload['text']??null)!==$message->text
                ||OutboundMessageState::whereIn('outbound_message_id',OutboundMessage::where('recipient_sha256',$record->recipient_sha256)->select('id'))->whereIn('status',['bounced','complained'])->exists()){
                throw new \RuntimeException();
            }
            if(!$this->configured())throw new \RuntimeException();
            // No redirects or automatic retries: the outbox preserves an uncertain provider outcome for reconciliation.
            $response=Http::withToken(config('services.resend.key'))->acceptJson()->asJson()
                ->withHeaders(['Idempotency-Key'=>'waoo-outbox:'.$message->id])->connectTimeout(2)->timeout(5)
                ->withOptions(['allow_redirects'=>false])->post('https://api.resend.com/emails',[
                    'from'=>config('waoo.mail_from_name').' <'.config('waoo.mail_from_address').'>',
                    'reply_to'=>config('waoo.mail_reply_to'),'to'=>[$message->recipient],
                    'subject'=>$message->subject,'html'=>$message->html,'text'=>$message->text,
                ]);
            $id=$response->json('id');
            if(!$response->successful()||!is_string($id)||!Str::isUuid($id))throw new \RuntimeException();
            return new TransportReceipt('resend',$id);
        }catch(\Throwable){throw new \RuntimeException('Resend transport outcome unknown.');}
    }
}
