<?php
namespace App\Services\Mail;
use App\Models\{EventMessagePolicy,OutboundMessage,OutboundMessageState,User};
use App\Services\Finance\Sandbox;
final class DispatchOutboundMail
{
    public function execute(int $messageId):void
    {
        Sandbox::require('sandbox_mail');
        $m=OutboundMessage::findOrFail($messageId);$transport=app(MailTransport::class);
        $claimed=MailGuard::transaction(function()use($m,$transport){
            // Mutable source reads happen in the same grant->event lock order as planning.
            \App\Models\PortalGrant::whereKey($m->portal_grant_id)->lockForUpdate()->firstOrFail();$event=\App\Models\Event::whereKey($m->event_id)->lockForUpdate()->firstOrFail();
            $state=OutboundMessageState::where('outbound_message_id',$m->id)->lockForUpdate()->firstOrFail();
            if($state->status!=='scheduled'||$m->due_at->isFuture())return false;
            if($m->environment!=='sandbox'||$m->channel!=='email'){$this->transition($m,$state,'blocked','unsupported_environment_or_channel');return false;}
            if($event->responsible_system!=='crm'){$this->transition($m,$state,'blocked','responsible_system_honeybook');return false;}
            $policy=EventMessagePolicy::where('event_id',$m->event_id)->lockForUpdate()->first();if($policy?->paused)return false;
            try{$actor=User::findOrFail($m->created_by);MailGuard::actor($actor);$source=EventMailSource::lock($m->event_id,$m->portal_grant_id);$rendered=EventMailSource::compose($m->template_key,$source);}
            catch(\Illuminate\Database\QueryException $error){throw $error;}
            catch(\Illuminate\Validation\ValidationException){$this->transition($m,$state,'blocked','source_configuration_required');return false;}
            catch(\Throwable){$this->transition($m,$state,'skipped','source_unavailable');return false;}
            if(!hash_equals($m->source_sha256,$rendered['source_sha256'])||!hash_equals($m->template_sha256,$rendered['template_sha256'])
                ||($m->template_key==='balance_reminder'&&$source[4]>=$source[2]->total_cents)||$m->due_at->setTimezone('America/Chicago')->format('Y-m-d')!==now()->setTimezone('America/Chicago')->format('Y-m-d')){$this->transition($m,$state,'skipped','source_changed_or_late');return false;}
            try{$intact=hash_equals(\App\Services\Finance\MutationKey::hash($m->payload),\App\Services\Finance\MutationKey::hash($rendered));}catch(\Throwable){$intact=false;}
            if(!$intact){$this->transition($m,$state,'blocked','payload_integrity_failed');return false;}
            $recipient=$rendered['recipient'];
            $suppressed=OutboundMessageState::whereIn('outbound_message_id',OutboundMessage::where('recipient_sha256',hash('sha256',$recipient))->select('id'))->whereIn('status',['bounced','complained'])->lockForUpdate()->first();
            if(!in_array($recipient,MailGuard::allowlist(),true)||$suppressed){$this->transition($m,$state,'blocked',$suppressed?'recipient_suppressed':'recipient_not_allowed');return false;}
            if(!$transport->configured()){$this->transition($m,$state,'blocked','transport_unconfigured');return false;}
            $this->transition($m,$state,'dispatching',null,true);return true;
        });
        if(!$claimed)return;
        // No automatic resend after crossing this boundary; a crash leaves dispatching for reconciliation.
        try{$p=$m->payload;$receipt=$transport->send(new OutboundEnvelope($m->id,$p['recipient'],$p['subject'],$p['html'],$p['text']));
            if(!preg_match('/^[a-z][a-z0-9_-]{0,59}$/D',$receipt->provider)||$receipt->messageId===''||strlen($receipt->messageId)>255)throw new \RuntimeException();
        }catch(\Throwable){MailGuard::transaction(function()use($m){$s=OutboundMessageState::where('outbound_message_id',$m->id)->lockForUpdate()->firstOrFail();if($s->status==='dispatching')$this->transition($m,$s,'delivery_unknown','transport_outcome_unknown');});return;}
        MailGuard::transaction(function()use($m,$receipt){$s=OutboundMessageState::where('outbound_message_id',$m->id)->lockForUpdate()->firstOrFail();if($s->status!=='dispatching')return;
            $s->fill(['provider'=>$receipt->provider,'provider_message_id'=>$receipt->messageId,'provider_message_sha256'=>hash('sha256',$receipt->provider."\0".$receipt->messageId),'sent_at'=>now()]);$this->transition($m,$s,'sent');
        });
    }
    private function transition(OutboundMessage $m,OutboundMessageState $s,string $status,?string $error=null,bool $attempt=false):void
    {
        $s->fill(['status'=>$status,'version'=>$s->version+1,'error_code'=>$error,'attempt_count'=>$s->attempt_count+($attempt?1:0)])->save();MailGuard::event($m->id,'state:'.$m->id.':'.$s->version,$status,['error_code'=>$error]);
    }
}
