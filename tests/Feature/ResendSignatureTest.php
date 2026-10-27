<?php
namespace Tests\Feature;

use App\Services\Mail\VerifyResendSignature;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ResendSignatureTest extends TestCase
{
    public function test_official_public_svix_signing_vector_matches_without_using_our_signer(): void
    {
        // Public upstream test fixture, never a Waoo or provider credential.
        $this->travelTo(\Carbon\CarbonImmutable::createFromTimestampUTC(1614265330));
        config(['waoo.resend_webhook_secret'=>'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw']);
        app(VerifyResendSignature::class)->execute('{"test": 2432232314}','msg_p5jXN8AQM9LWM0D4loKWxJek','1614265330','v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=');
        $this->addToAssertionCount(1);
    }
    public function test_valid_signatures_have_exact_clock_edges_and_tampered_bytes_fail(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-27T17:00:00Z'));
        $key='synthetic-signature-key-00000000';config(['waoo.resend_webhook_secret'=>'whsec_'.base64_encode($key)]);
        foreach([-300,300] as $offset){$ts=(string)(now()->timestamp+$offset);app(VerifyResendSignature::class)->execute('{}','msg_edge',$ts,'v1,'.base64_encode(hash_hmac('sha256','msg_edge.'.$ts.'.{}',$key,true)));$this->addToAssertionCount(1);}
        foreach([-301,301] as $offset){
            $ts=(string)(now()->timestamp+$offset);
            try{app(VerifyResendSignature::class)->execute('{}','msg_edge',$ts,'v1,'.base64_encode(hash_hmac('sha256','msg_edge.'.$ts.'.{}',$key,true)));$this->fail('Stale valid HMAC must fail');}
            catch(HttpExceptionInterface $e){$this->assertSame(401,$e->getStatusCode());}
        }
        $ts=(string)now()->timestamp;$sig='v1,'.base64_encode(hash_hmac('sha256','msg_edge.'.$ts.'.{}',$key,true));
        try{app(VerifyResendSignature::class)->execute('{ }','msg_edge',$ts,$sig);$this->fail('Whitespace tampering must fail');}
        catch(HttpExceptionInterface $e){$this->assertSame(401,$e->getStatusCode());}
    }
}
