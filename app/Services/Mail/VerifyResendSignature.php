<?php
namespace App\Services\Mail;

/** Native Svix wire format; verify exact bytes before JSON or normalization. */
final class VerifyResendSignature
{
    public function execute(#[\SensitiveParameter] string $body,string $id,string $timestamp,#[\SensitiveParameter] string $signatures): void
    {
        $secret=config('waoo.resend_webhook_secret');
        $decoded=is_string($secret)&&str_starts_with($secret,'whsec_')&&strlen($secret)<=256?base64_decode(substr($secret,6),true):false;
        abort_unless(is_string($decoded)&&strlen($decoded)>=16&&strlen($decoded)<=128,503);
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,255}$/D',$id)
            &&preg_match('/^[1-9][0-9]{9}$/D',$timestamp)&&abs(now()->timestamp-(int)$timestamp)<=300
            &&strlen($signatures)<=2048,401);
        $expected=base64_encode(hash_hmac('sha256',$id.'.'.$timestamp.'.'.$body,$decoded,true));
        $matched=false;
        foreach(explode(' ',$signatures) as $signature){
            $parts=explode(',',$signature,2);
            if(count($parts)===2&&$parts[0]==='v1'&&hash_equals($expected,$parts[1]))$matched=true;
        }
        abort_unless($matched,401);
    }
}
