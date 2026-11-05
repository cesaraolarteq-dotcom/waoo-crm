<?php
namespace Tests\Feature;

use App\Services\Finance\{StripeMode, StripeTestGateway};
use Illuminate\Auth\Access\AuthorizationException;
use Tests\TestCase;

// Owner 2026-11-04 (real CRM, step 2): live Stripe only on the real CRM with STRIPE_LIVE=true and live keys.
class StripeModeTest extends TestCase
{
    private function realCrm(bool $stripeLive): void
    {
        $this->app['env'] = 'production';
        config(['waoo.live' => true, 'waoo.sandbox_finance' => true, 'waoo.stripe_live' => $stripeLive,
            'waoo.stripe_test_secret' => 'sk_test_fixture', 'waoo.stripe_test_account' => 'acct_test_fixture',
            'waoo.stripe_live_secret' => 'sk_live_fixture', 'waoo.stripe_live_account' => 'acct_live_fixture',
            'waoo.stripe_live_webhook_secret' => 'whsec_live_fixture']);
    }

    private function refused(): string
    {
        try { new StripeTestGateway(); } catch (AuthorizationException $e) { return $e->getMessage(); }
        return '';
    }

    public function test_test_environments_only_accept_test_objects(): void
    {
        config(['waoo.stripe_live' => true, 'waoo.stripe_test_account' => 'acct_test_fixture', 'waoo.stripe_live_account' => 'acct_live_fixture']);
        $this->assertFalse(StripeMode::live());
        $this->assertFalse(StripeMode::livemode());
        $this->assertSame('acct_test_fixture', StripeMode::account());
        $this->assertFalse(StripeMode::keyAllowed('sk_live_x'));
        $this->assertSame('cs_test_', StripeMode::sessionPrefix());
    }

    public function test_real_crm_without_the_stripe_switch_charges_nothing(): void
    {
        $this->realCrm(false);
        $this->assertFalse(StripeMode::live());
        $this->assertStringContainsString('todavía no está conectado', $this->refused());
    }

    public function test_real_crm_with_the_switch_uses_only_live_keys_and_the_live_account(): void
    {
        $this->realCrm(true);
        $this->assertTrue(StripeMode::livemode());
        $this->assertSame('acct_live_fixture', StripeMode::account());
        $this->assertSame('', $this->refused());
        config(['waoo.stripe_live_secret' => 'sk_test_wrong']);
        $this->assertStringContainsString('clave Stripe real', $this->refused());
    }

    public function test_live_payment_identity_is_separate_and_test_identity_is_preserved(): void
    {
        $this->assertSame('stripe:test:acct_fixture:pi_fixture',StripeMode::paymentIdentity('acct_fixture','pi_fixture'));
        $this->realCrm(true);
        $this->assertSame('stripe:live:acct_fixture:pi_fixture',StripeMode::paymentIdentity('acct_fixture','pi_fixture'));
    }

    public function test_disabled_live_stripe_cannot_fall_back_to_the_test_webhook(): void
    {
        $this->realCrm(false);
        config(['waoo.stripe_test_webhook_secret'=>'whsec_test_fixture']);
        $payload=json_encode(['id'=>'evt_disabled_fixture','type'=>'checkout.session.completed','livemode'=>false,
            'data'=>['object'=>['id'=>'cs_test_fixture','object'=>'checkout.session']]]);
        $t=time();$sig=hash_hmac('sha256',$t.'.'.$payload,'whsec_test_fixture');
        $this->call('POST','/webhooks/stripe',[],[],[],['HTTP_STRIPE_SIGNATURE'=>"t=$t,v1=$sig",'CONTENT_TYPE'=>'application/json','HTTPS'=>'on'],$payload)->assertForbidden();
    }

    public function test_real_crm_webhook_refuses_a_test_mode_event(): void
    {
        $this->realCrm(true);
        $payload = json_encode(['id' => 'evt_fixture', 'type' => 'checkout.session.completed', 'livemode' => false, 'created' => time(), 'data' => ['object' => ['id' => 'cs_test_x', 'object' => 'checkout.session']]]);
        $t = time(); $sig = hash_hmac('sha256', $t.'.'.$payload, 'whsec_live_fixture');
        $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t=$t,v1=$sig", 'CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on'], $payload)->assertStatus(400);
    }
}
