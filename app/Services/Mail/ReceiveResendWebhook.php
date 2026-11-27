<?php
namespace App\Services\Mail;

use App\Models\{OutboundMessage,OutboundMessageState};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class ReceiveResendWebhook
{
    public function execute(Request $request): void
    {
        abort_unless(\App\Support\WaooRuntime::trusted()&&config('waoo.sandbox_resend_webhooks')===true,404);
        abort_unless($request->isSecure(),403);
        $body=$request->getContent();abort_if(strlen($body)>65536||(int)$request->header('Content-Length',0)>65536,413);
        app(VerifyResendSignature::class)->execute($body,(string)$request->header('svix-id'),(string)$request->header('svix-timestamp'),(string)$request->header('svix-signature'));
        abort_unless($request->isJson(),422);
        try{$payload=json_decode($body,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){abort(422);}
        $head=Validator::make(['payload'=>$payload],['payload'=>'required|array','payload.type'=>'required|string|max:80'])->validate()['payload'];
        $outcome=match($head['type']){'email.delivered'=>'delivered','email.bounced'=>'bounced','email.complained'=>'complained',default=>null};
        if(in_array($head['type'],['email.opened','email.clicked'],true)){$this->engagement($payload,$request);return;} // owner 2026-11-26
        if($outcome===null)return; // Authenticated unknown/delay events never suppress or mark delivery.
        $data=Validator::make(['payload'=>$payload],[
            'payload.created_at'=>['required','date','regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D','before_or_equal:'.CarbonImmutable::now()->addMinutes(5)->toIso8601String()],
            'payload.data'=>'required|array','payload.data.email_id'=>'required|uuid',
            'payload.data.to'=>'required|array|size:1','payload.data.to.0'=>'required|email|max:255',
        ])->validate()['payload'];
        MailGuard::transaction(function()use($data,$outcome,$request){
            $id=$data['data']['email_id'];
            $state=OutboundMessageState::where('provider','resend')->where('provider_message_sha256',hash('sha256',"resend\0".$id))->lockForUpdate()->first();
            abort_unless($state&&$state->provider_message_id===$id,503);
            $message=OutboundMessage::findOrFail($state->outbound_message_id);
            $recipient=mb_strtolower(trim($data['data']['to'][0]));$intended=$message->payload['recipient']??null;
            abort_unless($message->environment==='sandbox'&&$message->channel==='email'
                &&hash_equals($message->recipient_sha256,hash('sha256',$recipient))
                &&is_string($intended)&&hash_equals($intended,$recipient),422);
            app(RecordMailDelivery::class)->authenticatedResend($id,(string)$request->header('svix-id'),$outcome,CarbonImmutable::parse($data['created_at'])->utc(),$data['created_at']);
        });
    }

    /** Opens and clicks (owner 2026-11-26): recorded once per provider event, never change delivery or suppression. */
    private function engagement(array $payload,Request $request):void
    {
        $data=Validator::make(['payload'=>$payload],[
            'payload.created_at'=>['required','date','before_or_equal:'.CarbonImmutable::now()->addMinutes(5)->toIso8601String()],
            'payload.data'=>'required|array','payload.data.email_id'=>'required|uuid','payload.data.click'=>'sometimes|array','payload.data.click.link'=>'sometimes|nullable|string|max:2000',
        ])->validate()['payload'];
        $svix=(string)$request->header('svix-id');
        abort_unless($svix!==''&&strlen($svix)<=120,422);
        $id=$data['data']['email_id'];
        $state=OutboundMessageState::where('provider','resend')->where('provider_message_sha256',hash('sha256',"resend\0".$id))->first();
        if(!$state||$state->provider_message_id!==$id)return; // not one of ours: nothing to record
        $clicked=$payload['type']==='email.clicked';
        \App\Models\OutboundMessageEngagement::query()->insertOrIgnore([
            'outbound_message_id'=>$state->outbound_message_id,'kind'=>$clicked?'clicked':'opened',
            'link_kind'=>$clicked?\App\Models\OutboundMessageEngagement::linkKind($data['data']['click']['link']??null):null,
            'occurred_at'=>CarbonImmutable::parse($data['created_at'])->utc(),'provider_event_id'=>$svix,'created_at'=>now(),'updated_at'=>now()]);
    }
}
