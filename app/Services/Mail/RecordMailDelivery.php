<?php
namespace App\Services\Mail;
use App\Exceptions\RecordWriteRejected;
use App\Models\{OutboundMessageEvent,OutboundMessageState};
use App\Services\Finance\{MutationKey,Sandbox};
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
final class RecordMailDelivery
{
    // Internal normalized seam only. No public webhook until a concrete authenticated adapter is reviewed.
    public function execute(string $provider,string $providerMessageId,string $providerEventId,string $outcome,CarbonImmutable $occurredAt):void
    {
        Sandbox::require('sandbox_mail');if(!in_array($outcome,['delivered','bounced','complained'],true)||!preg_match('/^[a-z][a-z0-9_-]{0,59}$/D',$provider)||$providerMessageId===''||strlen($providerMessageId)>255||$providerEventId===''||strlen($providerEventId)>255||$occurredAt->isFuture())throw ValidationException::withMessages(['mail'=>'invalid_provider_event']);
        $key=MutationKey::hash(['provider'=>$provider,'event_id'=>$providerEventId]);$intent=MutationKey::hash(['provider'=>$provider,'message_id'=>$providerMessageId,'outcome'=>$outcome,'at'=>$occurredAt->toIso8601String()]);
        MailGuard::transaction(function()use($provider,$providerMessageId,$outcome,$occurredAt,$key,$intent){
            $s=OutboundMessageState::where('provider_message_sha256',hash('sha256',$provider."\0".$providerMessageId))->lockForUpdate()->firstOrFail();
            if($old=OutboundMessageEvent::where('event_key',$key)->lockForUpdate()->first()){RecordWriteRejected::unless($old->outbound_message_id===$s->outbound_message_id&&($old->metadata['intent_sha256']??null)===$intent,409,'mail_provider_event_conflict');return;}
            $next=match($outcome){'complained'=>'complained','bounced'=>$s->status==='complained'?'complained':'bounced','delivered'=>in_array($s->status,['bounced','complained'],true)?$s->status:'delivered'};
            $s->fill(['status'=>$next,'version'=>$s->version+1,...($outcome==='delivered'&&$s->delivered_at===null?['delivered_at'=>$occurredAt]:[])])->save();
            OutboundMessageEvent::create(['outbound_message_id'=>$s->outbound_message_id,'event_key'=>$key,'kind'=>$outcome,'metadata'=>['intent_sha256'=>$intent],'occurred_at'=>$occurredAt]);
        });
    }
}
