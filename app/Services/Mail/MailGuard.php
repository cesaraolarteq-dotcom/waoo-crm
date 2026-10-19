<?php
namespace App\Services\Mail;
use App\Models\User;
use App\Services\Finance\Sandbox;
use App\Services\Portal\PortalContext;
use App\Services\Records\RecordActor;
final class MailGuard
{
    public static function actor(User $actor):User{Sandbox::require('sandbox_mail');PortalContext::requireTransport(request());return RecordActor::authorize($actor);}
    public static function allowlist():array
    {
        $owners=User::where('role','owner_admin')->where('active',true)->whereNotNull('two_factor_secret')->whereNotNull('two_factor_confirmed_at')->pluck('email')->map(fn($e)=>mb_strtolower($e))->all();
        return array_values(array_unique(array_filter(array_map(fn($v)=>is_string($v)?mb_strtolower(trim($v)):'',(array)config('waoo.mail_allowlist',[])),fn($v)=>filter_var($v,FILTER_VALIDATE_EMAIL)&&in_array($v,$owners,true))));
    }
    public static function transaction(callable $work):mixed
    {
        try{return \Illuminate\Support\Facades\DB::transaction($work,3);}catch(\Illuminate\Database\QueryException){throw new \RuntimeException('Private mail persistence unavailable.');}
    }
    public static function event(int $messageId,string $key,string $kind,array $metadata=[],$at=null):void
    {
        \App\Models\OutboundMessageEvent::create(['outbound_message_id'=>$messageId,'event_key'=>hash('sha256',$key),'kind'=>$kind,'metadata'=>$metadata,'occurred_at'=>$at??now()]);
    }
}
