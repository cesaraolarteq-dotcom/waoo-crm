<?php
namespace App\Services\Finance;

use Illuminate\Auth\Access\AuthorizationException;
use Stripe\StripeClient;

final class StripeTestRefunds implements StripeRefunds
{
    public function refund(string $paymentIntentId, int $amountCents, string $key): array
    {
        Sandbox::require();
        $secret = (string) config('waoo.stripe_test_secret');
        if (!str_starts_with($secret, 'sk_test_') && !str_starts_with($secret, 'rk_test_')) throw new AuthorizationException('Configura una clave Stripe de pruebas; las claves live están prohibidas.');
        $http = new \Stripe\HttpClient\CurlClient(); $http->setTimeout(12); $http->setConnectTimeout(3); \Stripe\ApiRequestor::setHttpClient($http);
        $client = new StripeClient(['api_key' => $secret, 'max_network_retries' => 1]);
        $expected = (string) config('waoo.stripe_test_account');
        if (!str_starts_with($expected, 'acct_') || $client->accounts->retrieve()->id !== $expected) throw new AuthorizationException('La clave Stripe no pertenece a la cuenta configurada.');
        $refund = $client->refunds->create(['payment_intent' => $paymentIntentId, 'amount' => $amountCents, 'reason' => 'requested_by_customer'], ['idempotency_key' => 'waoo-refund-'.$key])->toArray();
        if (($refund['livemode'] ?? null) !== false) throw new \RuntimeException('stripe_mode_mismatch');
        return ['id' => (string) $refund['id'], 'status' => (string) $refund['status'], 'amount' => (int) $refund['amount'], 'payment_intent' => (string) $refund['payment_intent']];
    }
}
