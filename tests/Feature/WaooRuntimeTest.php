<?php
namespace Tests\Feature;

use App\Services\Finance\Sandbox;
use App\Support\WaooRuntime;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\TestCase;

// Owner 2026-11-04: the real CRM runs as production only with WAOO_LIVE=true; without it production stays locked.
class WaooRuntimeTest extends TestCase
{
    private function production(bool $live): void
    {
        $this->app['env'] = 'production';
        config(['waoo.live' => $live, 'waoo.sandbox_finance' => true]);
    }

    public function test_production_without_the_switch_keeps_every_feature_off(): void
    {
        $this->production(false);
        $this->assertFalse(WaooRuntime::trusted());
        $this->expectException(AuthorizationException::class);
        Sandbox::require();
    }

    public function test_live_production_runs_features_but_never_the_test_stripe_account(): void
    {
        $this->production(true);
        $this->assertTrue(WaooRuntime::trusted());
        $this->assertTrue(WaooRuntime::hosted());
        Sandbox::require();
        config(['waoo.stripe_test_secret' => 'sk_test_fixture']);
        try {
            new \App\Services\Finance\StripeTestGateway();
            $this->fail('The real CRM built the test Stripe gateway.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Stripe real todavía no está conectado', $e->getMessage());
        }
    }

    public function test_test_environments_are_unchanged(): void
    {
        $this->assertTrue(WaooRuntime::trusted());
        $this->assertFalse(WaooRuntime::live());
    }
}
