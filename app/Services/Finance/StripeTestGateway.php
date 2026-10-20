<?php
namespace App\Services\Finance;

use Illuminate\Auth\Access\AuthorizationException;
use Stripe\StripeClient;

final class StripeTestGateway implements StripeGateway, StripeRefundGateway
{
    private StripeClient $client;
    private ?string $verifiedAccount = null;
    public function __construct()
    {
        Sandbox::require();
        $key = (string) config('waoo.stripe_test_secret');
        if (!str_starts_with($key, 'sk_test_') && !str_starts_with($key, 'rk_test_')) throw new AuthorizationException('Configura una clave Stripe de pruebas; las claves live están prohibidas.');
        $http = new \Stripe\HttpClient\CurlClient(); $http->setTimeout(12); $http->setConnectTimeout(3); \Stripe\ApiRequestor::setHttpClient($http);
        $this->client = new StripeClient(['api_key' => $key, 'max_network_retries' => 1]);
    }
    private function account(): string
    {
        if ($this->verifiedAccount) return $this->verifiedAccount;
        $expected = (string) config('waoo.stripe_test_account');
        if (!str_starts_with($expected, 'acct_')) throw new AuthorizationException('Configura la cuenta Stripe de pruebas esperada.');
        $actual = $this->client->accounts->retrieve();
        if ($actual->id !== $expected) throw new AuthorizationException('La clave Stripe no pertenece a la cuenta configurada.');
        return $this->verifiedAccount = $actual->id;
    }
    public function createCheckout(array $parameters, string $key): array
    {
        $account = $this->account();
        $session = $this->client->checkout->sessions->create($parameters, ['idempotency_key' => $key])->toArray();
        if (($session['livemode'] ?? null) !== false) throw new \RuntimeException('stripe_mode_mismatch');
        return [...$session, '_verified_account' => $account];
    }
    public function retrieveCheckout(string $id): array
    {
        $account = $this->account();
        $session = $this->client->checkout->sessions->retrieve($id, ['expand' => ['payment_intent.latest_charge']])->toArray();
        if (($session['livemode'] ?? null) !== false) throw new \RuntimeException('stripe_mode_mismatch');
        return [...$session, '_verified_account' => $account];
    }
    public function retrieveRefund(string $refundId): array
    {
        if (!preg_match('/^re_[A-Za-z0-9_]{1,200}$/D', $refundId)) throw new \InvalidArgumentException('Invalid refund identity.');
        $account = $this->account();
        $r = $this->client->refunds->retrieve($refundId, ['expand' => ['charge.payment_intent']])->toArray();
        $c = is_array($r['charge'] ?? null) ? $r['charge'] : [];
        $p = is_array($c['payment_intent'] ?? null) ? $c['payment_intent'] : [];
        return ['account' => $account,
            'refund' => ['id' => $r['id'] ?? null, 'status' => $r['status'] ?? null, 'amount' => $r['amount'] ?? null,
                'currency' => $r['currency'] ?? null, 'created' => $r['created'] ?? null, 'charge_id' => $c['id'] ?? null,
                'payment_id' => is_string($r['payment_intent'] ?? null) ? $r['payment_intent'] : ($r['payment_intent']['id'] ?? null)],
            'charge' => ['id' => $c['id'] ?? null, 'payment_id' => $p['id'] ?? null, 'amount' => $c['amount'] ?? null,
                'currency' => $c['currency'] ?? null, 'created' => $c['created'] ?? null, 'livemode' => $c['livemode'] ?? null,
                'paid' => $c['paid'] ?? null, 'captured' => $c['captured'] ?? null],
            'payment' => ['id' => $p['id'] ?? null, 'amount_received' => $p['amount_received'] ?? null,
                'currency' => $p['currency'] ?? null, 'livemode' => $p['livemode'] ?? null, 'status' => $p['status'] ?? null]];
    }
    public function retrieveCharge(string $chargeId): array
    {
        if (!preg_match('/^ch_[A-Za-z0-9_]{1,200}$/D', $chargeId)) throw new \InvalidArgumentException('Invalid charge identity.');
        $account = $this->account();
        $c = $this->client->charges->retrieve($chargeId, ['expand' => ['payment_intent']])->toArray();
        $p = is_array($c['payment_intent'] ?? null) ? $c['payment_intent'] : [];
        return ['account' => $account,
            'charge' => ['id' => $c['id'] ?? null, 'payment_id' => $p['id'] ?? null, 'amount' => $c['amount'] ?? null,
                'currency' => $c['currency'] ?? null, 'created' => $c['created'] ?? null, 'livemode' => $c['livemode'] ?? null,
                'paid' => $c['paid'] ?? null, 'captured' => $c['captured'] ?? null],
            'payment' => ['id' => $p['id'] ?? null, 'amount_received' => $p['amount_received'] ?? null,
                'currency' => $p['currency'] ?? null, 'livemode' => $p['livemode'] ?? null, 'status' => $p['status'] ?? null]];
    }
    public function expireCheckout(string $id): void
    {
        $this->account();
        $session = $this->client->checkout->sessions->retrieve($id);
        if ($session->livemode !== false) throw new \RuntimeException('stripe_mode_mismatch');
        if ($session->status === 'open') $this->client->checkout->sessions->expire($id);
    }
}
