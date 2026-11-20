<?php
namespace App\Services\Finance;

use Illuminate\Auth\Access\AuthorizationException;
use Stripe\StripeClient;

/**
 * Card refunds through Stripe (test or live, by StripeMode).
 * Codex audit 2026-11-17 A1: a Stripe Refund object has no `livemode` field, so the mode is proven on the PaymentIntent
 * BEFORE the refund is created (same account, same mode, succeeded, USD, enough received), and the answer is checked by
 * identity (re_ id, same PaymentIntent, same amount, USD) instead of a field Stripe never sends.
 */
final class StripeTestRefunds implements StripeRefunds
{
    public function refund(string $paymentIntentId, int $amountCents, string $key): array
    {
        $client = $this->client();
        $payment = $client->paymentIntents->retrieve($paymentIntentId)->toArray();
        if (($payment['id'] ?? null) !== $paymentIntentId || ($payment['livemode'] ?? null) !== StripeMode::livemode() || ($payment['status'] ?? null) !== 'succeeded'
            || ($payment['currency'] ?? null) !== 'usd' || (int) ($payment['amount_received'] ?? 0) < $amountCents) {
            throw new \RuntimeException(self::BINDING_MISMATCH);
        }
        // Stripe forgets idempotency keys after 24 h: a request replayed later (state unknown, no re_ id stored) must find the
        // refund it may already have created, by the key written in its metadata, instead of creating a second one.
        foreach ($client->refunds->all(['payment_intent' => $paymentIntentId, 'limit' => 100])->toArray()['data'] ?? [] as $existing) {
            if (($existing['metadata']['waoo_refund_key'] ?? null) === $key) return $this->checked($existing, $paymentIntentId, $amountCents);
        }
        $refund = $client->refunds->create(['payment_intent' => $paymentIntentId, 'amount' => $amountCents, 'reason' => 'requested_by_customer',
            'metadata' => ['waoo_refund_key' => $key]], ['idempotency_key' => 'waoo-refund-'.$key])->toArray();
        return $this->checked($refund, $paymentIntentId, $amountCents);
    }

    public function retrieve(string $refundId, string $paymentIntentId, int $amountCents): array
    {
        if (! preg_match('/^re_[A-Za-z0-9_]{1,200}$/D', $refundId)) throw new \RuntimeException('stripe_refund_identity_mismatch');
        return $this->checked($this->client()->refunds->retrieve($refundId)->toArray(), $paymentIntentId, $amountCents, $refundId);
    }

    public const BINDING_MISMATCH = 'stripe_payment_binding_mismatch';

    private function checked(array $refund, string $paymentIntentId, int $amountCents, ?string $refundId = null): array
    {
        $id = (string) ($refund['id'] ?? '');
        if (! preg_match('/^re_[A-Za-z0-9_]{1,200}$/D', $id) || ($refundId !== null && $id !== $refundId) || ($refund['payment_intent'] ?? null) !== $paymentIntentId
            || (int) ($refund['amount'] ?? -1) !== $amountCents || ($refund['currency'] ?? null) !== 'usd' || ! is_string($refund['status'] ?? null)) {
            throw new \RuntimeException('stripe_refund_identity_mismatch');
        }
        return ['id' => $id, 'status' => $refund['status'], 'amount' => (int) $refund['amount'], 'payment_intent' => $paymentIntentId];
    }

    private function client(): StripeClient
    {
        Sandbox::require();
        $secret = StripeMode::secret();
        if (\App\Support\WaooRuntime::live() && ! StripeMode::live()) throw new AuthorizationException('Stripe real todavía no está conectado.');
        if (! StripeMode::keyAllowed($secret)) throw new AuthorizationException('La clave Stripe no corresponde a este entorno.');
        $http = new \Stripe\HttpClient\CurlClient(); $http->setTimeout(12); $http->setConnectTimeout(3); \Stripe\ApiRequestor::setHttpClient($http);
        $client = new StripeClient(['api_key' => $secret, 'max_network_retries' => 1]);
        $expected = (string) StripeMode::account();
        if (! str_starts_with($expected, 'acct_') || $client->accounts->retrieve()->id !== $expected) throw new AuthorizationException('La clave Stripe no pertenece a la cuenta configurada.');
        return $client;
    }
}
