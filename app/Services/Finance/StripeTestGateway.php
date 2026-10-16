<?php
namespace App\Services\Finance;

use Illuminate\Auth\Access\AuthorizationException;
use Stripe\StripeClient;

final class StripeTestGateway implements StripeGateway
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
    public function expireCheckout(string $id): void
    {
        $this->account();
        $session = $this->client->checkout->sessions->retrieve($id);
        if ($session->livemode !== false) throw new \RuntimeException('stripe_mode_mismatch');
        if ($session->status === 'open') $this->client->checkout->sessions->expire($id);
    }
}
