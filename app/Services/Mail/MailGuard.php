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
        $owner=array_filter(array_map(fn($v)=>is_string($v)?mb_strtolower(trim($v)):'',(array)config('waoo.mail_allowlist',[])),fn($v)=>filter_var($v,FILTER_VALIDATE_EMAIL)&&in_array($v,$owners,true));
        $tests=array_filter(array_map(fn($v)=>is_string($v)?mb_strtolower(trim($v)):'',(array)config('waoo.mail_test_recipients',[])),fn($v)=>filter_var($v,FILTER_VALIDATE_EMAIL)!==false);
        return array_values(array_unique([...$owner,...$tests]));
    }
    /** Client campaigns that may reach real clients once their own live switch is on (owner 2026-11-25: anniversary only). */
    public const CLIENT_CAMPAIGNS=['anniversary_main','anniversary_reminder'];

    /** Owners/test recipients always; a real client only for an approved client campaign on the real CRM with its switch on. */
    public static function allows(string $recipient,?string $template=null):bool
    {
        $r=mb_strtolower(trim($recipient));
        if(in_array($r,self::allowlist(),true))return true;
        if($template===null||!\App\Support\WaooRuntime::live()||filter_var($r,FILTER_VALIDATE_EMAIL)===false)return false;
        if(in_array($template,self::CLIENT_CAMPAIGNS,true))return config('waoo.anniversary_clients_enabled')===true;
        // Owner 2026-11-26: the six automatic messages reach clients once this switch is on (each text still needs his
        // approval and its own switch on «Mensajes automáticos»; parties HoneyBook manages never get them).
        return array_key_exists($template,\App\Support\AutoMessages::FLOW)&&config('waoo.client_messages_enabled')===true;
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
