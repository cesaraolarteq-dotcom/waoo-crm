<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** A dedicated read-only server capability, never a browser/session or lead-intake credential. */
final class AuthenticateWebsiteAvailability
{
    public static function error(string $code, int $status, array $headers=[])
    {
        return response()->json(['error'=>$code],$status,[
            'Cache-Control'=>'private, no-store','Pragma'=>'no-cache','Referrer-Policy'=>'no-referrer',
            'X-Content-Type-Options'=>'nosniff',...$headers,
        ]);
    }
    public function handle(Request $request, Closure $next)
    {
        $key='website-availability:'.hash('sha256',(string)$request->ip());
        if(RateLimiter::tooManyAttempts($key,60))return self::error('rate_limited',429,['Retry-After'=>(string)RateLimiter::availableIn($key)]);
        RateLimiter::hit($key,60);
        $secret=config('waoo.availability_shared_secret');
        if(!app()->environment(['local','testing','staging']) || config('waoo.sandbox_web_availability')!==true
            || !is_string($secret) || strlen($secret)<32 || strlen($secret)>512)return self::error('availability_unavailable',503);
        if(app()->environment('staging')&&!$request->isSecure())return self::error('availability_unavailable',403);
        $method=(string)$request->server('REQUEST_METHOD');
        if(!in_array($method,['GET','POST'],true)||$method!==$request->getMethod()||$request->headers->has('X-HTTP-Method-Override'))return self::error('method_not_allowed',405);
        $body=$request->getContent();$target=$request->getRequestUri();
        if(strlen($body)>16384||strlen($target)>2048)return self::error('payload_too_large',413);
        $timestamp=(string)$request->header('X-Waoo-Timestamp');$signature=(string)$request->header('X-Waoo-Signature');
        if(!preg_match('/^[1-9][0-9]{9}$/D',$timestamp)||abs(now()->timestamp-(int)$timestamp)>300
            ||!preg_match('/^sha256=[0-9a-f]{64}$/D',$signature)
            ||!hash_equals('sha256='.hash_hmac('sha256',$timestamp."\n".$method."\n".$target."\n".$body,$secret),$signature))return self::error('authentication_failed',401);
        // A service outage must not leak an exception/SQL trace when app.debug is enabled on crm-test.
        try{return $next($request);}catch(\Throwable){return self::error('availability_unavailable',503);}
    }
}
