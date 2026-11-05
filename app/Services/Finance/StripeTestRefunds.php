<?php
namespace App\Services\Finance;

use Illuminate\Auth\Access\AuthorizationException;
use Stripe\StripeClient;

final class StripeTestRefunds implements StripeRefunds
{
    public function refund(string $paymentIntentId, int $amountCents, string $key): array
    {
        Sandbox::require();
        $secret = \App\Services\Finance\StripeMode::secret();
        if (\App\Support\WaooRuntime::live() && !\App\Services\Finance\StripeMode::live()) throw new AuthorizationException('Stripe real todavía no está conectado.');
        if (!\App\Services\Finance\StripeMode::keyAllowed($secret)) throw new AuthorizationException('La clave Stripe no corresponde a este entorno.');
        $http = new \Stripe\HttpClient\CurlClient(); $http->setTimeout(12); $http->setConnectTimeout(3); \Stripe\ApiRequestor::setHttpClient($http);
        $client = new StripeClient(['api_key' => $secret, 'max_network_retries' => 1]);
        $expected = (string) \App\Services\Finance\StripeMode::account();
        if (!str_starts_with($expected, 'acct_') || $client->accounts->retrieve()->id !== $expected) throw new AuthorizationException('La clave Stripe no pertenece a la cuenta configurada.');
        $refund = $client->refunds->create(['payment_intent' => $paymentIntentId, 'amount' => $amountCents, 'reason' => 'requested_by_customer'], ['idempotency_key' => 'waoo-refund-'.$key])->toArray();
        if (($refund['livemode'] ?? null) !== \App\Services\Finance\StripeMode::livemode()) throw new \RuntimeException('stripe_mode_mismatch');
        return ['id' => (string) $refund['id'], 'status' => (string) $refund['status'], 'amount' => (int) $refund['amount'], 'payment_intent' => (string) $refund['payment_intent']];
    }
}
