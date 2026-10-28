<?php
namespace Tests\Support;

use App\Services\Finance\StripeGateway;

final class FakeStripeGateway implements StripeGateway
{
    public array $calls = []; public array $sessions = []; public array $keys = []; public array $expired = [];
    public bool $failAfterCreate = false; public bool $failRetrieve = false;
    public function createCheckout(array $parameters, string $key): array
    {
        $this->calls[] = ['parameters' => $parameters, 'key' => $key];
        if (!isset($this->keys[$key])) {
            $id = 'cs_test_fixture_'.(count($this->sessions) + 1); $this->keys[$key] = $id;
            $this->sessions[$id] = ['id' => $id, 'livemode' => false, '_verified_account' => 'acct_fixture', 'client_reference_id' => $parameters['client_reference_id'],
                'metadata' => $parameters['metadata'], 'amount_total' => array_sum(array_map(fn ($l) => (int) $l['price_data']['unit_amount'] * (int) ($l['quantity'] ?? 1), $parameters['line_items'])), 'currency' => 'usd',
                'status' => 'open', 'payment_status' => 'unpaid', 'payment_intent' => null, 'url' => 'https://checkout.stripe.com/c/pay/'.$id];
        }
        if ($this->failAfterCreate) { $this->failAfterCreate = false; throw new \RuntimeException('Fixture timeout after remote creation'); }
        return $this->sessions[$this->keys[$key]];
    }
    public function retrieveCheckout(string $id): array { if ($this->failRetrieve) throw new \RuntimeException('Fixture provider unavailable'); return $this->sessions[$id]; }
    public function expireCheckout(string $id): void { $this->expired[] = $id; if (isset($this->sessions[$id])) $this->sessions[$id]['status']='expired'; }
    public function paid(string $id): void
    {
        $session = &$this->sessions[$id]; $session['status'] = 'complete'; $session['payment_status'] = 'paid';
        $session['payment_intent'] = ['id' => 'pi_fixture_'.($session['metadata']['local_intent_id'] ?? str_replace('-', '', $session['metadata']['local_tip_intent_id'])), 'livemode' => false, 'status' => 'succeeded', 'currency' => 'usd',
            'amount_received' => $session['amount_total'], 'latest_charge' => ['paid' => true, 'captured' => true, 'created' => time() - 5]];
    }
}
