<?php
namespace Tests\Feature;

use App\Services\Finance\StripeTestRefunds;
use PHPUnit\Framework\Attributes\{RunTestsInSeparateProcesses,PreserveGlobalState};

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class StripeRefundAdapterTest extends \Tests\TestCase
{
    private array $calls=[];
    private function provider(bool $paymentLive=true): void
    {
        $this->app['env']='production';
        config(['waoo.live'=>true,'waoo.stripe_live'=>true,'waoo.sandbox_finance'=>true,
            'waoo.stripe_live_secret'=>'sk_live_fixture','waoo.stripe_live_account'=>'acct_fixture']);
        $http=\Mockery::mock('overload:Stripe\\HttpClient\\CurlClient');
        $http->shouldReceive('setTimeout')->andReturnSelf();$http->shouldReceive('setConnectTimeout')->andReturnSelf();
        $http->shouldReceive('request')->andReturnUsing(function($method,$url,...$rest)use($paymentLive){
            $this->calls[]=$method.' '.parse_url($url,PHP_URL_PATH);
            $body=match(parse_url($url,PHP_URL_PATH)){
                '/v1/account'=>['id'=>'acct_fixture','object'=>'account'],
                '/v1/payment_intents/pi_fixture'=>['id'=>'pi_fixture','object'=>'payment_intent','livemode'=>$paymentLive,'status'=>'succeeded','currency'=>'usd','amount_received'=>100],
                '/v1/refunds'=>['id'=>'re_fixture','object'=>'refund','status'=>'succeeded','currency'=>'usd','amount'=>100,'payment_intent'=>'pi_fixture'],
                default=>throw new \RuntimeException('Unexpected provider request'),
            };
            return [json_encode($body),200,[]];
        });
    }
    public function test_refund_without_nonexistent_livemode_field_uses_verified_payment_mode(): void
    {
        $this->provider();$r=(new StripeTestRefunds())->refund('pi_fixture',100,'fixture');
        $this->assertSame('re_fixture',$r['id']);
        $this->assertSame(['get /v1/account','get /v1/payment_intents/pi_fixture','post /v1/refunds'],$this->calls);
    }
    public function test_wrong_mode_payment_is_rejected_before_creating_any_refund(): void
    {
        $this->provider(false);
        try{(new StripeTestRefunds())->refund('pi_fixture',100,'fixture');$this->fail('Refund created for a test payment in live mode.');}
        catch(\RuntimeException $e){$this->assertSame('stripe_payment_binding_mismatch',$e->getMessage());}
        $this->assertSame(['get /v1/account','get /v1/payment_intents/pi_fixture'],$this->calls);
    }
}
